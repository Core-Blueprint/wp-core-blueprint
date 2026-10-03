<?php
declare(strict_types=1);
/**
 * Internal request-context classification for Core Blueprint bootstrap wiring.
 *
 * This is deliberately small and internal. It distinguishes only the request
 * boundaries Base needs to avoid treating admin-ajax.php, admin-post.php and
 * WP-CLI as normal wp-admin screens.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

defined( 'ABSPATH' ) || exit;

final class RequestContext {

	public static function is_ajax(): bool {
		if ( function_exists( 'wp_doing_ajax' ) ) {
			return wp_doing_ajax();
		}

		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	public static function is_admin_post(): bool {
		if ( self::is_ajax() ) {
			return false;
		}

		return self::is_script( 'admin-post.php' );
	}

	public static function is_admin_screen(): bool {
		return is_admin() && ! self::is_ajax() && ! self::is_admin_post() && ! self::is_cli();
	}

	public static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	private static function is_script( string $basename ): bool {
		foreach ( [ 'SCRIPT_NAME', 'PHP_SELF', 'SCRIPT_FILENAME' ] as $key ) {
			$value = sanitize_text_field( wp_unslash( (string) ( $_SERVER[ $key ] ?? '' ) ) );
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$path = wp_parse_url( $value, PHP_URL_PATH );
			if ( is_string( $path ) && $basename === basename( $path ) ) {
				return true;
			}
		}

		return false;
	}
}
