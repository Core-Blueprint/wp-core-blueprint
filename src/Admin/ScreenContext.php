<?php
declare(strict_types=1);
/**
 * Normalized snapshot of the current WordPress admin screen request.
 *
 * ScreenContext answers only "where am I?". It intentionally owns no asset
 * rules, capability policy, page definitions or feature/business logic.
 *
 * @package Core_Blueprint
 * @since   1.0.0-rc3.26
 */

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class ScreenContext {

	private function __construct(
		private readonly string $hook,
		private readonly string $registered_slug,
		private readonly string $page,
		private readonly string $tab,
		private readonly string $view
	) {}

	/** Build one normalized, read-only snapshot for the current admin screen. */
	public static function from_request( string $hook ): self {
		$registered_slug = self::registered_slug_for_hook( $hook );
		$page             = '' !== $registered_slug ? $registered_slug : self::request_key( 'page' );

		if ( '' === $page && 'toplevel_page_' . CB_CORE_PARENT_MENU === $hook ) {
			$page = CB_CORE_PARENT_MENU;
		}

		$tab  = self::normalize_tab( $page, self::request_key( 'tab' ) );
		$view = self::normalize_view( $page, $tab, self::request_key( 'view' ) );

		return new self( $hook, $registered_slug, $page, $tab, $view );
	}

	public function hook(): string {
		return $this->hook;
	}

	public function registered_slug(): string {
		return $this->registered_slug;
	}

	public function page(): string {
		return $this->page;
	}

	public function tab(): string {
		return $this->tab;
	}

	public function view(): string {
		return $this->view;
	}

	/** Read and sanitize a routing key once. */
	private static function request_key( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing context.
			return '';
		}

		return sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing context.
	}

	/**
	 * Normalize Base-owned tab routes to the same defaults/allowlists used by
	 * their renderers. Open extension tab registries (Logs/Reports) preserve an
	 * unknown sanitized slug so the private asset registry can choose its safe
	 * compatibility fallback without teaching ScreenContext extension policy.
	 */
	private static function normalize_tab( string $page, string $tab ): string {
		$closed = [
			'core-blueprint-preferences' => [
				'default' => 'overview',
				'allowed' => [ 'overview', 'privacy', 'notifications', 'language', 'appearance', 'floating-menu', 'admin-navigation', 'reports', 'permissions', 'notes', 'cli', 'about' ],
			],
			'core-blueprint-safeguards' => [
				'default' => 'overview',
				'allowed' => [ 'overview', 'access-mode', 'login-shield', 'core-shield', 'core-scanner', 'failsafe' ],
			],
			'core-blueprint-mail' => [
				'default' => 'settings',
				'allowed' => [ 'settings', 'test', 'logs' ],
			],
			'core-blueprint-snippets' => [
				'default' => 'snippets',
				'allowed' => [ 'snippets', 'settings', 'import-export' ],
			],
			'core-blueprint-content-models' => [
				'default' => 'post-types',
				'allowed' => [ 'post-types', 'taxonomies', 'option-pages', 'field-groups', 'tools' ],
			],
		];

		if ( isset( $closed[ $page ] ) ) {
			$rule = $closed[ $page ];
			return in_array( $tab, $rule['allowed'], true ) ? $tab : $rule['default'];
		}

		if ( 'core-blueprint-logs' === $page || 'core-blueprint-reports' === $page ) {
			return '' === $tab ? 'overview' : $tab;
		}

		return $tab;
	}

	/** Normalize view routes where Base has a closed, renderer-owned view set. */
	private static function normalize_view( string $page, string $tab, string $view ): string {
		if ( 'core-blueprint-snippets' === $page ) {
			if ( 'snippets' !== $tab ) {
				return '';
			}
			return 'edit' === $view ? 'edit' : 'list';
		}

		if ( 'core-blueprint-content-models' === $page ) {
			if ( 'tools' === $tab ) {
				return '';
			}

			$allowed = 'field-groups' === $tab
				? [ 'list', 'edit', 'duplicate', 'delete', 'field', 'duplicate-field', 'delete-field' ]
				: [ 'list', 'edit', 'duplicate', 'delete' ];

			return in_array( $view, $allowed, true ) ? $view : 'list';
		}

		return $view;
	}

	/** Resolve registry ownership without copying page definitions into context. */
	private static function registered_slug_for_hook( string $hook ): string {
		if ( 'toplevel_page_' . CB_CORE_PARENT_MENU === $hook ) {
			return CB_CORE_PARENT_MENU;
		}

		foreach ( PageRegistry::all() as $page ) {
			$slug = $page->slug();
			if ( $hook === PageRegistry::hook_suffix( $slug ) ) {
				return $slug;
			}
		}

		return '';
	}
}
