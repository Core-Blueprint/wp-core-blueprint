<?php
declare(strict_types=1);
/**
 * First-run guidance for Core Blueprint Base.
 *
 * A genuine single-plugin activation may queue one redirect for the activating
 * administrator. Core Setup remains the canonical onboarding surface; this class
 * only bridges WordPress' activation screen to that persistent review flow.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;

use CoreBlueprint\Core\Setup\Admin\Page as SetupPage;

defined( 'ABSPATH' ) || exit;

final class Onboarding {

	public const REDIRECT_OPTION = 'cb_core_setup_first_run_redirect';
	private const REDIRECT_MAX_AGE = DAY_IN_SECONDS;

	public static function boot(): void {
		add_action( 'admin_init', [ self::class, 'maybe_redirect' ], 1 );
		add_filter( 'plugin_action_links_' . CB_CORE_BASENAME, [ self::class, 'plugin_action_links' ] );
	}

	/**
	 * Queue first-run guidance only for a genuine interactive, single-plugin
	 * activation. Bulk, CLI, AJAX and network activation must preserve the
	 * caller's normal WordPress flow.
	 */
	public static function queue_first_activation_redirect( bool $is_first_activation, bool $network_wide = false ): bool {
		if ( ! $is_first_activation || $network_wide || wp_doing_ajax() || self::is_cli() || is_network_admin() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) )
			: '';
		$plugin = isset( $_REQUEST['plugin'] ) && is_scalar( $_REQUEST['plugin'] )
			? sanitize_text_field( wp_unslash( (string) $_REQUEST['plugin'] ) )
			: '';

		if ( ! in_array( $action, [ 'activate', 'activate-plugin' ], true ) || CB_CORE_BASENAME !== $plugin ) {
			return false;
		}

		return update_option(
			self::REDIRECT_OPTION,
			[
				'user_id'    => $user_id,
				'created_at' => time(),
			],
			false
		);
	}

	/** Consume and execute the queued redirect before admin output starts. */
	public static function maybe_redirect(): void {
		$url = self::consume_redirect_url();
		if ( '' === $url ) {
			return;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Resolve and consume a pending first-run redirect.
	 *
	 * @internal Public for deterministic integration testing.
	 */
	public static function consume_redirect_url(): string {
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$pending = get_option( self::REDIRECT_OPTION, null );
		if ( ! is_array( $pending ) ) {
			return '';
		}

		$user_id    = max( 0, (int) ( $pending['user_id'] ?? 0 ) );
		$created_at = max( 0, (int) ( $pending['created_at'] ?? 0 ) );
		if ( $user_id < 1 || get_current_user_id() !== $user_id || $created_at < time() - self::REDIRECT_MAX_AGE ) {
			delete_option( self::REDIRECT_OPTION );
			return '';
		}

		delete_option( self::REDIRECT_OPTION );
		return admin_url( 'admin.php?page=' . SetupPage::SLUG . '&tab=overview' );
	}

	/** Add a durable recovery path from the native Plugins screen. */
	public static function plugin_action_links( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . SetupPage::SLUG . '&tab=overview' ) ),
				esc_html__( 'Core Setup', 'core-blueprint' )
			)
		);
		return $links;
	}

	private static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	private function __construct() {}
}
