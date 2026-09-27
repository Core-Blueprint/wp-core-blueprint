<?php
declare(strict_types=1);

namespace CB\Core\AdminColumns\Admin;

use CB\Core\AdminColumns\PolicyRepository;
use CB\Core\AdminColumns\RegisteredMetaColumns;
use CB\Core\AdminColumns\SupportedScreen;
use CB\Core\AdminColumns\TaxonomyColumns;
use CB\Core\Ajax\Request;

defined( 'ABSPATH' ) || exit;

final class Ajax {
	public const ACTION = 'cb_core_admin_columns_save';
	public const NONCE_ACTION = 'cb_core_admin_columns';

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		Request::nonce( self::NONCE_ACTION );
		Request::cap( 'manage_options' );

		$screen_id = isset( $_POST['screen_id'] ) ? (string) wp_unslash( $_POST['screen_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $screen_id || sanitize_key( $screen_id ) !== $screen_id || ! SupportedScreen::supports_screen_id( $screen_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unsupported Admin Columns screen.', 'core-blueprint' ) ], 400 );
		}

		$operation = Request::sanitize_key( 'operation', [ 'save', 'reset' ] );
		$user = wp_get_current_user();
		$actor = 'admin:' . ( $user instanceof \WP_User ? (string) $user->user_login : 'unknown' );

		try {
			if ( 'reset' === $operation ) {
				$changed = PolicyRepository::reset_screen( $screen_id, $actor );
				wp_send_json_success( [ 'changed' => $changed ] );
			}

			$raw = isset( $_POST['screen_policy'] ) ? (string) wp_unslash( $_POST['screen_policy'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $raw || strlen( $raw ) > 65536 ) {
				throw new \InvalidArgumentException( __( 'The Admin Columns policy payload is invalid.', 'core-blueprint' ) );
			}
			$decoded = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new \InvalidArgumentException( __( 'The Admin Columns policy payload is invalid.', 'core-blueprint' ) );
			}
			$screen_policy = PolicyRepository::normalize_screen_policy( $decoded );
			self::assert_sources_allowed( $screen_id, $screen_policy );
			$changed = PolicyRepository::replace_screen( $screen_id, $screen_policy, $actor );
			wp_send_json_success( [ 'changed' => $changed ] );
		} catch ( \JsonException | \InvalidArgumentException $error ) {
			wp_send_json_error( [ 'message' => $error->getMessage() ], 400 );
		} catch ( \RuntimeException $error ) {
			wp_send_json_error( [ 'message' => $error->getMessage() ], 500 );
		}
	}

	/** @param array{taxonomies:list<string>,meta:list<string>} $incoming */
	private static function assert_sources_allowed( string $screen_id, array $incoming ): void {
		$post_type = SupportedScreen::post_type_from_screen_id( $screen_id );
		if ( null === $post_type ) {
			throw new \InvalidArgumentException( __( 'Unsupported Admin Columns screen.', 'core-blueprint' ) );
		}
		$current = PolicyRepository::screen( $screen_id ) ?? [ 'taxonomies' => [], 'meta' => [] ];

		$allowed_taxonomies = array_fill_keys(
			array_unique( array_merge( array_keys( TaxonomyColumns::catalog( $post_type ) ), (array) ( $current['taxonomies'] ?? [] ) ) ),
			true
		);
		foreach ( $incoming['taxonomies'] as $taxonomy ) {
			if ( ! isset( $allowed_taxonomies[ $taxonomy ] ) ) {
				throw new \InvalidArgumentException( __( 'The selected taxonomy column is not available for this post type.', 'core-blueprint' ) );
			}
		}

		$allowed_meta = array_fill_keys(
			array_unique( array_merge( array_keys( RegisteredMetaColumns::catalog( $post_type ) ), (array) ( $current['meta'] ?? [] ) ) ),
			true
		);
		foreach ( $incoming['meta'] as $meta_key ) {
			if ( ! isset( $allowed_meta[ $meta_key ] ) ) {
				throw new \InvalidArgumentException( __( 'The selected meta column is not a registered scalar field for this post type.', 'core-blueprint' ) );
			}
		}
	}

	private function __construct() {}
}
