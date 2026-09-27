<?php
declare(strict_types=1);
/**
 * Supported WordPress screen boundary for Admin Columns Governance v1.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\AdminColumns;

defined( 'ABSPATH' ) || exit;

final class SupportedScreen {
	public static function is_supported( ?\WP_Screen $screen = null ): bool {
		$screen ??= get_current_screen();
		if ( ! $screen instanceof \WP_Screen || is_network_admin() || is_user_admin() ) {
			return false;
		}

		global $pagenow;
		if ( 'edit.php' !== (string) $pagenow || 'edit' !== (string) $screen->base ) {
			return false;
		}

		$post_type = (string) $screen->post_type;
		return '' !== $post_type
			&& (string) $screen->id === 'edit-' . $post_type
			&& self::supports_screen_id( (string) $screen->id );
	}

	public static function supports_screen_id( string $screen_id ): bool {
		$post_type = self::post_type_from_screen_id( $screen_id );
		if ( null === $post_type || 'attachment' === $post_type ) {
			return false;
		}
		$object = get_post_type_object( $post_type );
		return $object instanceof \WP_Post_Type && true === $object->show_ui;
	}

	public static function post_type_from_screen_id( string $screen_id ): ?string {
		if ( ! str_starts_with( $screen_id, 'edit-' ) ) {
			return null;
		}
		$post_type = substr( $screen_id, 5 );
		if ( '' === $post_type || strlen( $post_type ) > 20 || sanitize_key( $post_type ) !== $post_type ) {
			return null;
		}
		return $post_type;
	}

	private function __construct() {}
}
