<?php
declare(strict_types=1);
/**
 * Preferences - personal, site-wide, governance, and About aggregator page.
 *
 * Optional module activation is managed from the Dashboard. Preferences contains
 * only actual configuration surfaces such as privacy, appearance, Notes defaults,
 * Reports branding, permissions, CLI reference, and About.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin\Pages;

use CB\Core\Locale;
use CB\Core\Admin\Admin;
use CB\Core\Admin\Overview;
use CB\Core\Admin\PageBase;
use CB\Core\Admin\Tabbed;
use CB\Core\AdminNavigation\Admin as AdminNavigationAdmin;
use CB\Core\Themes;
use CB\Core\UI;
use CB\Core\HUD\MenuPreferences;
use CB\Core\HUD\Registry as HudRegistry;

defined( 'ABSPATH' ) || exit;

final class Preferences extends PageBase {
	use Tabbed;

	const SLUG = 'core-blueprint-preferences';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Preferences', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Preferences', 'core-blueprint' );
	}

	public function position(): ?int {
		return 90;
	}

	public function render(): void {
		$this->guard();

		// Personal/site-wide preferences come first, followed by module-specific
		// configuration that remains meaningful independently from activation, then
		// meta-governance and reference tabs. Module on/off state lives on Dashboard.
		$available_tabs = [ 'overview', 'privacy', 'notifications', 'language', 'appearance', 'floating-menu', 'admin-navigation', 'routing', 'reports', 'notes', 'permissions', 'cli', 'about' ];
		$tab_labels     = [
			'overview'        => __( 'Overview',        'core-blueprint' ),
			'privacy'         => __( 'Privacy',         'core-blueprint' ),
			'notifications'   => __( 'Notifications',   'core-blueprint' ),
			'language'        => __( 'Language',        'core-blueprint' ),
			'appearance'      => __( 'Appearance',      'core-blueprint' ),
			'floating-menu'   => __( 'Floating Menu',   'core-blueprint' ),
			'admin-navigation'=> __( 'Admin Navigation','core-blueprint' ),
			'routing'         => __( 'Routing & URLs',  'core-blueprint' ),
			'reports'           => __( 'Reports',           'core-blueprint' ),
			'notes'           => __( 'Notes',           'core-blueprint' ),
			'permissions'     => __( 'Permissions',     'core-blueprint' ),
			'cli'             => __( 'CLI',             'core-blueprint' ),
			'about'           => __( 'About',           'core-blueprint' ),
		];


		// Reports preferences remain visible to anyone who can manage branding
		// or Reports configuration. Module activation itself lives on Dashboard.
		if ( ! current_user_can( 'cb_manage_branding' ) && ! current_user_can( 'cb_manage_reports' ) ) {
			unset( $tab_labels['reports'] );
			$available_tabs = array_values( array_diff( $available_tabs, [ 'reports' ] ) );
		}

		// Hide the Permissions tab when:
		//   (a) the user lacks cb_view_permissions entirely (e.g. a custom
		//       editor role with manage_options-but-nothing-CB), OR
		//   (b) the hide-toggle is enabled AND the user is not an operator.
		// OperatorGuard auto-disables the toggle when operator_count drops
		// to zero, so case (b) can never produce a hard lockout.
		$can_view_permissions   = current_user_can( 'cb_view_permissions' );
		$can_manage_permissions = current_user_can( 'cb_manage_permissions' );
		$hide_active            = ! empty( \CB\Core\Settings::get()['permissions']['hide_from_admins'] );

		if ( ! $can_view_permissions || ( $hide_active && ! $can_manage_permissions ) ) {
			unset( $tab_labels['permissions'] );
			$available_tabs = array_values( array_diff( $available_tabs, [ 'permissions' ] ) );
		}

		// Notes tab is gated on cb_manage_notes - same cap that controls
		// the top-level Notes page. Both administrators and operators
		// inherit it on activation, but a custom role without the cap
		// shouldn't see the configuration tab either.
		if ( ! current_user_can( 'cb_manage_notes' ) ) {
			unset( $tab_labels['notes'] );
			$available_tabs = array_values( array_diff( $available_tabs, [ 'notes' ] ) );
		}

		// CLI documentation tab - operator-only, gated on cb_use_cli.
		// Has no admin-toggle: WP-CLI access is a server-shell concern,
		// not something a site admin grants/revokes through CB. The tab
		// is purely documentation in 1.5.0-dev (no execution surface);
		// hiding it from non-operators avoids confusion about a feature
		// they can't act on anyway.
		if ( ! current_user_can( 'cb_use_cli' ) ) {
			unset( $tab_labels['cli'] );
			$available_tabs = array_values( array_diff( $available_tabs, [ 'cli' ] ) );
		}

		$tab = $this->active_tab( $available_tabs, 'overview' );

		switch ( $tab ) {
			case 'privacy':         $this->render_privacy_tab( $tab, $tab_labels );         return;
			case 'notifications':   $this->render_notifications_tab( $tab, $tab_labels );   return;
			case 'language':        $this->render_language_tab( $tab, $tab_labels );        return;
			case 'appearance':      $this->render_appearance_tab( $tab, $tab_labels );      return;
			case 'floating-menu':   $this->render_floating_menu_tab( $tab, $tab_labels );   return;
			case 'admin-navigation':$this->render_admin_navigation_tab( $tab, $tab_labels ); return;
			case 'routing':         $this->render_routing_tab( $tab, $tab_labels );          return;
			case 'reports':           $this->render_reports_tab( $tab, $tab_labels );                            return;
			case 'permissions':     $this->render_permissions_tab( $tab, $tab_labels );     return;
			case 'notes':           $this->render_notes_tab( $tab, $tab_labels );           return;
			case 'cli':             $this->render_cli_tab( $tab, $tab_labels );             return;
			case 'about':           $this->render_about_tab( $tab, $tab_labels );           return;
			default:                $this->render_overview_tab( $tab, $tab_labels );        return;
		}
	}

	private function render_overview_tab( string $tab, array $tab_labels ): void {
		$base_url = admin_url( 'admin.php?page=' . self::SLUG );

		// Small pieces of current state to show on status cards - reads
		// only what is safe to read without class_exists guards. Preferences
		// has no "critical" states so all cards are informational, not
		// colour-coded.
		$current_theme_label  = '';
		$current_locale_label = '';
		$current_desc_label   = '';

		if ( class_exists( Themes::class ) ) {
			$current_theme        = Themes::current();
			$themes               = Themes::all();
			$current_theme_label  = $themes[ $current_theme ]['label'] ?? $current_theme;
		}
		if ( class_exists( Locale::class ) ) {
			$current_locale = Locale::current();
			// Friendly human label where WP provides one, else the raw code.
			$translations         = function_exists( 'wp_get_available_translations' )
				? wp_get_available_translations()
				: [];
			$current_locale_label = $translations[ $current_locale ]['native_name'] ?? $current_locale;
		}
		if ( class_exists( UI::class ) ) {
			$current_desc       = UI::current_mode();
			$current_desc_label = 'technical' === $current_desc
				? __( 'Technical', 'core-blueprint' )
				: __( 'Plain', 'core-blueprint' );
		}

		// Tab cards are built dynamically because Report Branding and
		// Permissions are capability-gated (mirrors the visibility logic
		// in render() above). Order matches the tab navigation order.
		$tab_cards = [
			[
				'slug'  => 'privacy',
				'url'   => add_query_arg( 'tab', 'privacy', $base_url ),
				'label' => __( 'Privacy', 'core-blueprint' ),
				'desc'  => __( 'How IP addresses are stored in logs and which data categories the plugin may record. Applies site-wide.', 'core-blueprint' ),
				'icon'  => 'privacy',
			],
			[
				'slug'  => 'notifications',
				'url'   => add_query_arg( 'tab', 'notifications', $base_url ),
				'label' => __( 'Notifications', 'core-blueprint' ),
				'desc'  => __( 'Who receives email alerts from Core Blueprint, and which event severities trigger them. One place for all plugin notifications.', 'core-blueprint' ),
				'icon'  => 'email-alt',
			],
			[
				'slug'  => 'language',
				'url'   => add_query_arg( 'tab', 'language', $base_url ),
				'label' => __( 'Language', 'core-blueprint' ),
				'desc'  => __( 'Which language WordPress uses, and whether descriptions throughout the plugin are written in Plain or Technical style.', 'core-blueprint' ),
				'icon'  => 'translation',
			],
			[
				'slug'  => 'appearance',
				'url'   => add_query_arg( 'tab', 'appearance', $base_url ),
				'label' => __( 'Appearance', 'core-blueprint' ),
				'desc'  => __( 'Your preferred admin theme - Dark, Light, or Auto. Sets your personal view; site default is separate.', 'core-blueprint' ),
				'icon'  => 'art',
			],
			[
				'slug'  => 'floating-menu',
				'url'   => add_query_arg( 'tab', 'floating-menu', $base_url ),
				'label' => __( 'Floating Menu', 'core-blueprint' ),
				'desc'  => __( 'Choose which HUD sections and shortcuts are shown, change their order, and add site-specific custom links.', 'core-blueprint' ),
				'icon'  => 'menu',
			],
			[
				'slug'  => 'admin-navigation',
				'url'   => add_query_arg( 'tab', 'admin-navigation', $base_url ),
				'label' => __( 'Admin Navigation', 'core-blueprint' ),
				'desc'  => __( 'Manage presentation of the native WordPress admin menu and Toolbar without changing page access or capabilities.', 'core-blueprint' ),
				'icon'  => 'menu',
			],
			[
				'slug'  => 'routing',
				'url'   => add_query_arg( 'tab', 'routing', $base_url ),
				'label' => __( 'Routing & URLs', 'core-blueprint' ),
				'desc'  => __( 'Review public URL routing and optionally use clean category archive URLs with compact p{n} pagination.', 'core-blueprint' ),
				'icon'  => 'settings',
			],
		];


		// Reports card - visible to operators with branding OR reports cap.
		// Same visibility rule as the tab itself in render().
		if ( current_user_can( 'cb_manage_branding' ) || current_user_can( 'cb_manage_reports' ) ) {
			$tab_cards[] = [
				'slug'  => 'reports',
				'url'   => add_query_arg( 'tab', 'reports', $base_url ),
				'label' => __( 'Reports', 'core-blueprint' ),
				'desc'  => __( 'Report appearance and optional provider details. Report content is stored independently as an immutable snapshot.', 'core-blueprint' ),
				'icon'  => 'art',
			];
		}

		// Notes preferences - same gating as the tab itself.
		if ( current_user_can( 'cb_manage_notes' ) ) {
			$tab_cards[] = [
				'slug'  => 'notes',
				'url'   => add_query_arg( 'tab', 'notes', $base_url ),
				'label' => __( 'Notes', 'core-blueprint' ),
				'desc'  => __( 'Defaults for new notes: type, status, assignment, layout, and whether the details panel opens by default.', 'core-blueprint' ),
				'icon'  => 'edit-page',
			];
		}

		// CLI documentation card - operator-only. Pure reference: list of
		// `wp cb` commands with examples and copy-buttons, plus a setup
		// note for hosts where WP-CLI requires a tweak.
		if ( current_user_can( 'cb_use_cli' ) ) {
			$tab_cards[] = [
				'slug'  => 'cli',
				'url'   => add_query_arg( 'tab', 'cli', $base_url ),
				'label' => __( 'CLI', 'core-blueprint' ),
				'desc'  => __( 'Reference for the wp cb command-line tool: every available command, copy-ready examples, and host-specific setup notes for activating WP-CLI.', 'core-blueprint' ),
				'icon'  => 'editor-code',
			];
		}

		// Permissions card - view-cap required, hide-toggle respected.
		// Same visibility rule as the tab itself in render().
		$can_view_permissions   = current_user_can( 'cb_view_permissions' );
		$can_manage_permissions = current_user_can( 'cb_manage_permissions' );
		$hide_active            = ! empty( \CB\Core\Settings::get()['permissions']['hide_from_admins'] );

		if ( $can_view_permissions && ( ! $hide_active || $can_manage_permissions ) ) {
			$tab_cards[] = [
				'slug'  => 'permissions',
				'url'   => add_query_arg( 'tab', 'permissions', $base_url ),
				'label' => __( 'Permissions', 'core-blueprint' ),
				'desc'  => __( 'Who may configure Core Blueprint. Operators can change all CB settings, generate reports and adjust branding; administrators see status only unless explicitly granted.', 'core-blueprint' ),
				'icon'  => 'admin-users',
			];
		}

		// About - always last, naslag.
		$tab_cards[] = [
			'slug'  => 'about',
			'url'   => add_query_arg( 'tab', 'about', $base_url ),
			'label' => __( 'About', 'core-blueprint' ),
			'desc'  => __( 'Version information, credits, and links to the Core Blueprint documentation and support channels.', 'core-blueprint' ),
			'icon'  => 'info',
		];

		ob_start();
		Overview::render( [
			'title' => __( 'Overview', 'core-blueprint' ),
			'intro' => __( 'Your personal, site-wide, module-specific, and governance preferences: how data is handled, which admin experience you see, and who may configure the system. Module activation is managed from the Dashboard.', 'core-blueprint' ),

			'status_cards' => [
				[
					'label'  => __( 'Admin theme', 'core-blueprint' ),
					'value'  => $current_theme_label !== '' ? $current_theme_label : __( '-', 'core-blueprint' ),
					'detail' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( add_query_arg( 'tab', 'appearance', $base_url ) ),
						esc_html__( 'Change →', 'core-blueprint' )
					),
				],
				[
					'label'  => __( 'Language', 'core-blueprint' ),
					'value'  => $current_locale_label !== '' ? $current_locale_label : __( '-', 'core-blueprint' ),
					'detail' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( add_query_arg( 'tab', 'language', $base_url ) ),
						esc_html__( 'Change →', 'core-blueprint' )
					),
				],
				[
					'label'  => __( 'Description style', 'core-blueprint' ),
					'value'  => $current_desc_label !== '' ? $current_desc_label : __( '-', 'core-blueprint' ),
					'detail' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( add_query_arg( 'tab', 'language', $base_url ) ),
						esc_html__( 'Change →', 'core-blueprint' )
					),
				],
			],

			'tab_cards' => $tab_cards,
		] );
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_privacy_tab( string $tab, array $tab_labels ): void {
		ob_start();
		Privacy::render_body();
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_notifications_tab( string $tab, array $tab_labels ): void {
		$settings       = \CB\Core\Settings::get();
		$email_override = (string) ( $settings['audit']['email_recipient'] ?? '' );
		$email_alerts   = $settings['audit']['email_alerts'] ?? [];
		$admin_email    = (string) get_option( 'admin_email', '' );

		// v1.1+ per-group recipients + event-type toggles. Each group's
		// recipient is an optional override; an empty value falls back to
		// the audit-tab override above, then to admin_email. The event
		// toggles are individual booleans the user can flip per event type.
		$permissions_recipient = (string) ( $settings['permissions']['email_recipient'] ?? '' );
		$permissions_alerts    = $settings['permissions']['email_alerts'] ?? [];
		$can_manage_permissions_notifications = current_user_can( 'cb_manage_permissions' );
		$reports_recipient     = (string) ( $settings['reports']['email_recipient'] ?? '' );
		$reports_alerts        = $settings['reports']['email_alerts'] ?? [];
		$integrity_recipient   = (string) ( $settings['integrity']['email_recipient'] ?? '' );
		$integrity_alerts      = $settings['integrity']['email_alerts'] ?? [];

		ob_start();
		include CB_CORE_DIR . 'templates/notifications.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_appearance_tab( string $tab, array $tab_labels ): void {
		$themes        = Themes::all();
		$current_theme = Themes::current();
		$user_pref     = Themes::user_preference();
		$site_default  = Themes::site_default();

		ob_start();
		include CB_CORE_DIR . 'templates/appearance.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_language_tab( string $tab, array $tab_labels ): void {
		$allowed      = Locale::allowed();
		$current      = Locale::current();
		$user_pref    = Locale::user_preference();
		$site_default = Locale::site_default();
		$desc_current = class_exists( UI::class ) ? UI::current_mode() : 'plain';
		$desc_user    = class_exists( UI::class ) ? UI::current_user_preference() : '';
		$desc_site    = class_exists( UI::class ) ? UI::site_default_mode() : 'plain';

		ob_start();
		include CB_CORE_DIR . 'templates/language.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Render the site-wide Floating Menu editor. The registry remains the
	 * authoritative content catalog; this surface only edits presentation
	 * overrides and administrator-defined custom links.
	 */
	private function render_floating_menu_tab( string $tab, array $tab_labels ): void {
		if ( ! current_user_can( MenuPreferences::manage_capability() ) ) {
			$this->render_subsystem_missing( __( 'You do not have permission to manage the floating menu.', 'core-blueprint' ) );
			return;
		}

		MenuPreferences::ensure_registry();
		$config          = MenuPreferences::get();
		$hidden_sections = array_fill_keys( (array) $config['hidden_sections'], true );
		$hidden_items    = array_fill_keys( (array) $config['hidden_items'], true );
		$custom_ids      = [];
		foreach ( (array) $config['custom_items'] as $custom_item ) {
			if ( is_array( $custom_item ) && ! empty( $custom_item['id'] ) ) {
				$custom_ids[ (string) $custom_item['id'] ] = true;
			}
		}

		$sections = MenuPreferences::order_sections( HudRegistry::catalog_sections() );
		foreach ( array_keys( $sections ) as $section_id ) {
			if ( ! MenuPreferences::is_manageable_section( (string) $section_id ) ) {
				unset( $sections[ $section_id ] );
				continue;
			}
			$sections[ $section_id ]['items'] = MenuPreferences::order_items(
				(string) $section_id,
				HudRegistry::catalog_items_for_section( (string) $section_id )
			);
		}

		$notice = isset( $_GET['hud_menu_notice'] ) ? sanitize_key( wp_unslash( $_GET['hud_menu_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect notice only.

		ob_start();
		include CB_CORE_DIR . 'templates/preferences-floating-menu.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}


	/** Render site-wide native WordPress Admin Navigation presentation policy. */
	private function render_admin_navigation_tab( string $tab, array $tab_labels ): void {
		if ( ! AdminNavigationAdmin::can_manage() ) {
			$this->render_subsystem_missing( __( 'You do not have permission to manage Admin Navigation.', 'core-blueprint' ) );
			return;
		}

		$state  = AdminNavigationAdmin::editor_state();
		$notice = isset( $_GET['admin_navigation_notice'] )
			? sanitize_key( wp_unslash( $_GET['admin_navigation_notice'] ) )
			: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect notice only.

		ob_start();
		include CB_CORE_DIR . 'templates/preferences-admin-navigation.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Render site-wide public URL Governance policy. */
	private function render_routing_tab( string $tab, array $tab_labels ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->render_subsystem_missing( __( 'You do not have permission to manage Routing & URLs.', 'core-blueprint' ) );
			return;
		}

		$routing_enabled        = \CB\Core\Routing\Policy::enabled();
		$routing_runtime_active = \CB\Core\Routing\Runtime::is_active();
		$routing_preflight     = $routing_enabled
			? \CB\Core\Routing\Preflight::run()
			: \CB\Core\Routing\Admin::stored_preflight();
		$routing_state         = isset( $_GET['routing_state'] )
			? sanitize_key( wp_unslash( (string) $_GET['routing_state'] ) )
			: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect status only.

		$routes          = \CB\Core\Routing\CategoryRoutes::all();
		$example_term    = [] !== $routes ? reset( $routes ) : null;
		$routing_example = $example_term instanceof \WP_Term
			? trim( \CB\Core\Routing\CategoryRoutes::path( $example_term ), '/' )
			: 'blog';
		$category_base   = \CB\Core\Routing\CategoryRoutes::category_base_path();

		ob_start();
		include CB_CORE_DIR . 'templates/preferences-routing.php';
		$html = (string) ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Render the Notes tab - Notes preferences (modal defaults: type,
	 * status, assignment, layout, details-panel state). The actual
	 * markup is rendered by {@see \CB\Core\Notes\Admin\PreferencesPage::render_body()}
	 * - that class also owns the form's POST handling, exposed here
	 * via maybe_handle_post() so any save notice is rendered above the
	 * form before it draws.
	 *
	 * Cap-gated upstream in render() - only users with cb_manage_notes
	 * see this tab. The gate inside maybe_handle_post() is a defensive
	 * second check.
	 */
	private function render_notes_tab( string $tab, array $tab_labels ): void {
		if ( ! class_exists( '\CB\Core\\Notes\\Admin\\PreferencesPage' ) ) {
			$this->render_subsystem_missing( __( 'Notes subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		$notice = \CB\Core\Notes\Admin\PreferencesPage::maybe_handle_post();

		ob_start();
		?>
		<div class="wrap cb-core-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Notes preferences', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro">
				<?php esc_html_e( 'Defaults applied when creating a new note. Existing notes are unaffected.', 'core-blueprint' ); ?>
			</p>
			<?php if ( $notice ) : ?>
				<?php
				echo \CB\Core\UI\Notice::render( [
					'variant' => 'error' === $notice['type'] ? \CB\Core\UI\Notice::ERROR : \CB\Core\UI\Notice::SUCCESS,
					'message' => $notice['message'],
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
				?>
			<?php endif; ?>
			<?php \CB\Core\Notes\Admin\PreferencesPage::render_body(); ?>
		</div>
		<?php
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Render the CLI documentation tab - operator-only reference for the
	 * `wp cb` command surface.
	 *
	 * Pure documentation in 1.5.0-dev: every command from the
	 * Registry::commands() list with a one-line description, a copy-ready
	 * example, and a setup note covering common host-specific quirks
	 * (Cloud86 `wp` shim, restricted shells, etc.). No execution surface
	 * - that lands in the in-browser terminal emulator (Phase 2).
	 *
	 * Cap-gated upstream in render() - only users with cb_use_cli reach
	 * this method.
	 */
	private function render_cli_tab( string $tab, array $tab_labels ): void {
		ob_start();
		include CB_CORE_DIR . 'templates/preferences-cli.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_about_tab( string $tab, array $tab_labels ): void {
		if ( ! function_exists( 'core_blueprint_about_page' ) ) {
			require_once CB_CORE_DIR . 'includes/cb-about-page.php';
		}
		ob_start();
		core_blueprint_about_page();
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Render the Report Branding tab - operator-only configuration of the
	 * appearance and optional provider details applied when PDF reports are rendered
	 * (logo, accent colour, provider name, provider contact). Branding is stored under reports.branding.*
	 * and remains separate from immutable report-content snapshots.
	 */
	private function render_reports_tab( string $tab, array $tab_labels ): void {
		if ( ! class_exists( '\CB\Core\\Reports\\ReportBranding' ) ) {
			$this->render_subsystem_missing( __( 'Reports subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		// Read branding straight from Settings, not via ReportBranding::current().
		// current() returns a renderable branding shape with the logo URL resolved,
		// but
		// strips logo_attachment_id - the form needs the raw stored ID to
		// populate the hidden input correctly. Use the raw stored shape
		// here, fall back to the raw Reports branding defaults for missing keys.
		$settings    = \CB\Core\Settings::get();
		$branding    = is_array( $settings['reports']['branding'] ?? null )
			? $settings['reports']['branding']
			: [];
		$fallback    = \CB\Core\Reports\ReportBranding::settings_defaults();
		$nonce       = wp_create_nonce( 'cb_core_admin' );
		$is_enabled  = class_exists( '\CB\Core\\Reports\\State' )
			? \CB\Core\Reports\State::is_enabled()
			: true;

		// Resolve the logo attachment for the upload widget. Holds an
		// attachment ID rather than a URL so the Media Library handles
		// resizing, alt text, and replacement reuse. Uses the size-fallback
		// helper so attachments without a 'medium' variant still render.
		$logo_attachment_id = (int) ( $branding['logo_attachment_id'] ?? 0 );
		$logo_url           = '';
		$logo_alt           = '';
		if ( $logo_attachment_id > 0 ) {
			$logo_url = \CB\Core\Reports\ReportBranding::attachment_url( $logo_attachment_id, 'medium' );
			$logo_alt = (string) get_post_meta( $logo_attachment_id, '_wp_attachment_image_alt', true );
		}

		ob_start();
		include CB_CORE_DIR . 'templates/preferences-reports.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Render the Permissions tab. Only reached when the visibility check
	 * in render() has already cleared (cb_view_permissions present, hide-
	 * toggle either off or current user is an operator).
	 *
	 * Permissions is meta-governance - who may configure CB itself, who
	 * sees this tab, and which CB actions administrators may perform
	 * without the operator role. Lives under Preferences (not Safeguards)
	 * because configuring access is a personal/site preference, not a
	 * hardening layer in the threat model.
	 */
	private function render_permissions_tab( string $tab, array $tab_labels ): void {
		if ( ! class_exists( '\CB\Core\\Permissions\\Roles' ) ) {
			$this->render_subsystem_missing( __( 'Permissions subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		// Resolve everything the template needs so the template stays
		// presentation-only.
		$settings    = \CB\Core\Settings::get();
		$hide_active = ! empty( $settings['permissions']['hide_from_admins'] );
		$admin_can_generate_maintenance = ! empty(
			$settings['reports']['admin_can_generate']['maintenance'] ?? false
		);
		$admin_can_run_integrity = ! empty(
			$settings['integrity']['admin_can_run'] ?? false
		);

		// Promotion candidates: every administrator (including the current
		// user). Operator-only users without administrator are NOT shown
		// here - they're already operators by virtue of having the role.
		$candidates = get_users( [
			'role'    => 'administrator',
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => [ 'ID', 'user_login', 'user_email', 'display_name' ],
		] );

		$current_operator_ids = \CB\Core\Permissions\Roles::operator_ids();
		$current_user_id      = get_current_user_id();
		$can_manage           = current_user_can( 'cb_manage_permissions' );
		$nonce                = wp_create_nonce( 'cb_core_admin' );

		ob_start();
		include CB_CORE_DIR . 'templates/permissions.php';
		$html = ob_get_clean();
		echo $this->inject_tab_nav( $html, self::SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
