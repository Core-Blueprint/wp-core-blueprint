<?php
declare(strict_types=1);
/**
 * Request-local discovery cache for native WordPress navigation identities.
 *
 * This is not a site-wide registry. Only identities exposed by WordPress to the
 * current wp-admin request are captured, then unioned with persisted references
 * so temporarily absent or non-discoverable rules remain configurable later.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\AdminNavigation;

defined( 'ABSPATH' ) || exit;

final class Discovery {

	private const EDITOR_PAGE = 'core-blueprint-preferences';
	private const EDITOR_TAB = 'admin-navigation';

	/** @var list<string> */
	private static array $menu_identities = [];

	/** @var list<string> */
	private static array $toolbar_identities = [];

	/** Capture exactly the top-level identities WordPress exposes to menu_order. */
	public static function capture_menu_order( array $menu_order ): void {
		self::$menu_identities = self::unique_valid( $menu_order );
	}

	/**
	 * Capture toolbar nodes from the current normal wp-admin request.
	 * Frontend runtime must not call this method.
	 */
	public static function capture_toolbar( \WP_Admin_Bar $admin_bar ): void {
		$nodes = $admin_bar->get_nodes();
		$ids = [];
		if ( is_array( $nodes ) ) {
			foreach ( $nodes as $node ) {
				$id = is_object( $node ) && isset( $node->id ) ? $node->id : null;
				if ( Policy::is_valid_identity( $id ) ) {
					$ids[] = $id;
				}
			}
		}
		self::$toolbar_identities = self::unique_valid( $ids );
	}

	/** Current request discovery plus every persisted menu policy reference. */
	public static function menu_identities(): array {
		return self::unique_valid( array_merge( self::$menu_identities, Policy::referenced_menu_identities() ) );
	}

	/** Current wp-admin toolbar discovery plus every persisted toolbar policy reference. */
	public static function toolbar_identities(): array {
		return self::unique_valid( array_merge( self::$toolbar_identities, Policy::referenced_toolbar_identities() ) );
	}

	/** Request-local opt-in used by the future Preferences editor only. */
	public static function is_editor_request(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		return self::EDITOR_PAGE === $page && self::EDITOR_TAB === $tab;
	}

	/** @internal Integration tests only. */
	public static function _reset_for_testing(): void {
		self::$menu_identities = [];
		self::$toolbar_identities = [];
	}

	/** @return list<string> */
	private static function unique_valid( array $identities ): array {
		$unique = [];
		foreach ( $identities as $identity ) {
			if ( Policy::is_valid_identity( $identity ) ) {
				$unique[ $identity ] = $identity;
			}
		}
		return array_values( $unique );
	}

	private function __construct() {}
}
