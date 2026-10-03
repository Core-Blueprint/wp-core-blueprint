<?php
declare(strict_types=1);
/** Native WordPress Admin Toolbar presentation governance. */

namespace CoreBlueprint\Core\AdminNavigation;

use CoreBlueprint\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class ToolbarRuntime {

	/** Apply hide/rename policy through the public WP_Admin_Bar API only. */
	public static function apply( \WP_Admin_Bar $admin_bar ): void {
		if ( is_network_admin() || is_user_admin() ) {
			return;
		}

		// Discovery is intentionally wp-admin-only. Frontend requests may apply a
		// stored rule to an existing node ID but never become a discovery source.
		if ( RequestContext::is_admin_screen() ) {
			Discovery::capture_toolbar( $admin_bar );
		}

		$policy = Policy::get()['toolbar'];
		foreach ( $policy['hidden'] as $rule ) {
			if ( Audience::matches( $rule['audience'] ) ) {
				$admin_bar->remove_node( $rule['id'] );
			}
		}

		$renamed = [];
		foreach ( $policy['renamed'] as $rule ) {
			$id = $rule['id'];
			if ( isset( $renamed[ $id ] ) || ! Audience::matches( $rule['audience'] ) ) {
				continue;
			}

			$node = $admin_bar->get_node( $id );
			if ( ! is_object( $node ) || ! self::has_plain_text_title( $node ) ) {
				continue;
			}

			// Re-adding the same ID with only a title override is the documented
			// WP_Admin_Bar mutation boundary. Existing href/parent/group/meta stay
			// owned by WordPress/the registering plugin, and child nodes are untouched.
			$admin_bar->add_node( [
				'id'    => $id,
				'title' => $rule['label'],
			] );
			$renamed[ $id ] = true;
		}
	}

	private static function has_plain_text_title( object $node ): bool {
		$title = $node->title ?? null;
		return is_string( $title )
			&& '' !== $title
			&& $title === wp_strip_all_tags( $title, true )
			&& ! str_contains( $title, '<' )
			&& ! str_contains( $title, '>' );
	}

	private function __construct() {}
}
