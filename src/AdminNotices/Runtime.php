<?php
declare(strict_types=1);
/**
 * WordPress-native Admin Notices audience runtime.
 *
 * Runtime removes only future callbacks from the current public WordPress
 * notice hook. It never parses rendered HTML, hides DOM nodes, rewrites notice
 * markup, or changes the callback's underlying authorization.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\AdminNotices;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	private const SITE_HOOKS = [ 'admin_notices', 'all_admin_notices' ];

	public static function boot(): void {
		foreach ( self::SITE_HOOKS as $hook ) {
			add_action( $hook, [ __CLASS__, 'govern_current_hook' ], PHP_INT_MIN );
		}
	}

	public static function govern_current_hook(): void {
		self::apply( (string) current_filter() );
	}

	/** @return list<string> Source IDs removed from this hook. */
	public static function apply( string $hook ): array {
		if (
			! in_array( $hook, self::SITE_HOOKS, true )
			|| ! Policy::has_restrictions()
			|| is_network_admin()
			|| is_user_admin()
			|| ! Visibility::governance_available()
			|| Visibility::current_user_is_manager()
		) {
			return [];
		}

		$removed = [];
		foreach ( Discovery::callbacks( $hook ) as $entry ) {
			$source = $entry['source'];
			if ( empty( $source['manageable'] ) || Visibility::allows_current_user( (string) $source['id'] ) ) {
				continue;
			}

			remove_action( $hook, $entry['callback'], (int) $entry['priority'] );
			$removed[ (string) $source['id'] ] = (string) $source['id'];
		}

		return array_values( $removed );
	}

	private function __construct() {}
}
