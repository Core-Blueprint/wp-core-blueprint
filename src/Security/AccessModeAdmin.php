<?php
declare(strict_types=1);
/**
 * wp-admin and AJAX presentation/transport for Access Mode.
 *
 * Runtime HTTP enforcement remains in AccessMode; persistence and validation
 * remain in AccessModeState.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\Log\AuditLog;
use WP_Admin_Bar;
use WP_Query;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class AccessModeAdmin {

	public static function boot(): void {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			add_action( 'wp_ajax_cb_core_set_access_mode', [ __CLASS__, 'ajax_set_mode' ] );
			add_action( 'wp_ajax_cb_core_access_mode_search_pages', [ __CLASS__, 'ajax_search_pages' ] );
			return;
		}

		if ( ! AccessMode::is_public() ) {
			add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_notice' ], 100 );
		}
	}

	public static function mode_label( ?string $mode = null ): string {
		$mode = $mode ?? AccessMode::current();
		return match ( $mode ) {
			AccessMode::MODE_COMING_SOON => __( 'Coming Soon', 'core-blueprint' ),
			AccessMode::MODE_MAINTENANCE => __( 'Maintenance', 'core-blueprint' ),
			AccessMode::MODE_ADMIN_ONLY  => __( 'Admin-Only', 'core-blueprint' ),
			default                     => __( 'Public', 'core-blueprint' ),
		};
	}

	public static function status_label( ?string $mode = null ): string {
		$mode = $mode ?? AccessMode::current();
		return match ( $mode ) {
			AccessMode::MODE_COMING_SOON => __( 'Coming Soon - pre-launch page active', 'core-blueprint' ),
			AccessMode::MODE_MAINTENANCE => __( 'Maintenance - site temporarily unavailable', 'core-blueprint' ),
			AccessMode::MODE_ADMIN_ONLY  => __( 'Admin-Only Mode - site locked', 'core-blueprint' ),
			default                     => __( 'Public Mode - site live', 'core-blueprint' ),
		};
	}

	/** @return array<int,array{id:int,label:string,meta:string}> */
	public static function picker_selected_page( int $page_id ): array {
		$page = AccessModeState::valid_landing_page( $page_id );
		if ( ! $page ) {
			return [];
		}

		return [ [
			'id'    => (int) $page->ID,
			'label' => (string) get_the_title( $page ),
			'meta'  => __( 'Published page', 'core-blueprint' ),
		] ];
	}

	public static function admin_bar_notice( WP_Admin_Bar $bar ): void {
		$mode = AccessMode::current();
		if ( AccessMode::MODE_PUBLIC === $mode || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$title = match ( $mode ) {
			AccessMode::MODE_COMING_SOON => '🚀 ' . esc_html__( 'Coming Soon', 'core-blueprint' ),
			AccessMode::MODE_MAINTENANCE => '🛠 ' . esc_html__( 'Maintenance', 'core-blueprint' ),
			default                     => '🔒 ' . esc_html__( 'Admin-Only Mode', 'core-blueprint' ),
		};
		$hint = match ( $mode ) {
			AccessMode::MODE_COMING_SOON => __( 'Pre-launch page is active. Click to manage.', 'core-blueprint' ),
			AccessMode::MODE_MAINTENANCE => __( 'Maintenance response is active. Click to manage.', 'core-blueprint' ),
			default                     => __( 'Front-end is locked. Click to manage.', 'core-blueprint' ),
		};

		$bar->add_node( [
			'id'    => 'cb-core-access-mode-notice',
			'title' => $title,
			'href'  => admin_url( 'admin.php?page=' . Admin::SAFEGUARDS_SLUG . '&tab=access-mode' ),
			'meta'  => [
				'class' => 'cb-core-access-mode-bar-notice',
				'title' => $hint,
			],
		] );
	}

	public static function ajax_set_mode(): void {
		Request::nonce( 'cb_core_admin' );
		Request::cap( 'manage_options' );

		$requested = Request::sanitize_key( 'mode', AccessMode::modes() );
		$previous  = AccessMode::current();
		$before    = AccessMode::config();

		$config = [
			'schema_version'         => AccessMode::CONFIG_SCHEMA,
			'coming_soon_page_id'    => Request::int( 'coming_soon_page_id', (int) $before['coming_soon_page_id'] ),
			'coming_soon_indexable'  => isset( $_POST['coming_soon_indexable'] )
				? filter_var( wp_unslash( $_POST['coming_soon_indexable'] ), FILTER_VALIDATE_BOOLEAN )
				: (bool) $before['coming_soon_indexable'], // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			'maintenance_page_id'    => Request::int( 'maintenance_page_id', (int) $before['maintenance_page_id'] ),
			'maintenance_until_date' => AccessModeState::sanitize_date( Request::text( 'maintenance_until_date', (string) $before['maintenance_until_date'] ) ),
			'maintenance_until_time' => AccessModeState::sanitize_time( Request::text( 'maintenance_until_time', (string) $before['maintenance_until_time'] ) ),
		];
		$config['coming_soon_page_id'] = absint( $config['coming_soon_page_id'] );
		$config['maintenance_page_id'] = absint( $config['maintenance_page_id'] );

		$error = AccessModeState::validate_config_for_mode( $requested, $config );
		if ( '' !== $error ) {
			wp_send_json_error( [ 'message' => $error ], 400 );
		}

		if ( ! AccessModeState::persist_config( $config ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not save Access Mode settings.', 'core-blueprint' ) ], 500 );
		}
		if ( ! AccessModeState::persist_mode( $requested ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not activate the selected Access Mode.', 'core-blueprint' ) ], 500 );
		}

		$config_changed = $before !== $config;
		$mode_changed   = $previous !== $requested;

		if ( ( $mode_changed || $config_changed ) && class_exists( AuditLog::class ) ) {
			$user = wp_get_current_user();
			AuditLog::log(
				'access_mode.changed',
				'notice',
				[
					'from'                   => $previous,
					'to'                     => $requested,
					'configuration_changed'  => $config_changed,
					'coming_soon_page_id'    => (int) $config['coming_soon_page_id'],
					'coming_soon_indexable'  => (bool) $config['coming_soon_indexable'],
					'maintenance_page_id'    => (int) $config['maintenance_page_id'],
					'retry_after_configured' => '' !== $config['maintenance_until_date'] && '' !== $config['maintenance_until_time'],
					'actor'                  => 'admin:' . ( $user ? $user->user_login : 'unknown' ),
				]
			);
		}

		if ( $mode_changed ) {
			do_action( 'core_blueprint_access_mode_changed', $requested, $previous );
		}
		if ( $config_changed ) {
			do_action( 'core_blueprint_access_mode_settings_changed', $config, $before );
		}

		wp_send_json_success( [
			'mode'    => $requested,
			'message' => self::activation_message( $requested ),
			'status'  => self::status_label( $requested ),
		] );
	}

	public static function ajax_search_pages(): void {
		Request::nonce( 'cb_core_admin', '_ajax_nonce' );
		Request::cap( 'manage_options' );
		$search = Request::text( 'search' );
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( [ 'items' => [] ] );
		}

		$query = new WP_Query( [
			'post_type'           => 'page',
			'post_status'         => 'publish',
			'has_password'        => false,
			's'                   => $search,
			'posts_per_page'      => 20,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		] );

		$items = [];
		foreach ( $query->posts as $page ) {
			if ( ! $page instanceof WP_Post ) {
				continue;
			}
			$items[] = [
				'id'    => (int) $page->ID,
				'label' => (string) get_the_title( $page ),
				'meta'  => __( 'Published page', 'core-blueprint' ),
			];
		}

		wp_send_json_success( [ 'items' => $items ] );
	}

	private static function activation_message( string $mode ): string {
		return match ( $mode ) {
			AccessMode::MODE_COMING_SOON => __( 'Coming Soon activated. Public URLs now lead visitors to the selected pre-launch page.', 'core-blueprint' ),
			AccessMode::MODE_MAINTENANCE => __( 'Maintenance activated. Public requests now return 503 while rendering the selected maintenance page.', 'core-blueprint' ),
			AccessMode::MODE_ADMIN_ONLY  => __( 'Admin-Only Mode enabled. Public visitors now receive 403.', 'core-blueprint' ),
			default                     => __( 'Public Mode enabled. The site is live.', 'core-blueprint' ),
		};
	}
}
