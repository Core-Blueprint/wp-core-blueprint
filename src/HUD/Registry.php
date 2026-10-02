<?php
declare(strict_types=1);
/**
 * Registry - HUD sections and items.
 *
 * Static, request-scoped registry that holds the sections and items
 * rendered inside the HUD panel. Sections are typed containers and items are
 * validated against the controlled presentation contract of their section type.
 *
 * Three-phase population on init:
 *   1. Section types: Base built-ins, then `cb_hud_register_section_types`.
 *   2. Sections: Base defaults, then `cb_hud_register_sections`.
 *   3. Items: Base defaults, then `cb_hud_register_items`.
 *
 * Item visibility and capability gating live in the registry itself -
 * an item with a capability the current user lacks never makes it into
 * the in-memory store, so the renderer doesn't need to know about
 * permissions.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\HUD;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var array<string, array<string, mixed>> */
	private static array $sections = [];

	/** @var array<string, array<string, mixed>> */
	private static array $items = [];

	// ─── Sections ──────────────────────────────────────────────────────────

	/**
	 * Register a canonical HUD section.
	 *
	 * Section IDs are strict kebab-case. The section `type` must already be
	 * registered in SectionTypeRegistry. Duplicate, malformed, unauthorized or
	 * unknown definitions are rejected instead of silently overwriting state.
	 *
	 * Shape:
	 * - id: required canonical kebab-case section ID;
	 * - label: required human-readable section label;
	 * - type: registered section type, default `navigation`;
	 * - capability: optional additional section capability;
	 * - order: sort order;
	 * - collapsible/collapsed_default: optional presentation preferences;
	 * - columns: 1 or 2 for body presentations.
	 *
	 * @param array<string, mixed> $definition Section definition.
	 */
	public static function register_section( array $definition ): bool {
		SectionTypeRegistry::register_builtins();

		$id = trim( (string) ( $definition['id'] ?? '' ) );
		if ( ! self::is_valid_id( $id ) ) {
			self::diagnostic( __METHOD__, 'HUD section IDs must use canonical kebab-case.' );
			return false;
		}
		if ( isset( self::$sections[ $id ] ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section "%s" is already registered.', $id ) );
			return false;
		}

		$label = trim( (string) ( $definition['label'] ?? '' ) );
		if ( '' === $label ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section "%s" requires a label.', $id ) );
			return false;
		}

		$type_id = trim( (string) ( $definition['type'] ?? 'navigation' ) );
		$type    = SectionTypeRegistry::get( $type_id );
		if ( ! is_array( $type ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section "%s" references unknown section type "%s".', $id, $type_id ) );
			return false;
		}

		$capability = trim( (string) ( $definition['capability'] ?? '' ) );
		if ( '' !== $capability && sanitize_key( $capability ) !== $capability ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section "%s" declares an invalid capability.', $id ) );
			return false;
		}

		$columns = array_key_exists( 'columns', $definition )
			? (int) $definition['columns']
			: (int) ( $type['default_columns'] ?? 1 );
		if ( ! in_array( $columns, [ 1, 2 ], true ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section "%s" must use columns 1 or 2.', $id ) );
			return false;
		}

		$collapsible = array_key_exists( 'collapsible', $definition )
			? (bool) $definition['collapsible']
			: (bool) ( $type['default_collapsible'] ?? false );

		self::$sections[ $id ] = [
			'id'                => $id,
			'label'             => $label,
			'type'              => $type_id,
			'capability'        => $capability,
			'order'             => (int) ( $definition['order'] ?? 10 ),
			'collapsible'       => $collapsible,
			'collapsed_default' => $collapsible && (bool) ( $definition['collapsed_default'] ?? false ),
			'columns'           => $columns,
			'manageable'        => (bool) ( $type['manageable'] ?? false )
				&& (bool) ( $definition['manageable'] ?? true ),
		];
		return true;
	}

	/** Register the canonical Base-owned HUD sections. */
	public static function register_default_sections(): void {
		SectionTypeRegistry::register_builtins();

		self::register_section( [
			'id'                => 'quick-actions',
			'label'             => __( 'Quick actions', 'core-blueprint' ),
			'type'              => 'quick-actions',
			'order'             => 5,
			'collapsible'       => true,
			'collapsed_default' => false,
			'columns'           => 1,
		] );
		self::register_section( [
			'id'                => 'cb-content',
			'label'             => __( 'Content', 'core-blueprint' ),
			'type'              => 'navigation',
			'order'             => 10,
			'collapsible'       => true,
			'collapsed_default' => false,
			'columns'           => 2,
		] );
		self::register_section( [
			'id'                => 'cb-site',
			'label'             => __( 'Site', 'core-blueprint' ),
			'type'              => 'navigation',
			'order'             => 20,
			'collapsible'       => true,
			'collapsed_default' => false,
			'columns'           => 2,
		] );
		self::register_section( [
			'id'                => 'cb-core',
			'label'             => __( 'Core Blueprint', 'core-blueprint' ),
			'type'              => 'navigation',
			'order'             => 30,
			'collapsible'       => true,
			'collapsed_default' => false,
			'columns'           => 2,
		] );
		self::register_section( [
			'id'          => 'status',
			'label'       => __( 'System Status', 'core-blueprint' ),
			'type'        => 'status',
			'order'       => 50,
			'collapsible' => false,
			'columns'     => 1,
		] );
	}

	// ─── Items ─────────────────────────────────────────────────────────────

	/**
	 * Register a declarative HUD item.
	 *
	 * Items are validated against the registered section and its canonical
	 * section type before entering the request-local registry. Unknown sections,
	 * unsupported item shapes and duplicate IDs are rejected; runtime capability
	 * and module gates remain fail-closed.
	 *
	 * Supported canonical item types are `link` and `stat`. The section type
	 * decides which item types are allowed.
	 *
	 * @param array<string, mixed> $item Item definition.
	 */
	public static function add_item( array $item ): bool {
		$defaults = [
			'id'                 => '',
			'label'              => '',
			'description'        => '',
			'type'               => 'link',
			'section'            => 'cb-content',
			'url'                => '',
			'quick_action_url'   => '',
			'quick_action_label' => '',
			'value'              => null,
			'capability'         => Access::capability(),
			'order'              => 10,
			'visible'            => true,
			'icon'               => '',
			'module'             => '',
			'status'             => '',
		];

		$item = array_merge( $defaults, $item );
		$id   = trim( (string) $item['id'] );
		if ( ! self::is_valid_id( $id ) || '' === trim( (string) $item['label'] ) ) {
			self::diagnostic( __METHOD__, 'HUD item IDs must use canonical kebab-case and include a label.' );
			return false;
		}
		if ( isset( self::$items[ $id ] ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD item "%s" is already registered.', $id ) );
			return false;
		}

		$section_id = trim( (string) $item['section'] );
		if ( ! self::is_valid_id( $section_id ) || ! isset( self::$sections[ $section_id ] ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD item "%s" references unknown section "%s".', $id, $section_id ) );
			return false;
		}

		$section = self::$sections[ $section_id ];
		$type_id = (string) ( $section['type'] ?? '' );
		$item_type = trim( (string) $item['type'] );
		if ( ! SectionTypeRegistry::item_type_allowed( $type_id, $item_type ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD item "%s" uses item type "%s", which section type "%s" does not allow.', $id, $item_type, $type_id ) );
			return false;
		}

		if ( 'link' === $item_type && '' === trim( (string) $item['url'] ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD link item "%s" requires a URL.', $id ) );
			return false;
		}

		$capability = trim( (string) $item['capability'] );
		if ( '' !== $capability && ( sanitize_key( $capability ) !== $capability || ! current_user_can( $capability ) ) ) {
			return false;
		}
		if ( false === (bool) $item['visible'] ) {
			return false;
		}

		$module = trim( (string) $item['module'] );
		if ( '' !== $module && ! \CB\Core\Modules\ActivationRegistry::is_enabled( $module ) ) {
			return false;
		}

		$status = trim( (string) $item['status'] );
		if ( '' !== $status && ! \CB\Core\Modules\ActivationRegistry::is_valid_id( $status ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD item "%s" declares an invalid status ID.', $id ) );
			return false;
		}

		$type = SectionTypeRegistry::get( $type_id );
		$max_items = is_array( $type ) ? max( 0, (int) ( $type['max_items'] ?? 0 ) ) : 0;
		$recommended_max = is_array( $type ) ? max( 0, (int) ( $type['recommended_max_items'] ?? 0 ) ) : 0;
		$existing = count( array_filter( self::$items, static fn( array $existing_item ): bool => $section_id === (string) ( $existing_item['section'] ?? '' ) ) );
		if ( $max_items > 0 && $existing >= $max_items ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section "%s" reached its maximum of %d items; "%s" was rejected.', $section_id, $max_items, $id ) );
			return false;
		}
		if ( $recommended_max > 0 && $existing >= $recommended_max ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section "%s" already has %d items; the recommended maximum is %d.', $section_id, $existing, $recommended_max ) );
		}

		$item['id']      = $id;
		$item['section'] = $section_id;
		$item['type']    = $item_type;
		$item['module']  = $module;
		$item['status']  = $status;
		self::$items[ $id ] = $item;
		return true;
	}

	/**
	 * Built-in HUD items, organised across the three canonical role-
	 * natural sections (cb-content / cb-site / cb-core) plus baseline
	 * status stats. Capability gating drives role-aware visibility:
	 *
	 *   - Editor sees   cb-content (CONTENT)
	 *   - Admin sees    cb-content + cb-site (CONTENT + SITE)
	 *   - Operator sees all three (CONTENT + SITE + CORE BLUEPRINT)
	 *
	 * No role-detection logic - purely capability-driven. The [+] quick-
	 * action URLs map to WP's standard "create" surfaces (post-new.php,
	 * media-new.php, plugin-install.php, etc.) so a single click goes
	 * straight to creation without passing through the index page first.
	 *
	 * Sibling plugins (Hub, Invoice, etc.) layer their own items on top
	 * via cb_hud_register_items - they typically place items in cb-core
	 * (operator-only) or register their own section via the
	 * cb_hud_register_sections action for plugin-specific content
	 * collections.
	 */
	public static function register_builtin_items(): void {
		// ─── CONTENT - Editor's territory ───────────────────────────────
		self::add_item( [
			'id'                 => 'cb-hud-wp-posts',
			'label'              => __( 'Posts', 'core-blueprint' ),
			'section'            => 'cb-content',
			'url'                => admin_url( 'edit.php' ),
			'quick_action_url'   => admin_url( 'post-new.php' ),
			'quick_action_label' => __( 'Add new post', 'core-blueprint' ),
			'order'              => 10,
			'capability'         => 'edit_posts',
			'icon'               => 'admin-post',
		] );
		self::add_item( [
			'id'                 => 'cb-hud-wp-pages',
			'label'              => __( 'Pages', 'core-blueprint' ),
			'section'            => 'cb-content',
			'url'                => admin_url( 'edit.php?post_type=page' ),
			'quick_action_url'   => admin_url( 'post-new.php?post_type=page' ),
			'quick_action_label' => __( 'Add new page', 'core-blueprint' ),
			'order'              => 20,
			'capability'         => 'edit_pages',
			'icon'               => 'admin-page',
		] );
		self::add_item( [
			'id'                 => 'cb-hud-wp-media',
			'label'              => __( 'Media', 'core-blueprint' ),
			'section'            => 'cb-content',
			'url'                => admin_url( 'upload.php' ),
			'quick_action_url'   => admin_url( 'media-new.php' ),
			'quick_action_label' => __( 'Upload media', 'core-blueprint' ),
			'order'              => 30,
			'capability'         => 'upload_files',
			'icon'               => 'admin-media',
		] );

		// Comments - only shown when comments are actually in use on
		// this site. Many sites disable comments entirely (default
		// closed for all post types, no historical comments, no pending
		// moderation), in which case showing the Comments link is dead
		// real-estate. Conditional registration keeps HUD lean for the
		// majority of CB-managed sites that don't use comments.
		//
		// Detection criteria (any one truthy = show):
		//   - default comment status is 'open' (new posts allow comments)
		//   - any existing comments in any state
		//   - any pending moderation queue
		//
		// Filter `cb_core_hud_show_comments` allows explicit override
		// for sites that want to force-show or force-hide regardless
		// of detection (e.g. a multisite where some installs need a
		// uniform answer).
		if ( self::should_show_comments() ) {
			self::add_item( [
				'id'         => 'cb-hud-wp-comments',
				'label'      => __( 'Comments', 'core-blueprint' ),
				'section'    => 'cb-content',
				'url'        => admin_url( 'edit-comments.php' ),
				'order'      => 40,
				'capability' => 'moderate_comments',
				'icon'       => 'admin-comments',
			] );
		}

		// ─── SITE - Admin's territory ───────────────────────────────────
		self::add_item( [
			'id'                 => 'cb-hud-wp-plugins',
			'label'              => __( 'Plugins', 'core-blueprint' ),
			'section'            => 'cb-site',
			'url'                => admin_url( 'plugins.php' ),
			'quick_action_url'   => admin_url( 'plugin-install.php' ),
			'quick_action_label' => __( 'Add new plugin', 'core-blueprint' ),
			'order'              => 10,
			'capability'         => 'activate_plugins',
			'icon'               => 'admin-plugins',
		] );
		self::add_item( [
			'id'                 => 'cb-hud-wp-themes',
			'label'              => __( 'Themes', 'core-blueprint' ),
			'section'            => 'cb-site',
			'url'                => admin_url( 'themes.php' ),
			'quick_action_url'   => admin_url( 'theme-install.php' ),
			'quick_action_label' => __( 'Browse themes', 'core-blueprint' ),
			'order'              => 20,
			'capability'         => 'switch_themes',
			'icon'               => 'admin-appearance',
		] );
		self::add_item( [
			'id'                 => 'cb-hud-wp-users',
			'label'              => __( 'Users', 'core-blueprint' ),
			'section'            => 'cb-site',
			'url'                => admin_url( 'users.php' ),
			'quick_action_url'   => admin_url( 'user-new.php' ),
			'quick_action_label' => __( 'Add new user', 'core-blueprint' ),
			'order'              => 30,
			'capability'         => 'list_users',
			'icon'               => 'admin-users',
		] );
		self::add_item( [
			'id'         => 'cb-hud-wp-tools',
			'label'      => __( 'Tools', 'core-blueprint' ),
			'section'    => 'cb-site',
			'url'        => admin_url( 'tools.php' ),
			'order'      => 40,
			'capability' => 'manage_options', // Tools sub-pages have varied caps; gate on admin baseline
			'icon'       => 'admin-tools',
		] );
		self::add_item( [
			'id'         => 'cb-hud-wp-settings',
			'label'      => __( 'Settings', 'core-blueprint' ),
			'section'    => 'cb-site',
			'url'        => admin_url( 'options-general.php' ),
			'order'      => 50,
			'capability' => 'manage_options',
			'icon'       => 'admin-settings',
		] );

		// ─── CORE BLUEPRINT - Operator's territory ─────────────────────
		//
		// CB-specific surfaces gated on CB Base capabilities. Plain
		// admins without those caps don't see this section at all -
		// CB is "for operators, by operators". Hub items, Reports
		// shortcuts, Safeguards, Preferences all live here under
		// their respective CB caps.
		self::add_item( [
			'id'         => 'cb-hud-cb-dashboard',
			'label'      => __( 'CB Dashboard', 'core-blueprint' ),
			'section'    => 'cb-core',
			'url'        => admin_url( 'admin.php?page=core-blueprint' ),
			'order'      => 10,
			'capability' => 'cb_view_permissions', // operator-default, admin via toggle
			'icon'       => 'dashboard',
		] );

		// ─── Status stats - baseline System Status ─────────────────────
		//
		// Two values that always exist on every WP install. Renders as
		// the canonical header status-strip presentation. Hub / Invoice / siblings layer richer status items
		// (Beacon connect-state, sync recency, fleet health) via
		// cb_hud_register_items without HUD itself needing to know.

		global $wp_version;
		self::add_item( [
			'id'      => 'cb-hud-stat-wp-version',
			'label'   => __( 'WP', 'core-blueprint' ),
			'type'    => 'stat',
			'section' => 'status',
			'value'   => is_string( $wp_version ?? null ) ? $wp_version : '-',
			'order'   => 10,
		] );

		self::add_item( [
			'id'      => 'cb-hud-stat-php-version',
			'label'   => __( 'PHP', 'core-blueprint' ),
			'type'    => 'stat',
			'section' => 'status',
			'value'   => PHP_VERSION,
			'order'   => 20,
		] );

		// Updates count - most actionable status signal. Computed via
		// wp_get_update_data() which aggregates plugins/themes/core/
		// translations into a single counts array. Capability-gated on
		// update_core (the same cap the WP Updates page uses) so users
		// without update access don't see a count they can't act on.
		if ( current_user_can( 'update_core' ) && function_exists( 'wp_get_update_data' ) ) {
			$updates = wp_get_update_data();
			$count   = (int) ( $updates['counts']['total'] ?? 0 );
			self::add_item( [
				'id'         => 'cb-hud-stat-updates',
				'label'      => __( 'Updates', 'core-blueprint' ),
				'type'       => 'stat',
				'section'    => 'status',
				'value'      => (string) $count,
				'order'      => 30,
				'capability' => 'update_core',
			] );
		}
	}

	// ─── Read API ──────────────────────────────────────────────────────────

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function sections(): array {
		return MenuPreferences::apply_sections( self::catalog_sections() );
	}

	/**
	 * Return registered sections before site-wide HUD menu presentation
	 * overrides are applied. Intended for the Preferences editor/catalog.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function catalog_sections(): array {
		$sections = array_filter(
			self::$sections,
			static fn( array $section ): bool => self::section_capability_allows( $section )
		);
		uasort(
			$sections,
			static function ( array $a, array $b ): int {
				$by_order = (int) ( $a['order'] ?? 10 ) <=> (int) ( $b['order'] ?? 10 );
				return 0 !== $by_order ? $by_order : strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);
		return $sections;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function items_for_section( string $section ): array {
		if ( ! self::is_valid_id( $section ) ) {
			return [];
		}
		return MenuPreferences::apply_items( $section, self::catalog_items_for_section( $section ) );
	}

	/**
	 * Return runtime-eligible items before site-wide HUD menu presentation
	 * overrides are applied. Capability/module gates remain authoritative.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function catalog_items_for_section( string $section ): array {
		if ( ! self::is_valid_id( $section ) ) {
			return [];
		}
		$items = array_filter(
			self::$items,
			static fn( array $item ): bool => $section === (string) $item['section']
		);
		uasort(
			$items,
			static function ( array $a, array $b ): int {
				$by_order = (int) ( $a['order'] ?? 10 ) <=> (int) ( $b['order'] ?? 10 );
				return 0 !== $by_order ? $by_order : strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);
		return array_values( $items );
	}


	/** @return array<string, mixed>|null */
	public static function section( string $id ): ?array {
		return self::is_valid_id( $id ) && isset( self::$sections[ $id ] ) ? self::$sections[ $id ] : null;
	}

	/** Whether a registered section may be hidden/reordered in HUD Preferences. */
	public static function is_manageable_section( string $id ): bool {
		$section = self::section( $id );
		return is_array( $section ) && (bool) ( $section['manageable'] ?? false );
	}

	/**
	 * Return registered sections assigned to a controlled renderer placement.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function sections_for_placement( string $placement ): array {
		$sections = [];
		foreach ( self::sections() as $id => $section ) {
			$type = SectionTypeRegistry::get( (string) ( $section['type'] ?? '' ) );
			if ( is_array( $type ) && $placement === (string) ( $type['placement'] ?? 'body' ) ) {
				$sections[ $id ] = $section;
			}
		}
		return $sections;
	}

	/** Reset registry state. Internal test support; not part of the public API. */
	public static function reset(): void {
		self::$sections = [];
		self::$items    = [];
		SectionTypeRegistry::reset();
	}

	/** @param array<string, mixed> $section */
	private static function section_capability_allows( array $section ): bool {
		$type = SectionTypeRegistry::get( (string) ( $section['type'] ?? '' ) );
		if ( ! is_array( $type ) ) {
			return false;
		}
		$type_capability = (string) ( $type['capability'] ?? '' );
		if ( '' !== $type_capability && ! current_user_can( $type_capability ) ) {
			return false;
		}
		$section_capability = (string) ( $section['capability'] ?? '' );
		return '' === $section_capability || current_user_can( $section_capability );
	}

	/** Canonical kebab-case identifier validator shared by sections/items. */
	private static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
	}

	private static function diagnostic( string $method, string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( esc_html( $method ), esc_html( $message ), '1.0.0' );
		}
	}

	/**
	 * Detect whether the site uses comments meaningfully enough to
	 * warrant a HUD shortcut. Three signals must ALL be cold for the
	 * Comments item to be hidden:
	 *
	 *   1. Default comment status on new posts is 'closed'
	 *   2. No existing comments in any state (approved/pending/spam/trash)
	 *   3. No pending moderation queue (defensive duplicate of #2 since
	 *      pending comments contribute to total_comments, but explicit
	 *      check guards against edge cases where wp_count_comments()
	 *      returns surprising shapes)
	 *
	 * If all three are cold the site is genuinely comment-free; hiding
	 * the link declutters HUD without losing functionality (operators
	 * can still reach edit-comments.php via wp-admin sidebar).
	 *
	 * Filter `cb_core_hud_show_comments` overrides the detection in
	 * either direction - return true to force-show, false to force-hide.
	 * Useful for multisite-uniformity or for sites that disable
	 * comments via plugin (where default_comment_status may still be
	 * 'open' but the plugin suppresses the UI entirely).
	 */
	private static function should_show_comments(): bool {
		// Detection - start optimistic (show), only hide when all
		// signals confirm comments are dead.
		$show = true;

		$default_status = (string) get_option( 'default_comment_status', 'open' );
		if ( 'closed' === $default_status ) {
			// Default is closed - check if any historical comments exist.
			$counts = function_exists( 'wp_count_comments' ) ? wp_count_comments() : null;
			$total  = is_object( $counts ) ? (int) ( $counts->total_comments ?? 0 ) : 0;
			$pending = is_object( $counts ) ? (int) ( $counts->moderated ?? 0 ) : 0;

			if ( 0 === $total && 0 === $pending ) {
				$show = false;
			}
		}

		/**
		 * Filter: cb_core_hud_show_comments
		 *
		 * Override the auto-detection result for the Comments item.
		 * Return true to force-show, false to force-hide. Default is
		 * the auto-detection outcome.
		 *
		 * @param bool $show Whether to register the Comments item.
		 */
		return (bool) apply_filters( 'cb_core_hud_show_comments', $show );
	}
}
