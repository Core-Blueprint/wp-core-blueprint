<?php
declare(strict_types=1);
/**
 * MenuGroupRegistry - public registration boundary for extension-owned
 * top-level product menus.
 *
 * Extensions register one MenuGroup plus its Page implementations during the
 * existing core_blueprint_register_pages lifecycle. Base validates declarations,
 * wires WordPress menus/hooks and resolves shared semantic UI requirements.
 *
 * A product-group slug is menu identity only. Page slugs are distinct screen
 * identities. This guarantees one canonical render callback per WordPress
 * admin screen and avoids top-level/submenu hook collisions.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class MenuGroupRegistry {

	/** @var array<string,MenuGroup> */
	private static array $groups = [];

	/** @var array<string,Page> */
	private static array $pages = [];

	/** @var array<string,string> page slug -> group slug */
	private static array $page_groups = [];

	/** @var array<string,array{foundations:string[],components:string[]}> */
	private static array $requirements = [];

	/** @var array<string,string> page slug -> WordPress hook suffix */
	private static array $hooks = [];

	/** @var array<string,string> top-level hook suffix -> group slug */
	private static array $landing_hooks = [];

	private static bool $initialized = false;
	private static bool $finalized = false;

	/** Boot menu wiring before WordPress begins the admin_menu lifecycle. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// PageRegistry collects declarations at priority 20. Product groups wire
		// immediately afterwards from the completed declaration set.
		add_action( 'admin_menu', [ self::class, 'finalize' ], 21 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_requirements_for_hook' ], 20 );
	}

	/**
	 * Register a top-level product menu and all pages it owns.
	 *
	 * The product-group slug and every page slug MUST be distinct. Selecting the
	 * top-level item renders the first accessible page in canonical page order.
	 *
	 * @param Page[] $pages
	 * @param array<string,array{foundations?:string[],components?:string[]}> $requirements
	 */
	public static function register( MenuGroup $group, array $pages, array $requirements = [] ): bool {
		if ( self::$finalized ) {
			self::diagnostic( 'Top-level product menus must be registered during core_blueprint_register_pages before menu wiring is finalized.' );
			return false;
		}

		if ( ! self::valid_group( $group ) ) {
			return false;
		}

		$group_slug = $group->slug();
		if ( isset( self::$groups[ $group_slug ] ) ) {
			self::diagnostic( "Top-level product menu '{$group_slug}' is already registered; duplicates are rejected." );
			return false;
		}

		if ( [] === $pages ) {
			self::diagnostic( "Top-level product menu '{$group_slug}' must register at least one page." );
			return false;
		}

		$normalized_pages = [];
		$normalized_requirements = [];
		$seen = [];

		foreach ( $pages as $page ) {
			if ( ! $page instanceof Page ) {
				self::diagnostic( "Top-level product menu '{$group_slug}' received a value that does not implement the Core Admin Page contract." );
				return false;
			}

			$slug = $page->slug();
			if ( ! self::valid_slug( $slug ) ) {
				self::diagnostic( "Top-level product page slug '{$slug}' is invalid. Use lower-case kebab-case." );
				return false;
			}
			if ( $slug === $group_slug ) {
				self::diagnostic( "Top-level product page '{$slug}' collides with its product-group slug. Group and page identities must be distinct." );
				return false;
			}
			if ( isset( $seen[ $slug ] ) || isset( self::$pages[ $slug ] ) ) {
				self::diagnostic( "Top-level product page '{$slug}' is already registered; duplicates are rejected." );
				return false;
			}
			if ( '' === $page->capability() || sanitize_key( $page->capability() ) !== $page->capability() ) {
				self::diagnostic( "Top-level product page '{$slug}' has an invalid capability." );
				return false;
			}
			if ( null !== $page->position() && $page->position() < 0 ) {
				self::diagnostic( "Top-level product page '{$slug}' cannot use a negative position." );
				return false;
			}

			$normalized = PageRegistry::normalize_semantic_requirements( $requirements[ $slug ] ?? [], $slug );
			if ( null === $normalized ) {
				return false;
			}

			$seen[ $slug ] = true;
			$normalized_pages[ $slug ] = $page;
			$normalized_requirements[ $slug ] = $normalized;
		}

		$unknown_requirement_pages = array_diff( array_keys( $requirements ), array_keys( $normalized_pages ) );
		if ( [] !== $unknown_requirement_pages ) {
			self::diagnostic( "Top-level product menu '{$group_slug}' contains requirements for an unknown page." );
			return false;
		}

		self::$groups[ $group_slug ] = $group;
		foreach ( $normalized_pages as $slug => $page ) {
			self::$pages[ $slug ] = $page;
			self::$page_groups[ $slug ] = $group_slug;
			self::$requirements[ $slug ] = $normalized_requirements[ $slug ];
		}

		return true;
	}

	public static function get( string $slug ): ?Page {
		return self::$pages[ $slug ] ?? null;
	}

	public static function group( string $slug ): ?MenuGroup {
		return self::$groups[ $slug ] ?? null;
	}

	/** Return the dedicated submenu hook for a registered product page. */
	public static function hook_suffix( string $page_slug ): string {
		return self::$hooks[ $page_slug ] ?? '';
	}

	/**
	 * Resolve a product-owned page from its registered WordPress screen hook.
	 *
	 * A group's top-level landing hook resolves to the first page the current
	 * user may access, just like render_landing(). Submenus resolve directly.
	 * ScreenContext uses this to load the same Base Admin shell and semantic
	 * assets on top-level product pages as on PageRegistry pages.
	 */
	public static function registered_page_slug_for_hook( string $hook ): string {
		if ( '' === $hook ) {
			return '';
		}

		$slug = array_search( $hook, self::$hooks, true );
		if ( false !== $slug ) {
			return (string) $slug;
		}

		$group_slug = self::$landing_hooks[ $hook ] ?? '';
		if ( '' === $group_slug ) {
			return '';
		}

		$page = self::accessible_landing_page( $group_slug );
		return null !== $page ? $page->slug() : '';
	}

	/**
	 * Whether one WordPress screen hook currently represents this product page.
	 *
	 * A page matches either its dedicated submenu hook or the top-level product
	 * hook when it is the first accessible landing page for the current user.
	 */
	public static function is_page_hook( string $page_slug, string $hook ): bool {
		if ( '' === $hook || ! isset( self::$pages[ $page_slug ] ) ) {
			return false;
		}
		if ( ( self::$hooks[ $page_slug ] ?? '' ) === $hook ) {
			return true;
		}

		$group_slug = self::$page_groups[ $page_slug ] ?? '';
		if ( '' === $group_slug || ( self::$landing_hooks[ $hook ] ?? '' ) !== $group_slug ) {
			return false;
		}

		$landing = self::accessible_landing_page( $group_slug );
		return null !== $landing && $landing->slug() === $page_slug;
	}

	/**
	 * Wire all collected product groups into WordPress.
	 *
	 * @internal admin_menu callback registered by init().
	 */
	public static function finalize(): void {
		if ( self::$finalized ) {
			return;
		}
		self::$finalized = true;

		foreach ( self::$groups as $group_slug => $_group ) {
			if ( null !== PageRegistry::get( $group_slug ) ) {
				self::diagnostic( "Top-level product menu '{$group_slug}' collides with a registered Core Admin page." );
				return;
			}
		}
		foreach ( array_keys( self::$pages ) as $slug ) {
			if ( null !== PageRegistry::get( $slug ) ) {
				self::diagnostic( "Admin page slug '{$slug}' is registered in both PageRegistry and MenuGroupRegistry. Product-menu wiring was aborted." );
				return;
			}
		}

		foreach ( self::$groups as $group_slug => $group ) {
			$landing_hook = add_menu_page(
				$group->title(),
				$group->menu_title(),
				$group->capability(),
				$group_slug,
				static function () use ( $group_slug ): void {
					self::render_landing( $group_slug );
				},
				$group->icon(),
				$group->position()
			);

			if ( $landing_hook ) {
				self::$landing_hooks[ $landing_hook ] = $group_slug;
			}

			foreach ( self::pages_for_group( $group_slug ) as $page ) {
				$suffix = add_submenu_page(
					$group_slug,
					$page->title(),
					$page->menu_title(),
					$page->capability(),
					$page->slug(),
					[ $page, 'render' ],
					$page->position()
				);
				if ( $suffix ) {
					self::$hooks[ $page->slug() ] = $suffix;
				}
			}

			// WordPress auto-inserts the top-level item as the first submenu when
			// child slugs differ. Product groups expose only their declared pages.
			remove_submenu_page( $group_slug, $group_slug );
		}
	}

	/** Enqueue semantic requirements for a top-level product page hook. */
	public static function enqueue_requirements_for_hook( string $hook ): void {
		if ( isset( self::$landing_hooks[ $hook ] ) ) {
			$page = self::accessible_landing_page( self::$landing_hooks[ $hook ] );
			if ( null !== $page ) {
				self::enqueue_page_requirements( $page->slug(), $hook );
			}
			return;
		}

		$slug = array_search( $hook, self::$hooks, true );
		if ( false !== $slug ) {
			self::enqueue_page_requirements( $slug, $hook );
		}
	}

	/** Reset registry state - tests only. */
	public static function _reset_for_testing(): void {
		self::$groups = [];
		self::$pages = [];
		self::$page_groups = [];
		self::$requirements = [];
		self::$hooks = [];
		self::$landing_hooks = [];
		self::$finalized = false;
	}

	private static function render_landing( string $group_slug ): void {
		$page = self::accessible_landing_page( $group_slug );
		if ( null === $page ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
		$page->render();
	}

	private static function accessible_landing_page( string $group_slug ): ?Page {
		foreach ( self::pages_for_group( $group_slug ) as $page ) {
			if ( current_user_can( $page->capability() ) ) {
				return $page;
			}
		}
		return null;
	}

	/** @return Page[] */
	private static function pages_for_group( string $group_slug ): array {
		$pages = [];
		foreach ( self::$pages as $slug => $page ) {
			if ( ( self::$page_groups[ $slug ] ?? '' ) === $group_slug ) {
				$pages[] = $page;
			}
		}
		usort( $pages, static function ( Page $a, Page $b ): int {
			$pa = $a->position();
			$pb = $b->position();
			if ( null === $pa && null === $pb ) {
				return strcmp( $a->slug(), $b->slug() );
			}
			if ( null === $pa ) {
				return 1;
			}
			if ( null === $pb ) {
				return -1;
			}
			if ( $pa === $pb ) {
				return strcmp( $a->slug(), $b->slug() );
			}
			return $pa <=> $pb;
		} );
		return $pages;
	}

	private static function enqueue_page_requirements( string $slug, string $hook ): void {
		if ( isset( self::$requirements[ $slug ] ) ) {
			PageRegistry::enqueue_semantic_requirements( self::$requirements[ $slug ], $hook );
		}
	}

	private static function valid_group( MenuGroup $group ): bool {
		$slug = $group->slug();
		if ( ! self::valid_slug( $slug ) ) {
			self::diagnostic( "Top-level product menu slug '{$slug}' is invalid. Use lower-case kebab-case." );
			return false;
		}
		if ( '' === trim( $group->title() ) || '' === trim( $group->menu_title() ) ) {
			self::diagnostic( "Top-level product menu '{$slug}' requires non-empty translated labels." );
			return false;
		}
		if ( '' === $group->capability() || sanitize_key( $group->capability() ) !== $group->capability() ) {
			self::diagnostic( "Top-level product menu '{$slug}' has an invalid capability." );
			return false;
		}
		if ( null !== $group->position() && $group->position() < 0 ) {
			self::diagnostic( "Top-level product menu '{$slug}' cannot use a negative position." );
			return false;
		}
		return true;
	}

	private static function valid_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $slug );
	}

	private static function diagnostic( string $message ): void {
		_doing_it_wrong( self::class, esc_html( $message ), esc_html( CB_CORE_VERSION ) );
	}

	private function __construct() {}
}
