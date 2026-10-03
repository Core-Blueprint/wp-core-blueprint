<?php
declare(strict_types=1);
/** Native top-level wp-admin menu presentation governance. */

namespace CoreBlueprint\Core\AdminNavigation;

defined( 'ABSPATH' ) || exit;

final class AdminMenuRuntime {

	/**
	 * Preserve the incoming WordPress/plugin decision unless a real saved order
	 * exists or the current request is the Admin Navigation editor discovery pass.
	 */
	public static function filter_custom_menu_order( bool $enabled ): bool {
		return Policy::has_menu_order() || Discovery::is_editor_request() ? true : $enabled;
	}

	/**
	 * Capture current-request identities and apply only explicit saved ordering.
	 * Unknown/new identities remain present and preserve their relative order.
	 */
	public static function filter_menu_order( array $menu_order ): array {
		Discovery::capture_menu_order( $menu_order );
		$order = Policy::get()['menu']['order'];
		if ( [] === $order ) {
			return $menu_order;
		}

		$present = [];
		foreach ( $menu_order as $identity ) {
			if ( is_string( $identity ) ) {
				$present[ $identity ] = true;
			}
		}

		$result = [];
		$governed = [];
		foreach ( $order as $identity ) {
			if ( isset( $present[ $identity ] ) ) {
				$result[] = $identity;
				$governed[ $identity ] = true;
			}
		}

		foreach ( $menu_order as $identity ) {
			if ( ! is_string( $identity ) || ! isset( $governed[ $identity ] ) ) {
				$result[] = $identity;
			}
		}

		return $result;
	}

	/** Apply presentation-only top-level hide rules after normal registrations. */
	public static function apply_visibility(): void {
		if ( is_network_admin() || is_user_admin() ) {
			return;
		}

		foreach ( Policy::get()['menu']['hidden'] as $rule ) {
			if ( Audience::matches( $rule['audience'] ) ) {
				remove_menu_page( $rule['id'] );
			}
		}
	}

	private function __construct() {}
}
