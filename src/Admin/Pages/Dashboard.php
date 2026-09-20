<?php
declare(strict_types=1);
/**
 * Dashboard - the Core Blueprint landing page.
 *
 * The dashboard is the Core Blueprint navigation-and-health cockpit. It may
 * expose compact activation actions for optional Base modules, while detailed
 * configuration remains on the owning module or Preferences page. Sections:
 *
 *   1. Safeguards   - five Base health cards (Access Mode, Login Shield,
 *                     Core Shield, Core Scanner, Failsafe).
 *                     Each card shows live state + one factual line and
 *                     deeplinks to the relevant Safeguards tab.
 *   2. Operations   - Logs, Notes, Reports, Core Profiles.
 *   3. CMS Tools    - first-party CMS baseline modules such as User Roles,
 *                     Media Replace, and Package Downloads.
 *   4. Preferences  - navigation cards mirroring the available Preferences tabs,
 *                     each deeplinked to its tab.
 *   5. Extensions   - sibling CB plugins detected on the site, with
 *                     active/version state.
 *
 * Footer card: About - full-width, marks itself as suite-meta rather
 * than a regular preference.
 *
 * The page itself is a pure consumer. All status data comes from
 * \CB\Core\Modules\Status::many(); per-module logic lives in the
 * canonical status providers owned by their subsystems.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin\Pages;

use CB\Core\Admin\Admin;
use CB\Core\Admin\PageBase;
use CB\Core\ContentModels\Admin\Page as ContentModelsPage;
use CB\Core\ContentModels\State as ContentModelsState;
use CB\Core\Dashboard\CardRegistry as DashboardCardRegistry;
use CB\Core\ExtensionLifecycle;
use CB\Core\Extensions;
use CB\Core\ExtensionRegistry;
use CB\Core\MediaReplace\Admin\Page as MediaReplacePage;
use CB\Core\MediaReplace\Capabilities as MediaReplaceCapabilities;
use CB\Core\MediaFormats\Admin\Page as MediaFormatsPage;
use CB\Core\MediaFormats\State as MediaFormatsState;
use CB\Core\Mail\Admin\Page as MailPage;
use CB\Core\Mail\State as MailState;
use CB\Core\Mail\Runtime as MailRuntime;
use CB\Core\Mail\Settings as MailSettings;
use CB\Core\Modules\ActivationRegistry;
use CB\Core\PackageDownload\Admin\Page as PackageDownloadPage;
use CB\Core\MediaReplace\State as MediaReplaceState;
use CB\Core\PackageDownload\State as PackageDownloadState;
use CB\Core\Permissions\Admin\RolesPage;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\UserRolesState;
use CB\Core\Modules\Status;
use CB\Core\Security\AccessMode;
use CB\Core\Snippets\Admin\Page as SnippetsPage;
use CB\Core\Snippets\State as SnippetsState;
use CB\Core\UI\StatusMenu;

defined( 'ABSPATH' ) || exit;

final class Dashboard extends PageBase {

	const SLUG = 'core-blueprint';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Core Blueprint', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Dashboard', 'core-blueprint' );
	}

	public function position(): ?int {
		return 10;
	}

	public function render(): void {
		$this->guard();

		// Safeguards - live status for every module. The helper is fully
		// defensive; the template can render values directly without
		// re-validating.
		$safeguards = Status::many( [ 'access-mode', 'login-shield', 'core-shield', 'core-scanner', 'failsafe' ] );
		$safeguards = self::attach_safeguard_status_menus( $safeguards );

		// Extensions - sibling CB plugins discovered at runtime.
		$extensions = Extensions::detected();

		// Pre-compute deeplinks for the Operations + Preferences sections.
		// Centralised here so the template stays free of admin_url() calls
		// and capability checks.
		$logs_slug        = Admin::LOGS_SLUG;
		$preferences_slug = Admin::PREFERENCES_SLUG;
		$notes_slug       = 'core-blueprint-notes';
		$reports_slug     = 'core-blueprint-reports';
		$user_roles_slug     = RolesPage::SLUG;
		$media_replace_slug    = MediaReplacePage::SLUG;
		$media_formats_slug    = MediaFormatsPage::SLUG;
		$mail_slug             = MailPage::SLUG;
		$package_download_slug = PackageDownloadPage::SLUG;
		$snippets_slug         = SnippetsPage::SLUG;
		$content_models_slug    = ContentModelsPage::SLUG;

		// Optional-module state for managed Dashboard cards. Disabled modules stay
		// visible here for discoverability while their functional pages hide; the
		// compact status menu is the single activation surface. Logs has no module
		// activation state and remains a regular navigation card.
		$notes_enabled            = class_exists( '\CB\Core\Notes\State' ) ? \CB\Core\Notes\State::is_enabled() : true;
		$reports_enabled          = class_exists( '\CB\Core\Reports\State' ) ? \CB\Core\Reports\State::is_enabled() : true;
		$user_roles_enabled       = UserRolesState::is_enabled();
		$media_replace_enabled    = MediaReplaceState::is_enabled();
		$media_formats_enabled    = MediaFormatsState::is_enabled();
		$package_download_enabled = PackageDownloadState::is_enabled();
		$snippets_enabled         = SnippetsState::is_enabled();
		$content_models_enabled    = ContentModelsState::is_enabled();
		$mail_enabled              = MailState::is_enabled();
		$mail_runtime_active       = MailRuntime::is_active();

		$disabled_meta = __( 'Disabled', 'core-blueprint' );

		$operations_cards = [
			[
				'id'    => 'logs',
				'title' => __( 'Logs',    'core-blueprint' ),
				'meta'  => __( 'Audit trail of all CB events',    'core-blueprint' ),
				'url'   => admin_url( 'admin.php?page=' . $logs_slug . '&tab=audit' ),
			],
			[
				'id'              => 'notes',
				'title'           => __( 'Notes', 'core-blueprint' ),
				'meta'            => $notes_enabled ? __( 'Site-bound notes for handover', 'core-blueprint' ) : $disabled_meta,
				'url'             => $notes_enabled ? admin_url( 'admin.php?page=' . $notes_slug ) : '',
				'visit_url'       => admin_url( 'admin.php?page=' . $notes_slug ),
				'preferences_url' => add_query_arg( 'tab', 'notes', admin_url( 'admin.php?page=' . $preferences_slug ) ),
				'enabled'         => $notes_enabled,
				'state'           => $notes_enabled ? 'ok' : 'off',
			],
			[
				'id'              => 'reports',
				'title'           => __( 'Reports', 'core-blueprint' ),
				'meta'            => $reports_enabled ? __( 'Maintenance reports for clients', 'core-blueprint' ) : $disabled_meta,
				'url'             => $reports_enabled ? admin_url( 'admin.php?page=' . $reports_slug ) : '',
				'visit_url'       => admin_url( 'admin.php?page=' . $reports_slug ),
				'preferences_url' => add_query_arg( 'tab', 'reports', admin_url( 'admin.php?page=' . $preferences_slug ) ),
				'enabled'         => $reports_enabled,
				'state'           => $reports_enabled ? 'ok' : 'off',
			],
		];

		$dashboard_user = wp_get_current_user();
		if (
			current_user_can( 'cb_manage_permissions' )
			&& $dashboard_user instanceof \WP_User
			&& PrivilegedAccessGuard::is_trusted_operator( $dashboard_user )
		) {
			$operations_cards[] = [
				'id'    => 'core-profiles',
				'title' => __( 'Core Profiles', 'core-blueprint' ),
				'meta'  => __( 'Transfer governed Base configuration between sites', 'core-blueprint' ),
				'url'   => admin_url( 'admin.php?page=' . Profiles::SLUG ),
			];
		}

		// CMS Tools - first-party modules that fill gaps in WordPress' CMS
		// baseline. Keep these separate from operational records/workflows so
		// the dashboard doubles as a discoverable inventory of Base features.
		$cms_tools_cards = [];

		if ( current_user_can( 'cb_manage_content_models' ) ) {
			$cms_tools_cards[] = [
				'id'    => 'content-models',
				'title' => __( 'Content Models', 'core-blueprint' ),
				'meta'  => $content_models_enabled
					? __( 'Custom post types and taxonomies', 'core-blueprint' )
					: $disabled_meta,
				'url'       => $content_models_enabled ? admin_url( 'admin.php?page=' . $content_models_slug ) : '',
				'visit_url' => admin_url( 'admin.php?page=' . $content_models_slug ),
				'enabled'   => $content_models_enabled,
				'state'     => $content_models_enabled ? 'ok' : 'off',
			];
		}

		if ( current_user_can( 'cb_manage_snippets' ) ) {
			$cms_tools_cards[] = [
				'id'    => 'snippets',
				'title' => __( 'Snippets', 'core-blueprint' ),
				'meta'  => $snippets_enabled
					? __( 'Managed PHP, CSS, JavaScript and HTML snippets', 'core-blueprint' )
					: $disabled_meta,
				'url'       => $snippets_enabled ? admin_url( 'admin.php?page=' . $snippets_slug ) : '',
				'visit_url' => admin_url( 'admin.php?page=' . $snippets_slug ),
				'enabled'   => $snippets_enabled,
				'state'     => $snippets_enabled ? 'ok' : 'off',
			];
		}

		if ( current_user_can( 'cb_manage_roles' ) ) {
			$cms_tools_cards[] = [
				'id'    => 'user-roles',
				'title' => __( 'User Roles', 'core-blueprint' ),
				'meta'  => $user_roles_enabled
					? __( 'WordPress roles and capabilities', 'core-blueprint' )
					: $disabled_meta,
				'url'       => $user_roles_enabled ? admin_url( 'admin.php?page=' . $user_roles_slug ) : '',
				'visit_url' => admin_url( 'admin.php?page=' . $user_roles_slug ),
				'enabled'   => $user_roles_enabled,
				'state'     => $user_roles_enabled ? 'ok' : 'off',
			];
		}

		if ( current_user_can( 'manage_options' ) ) {
			$cms_tools_cards[] = [
				'id'    => 'media-formats',
				'title' => __( 'Media Formats', 'core-blueprint' ),
				'meta'  => $media_formats_enabled
					? __( 'Modern image formats and secure SVG uploads', 'core-blueprint' )
					: $disabled_meta,
				'url'       => $media_formats_enabled ? admin_url( 'admin.php?page=' . $media_formats_slug ) : '',
				'visit_url' => admin_url( 'admin.php?page=' . $media_formats_slug ),
				'enabled'   => $media_formats_enabled,
				'state'     => $media_formats_enabled ? 'ok' : 'off',
			];
		}

		if ( current_user_can( MediaReplaceCapabilities::MANAGE_MEDIA_REPLACE ) ) {
			$cms_tools_cards[] = [
				'id'    => 'media-replace',
				'title' => __( 'Media Replace', 'core-blueprint' ),
				'meta'  => $media_replace_enabled
					? __( 'Replace media without changing its identity', 'core-blueprint' )
					: $disabled_meta,
				'url'       => $media_replace_enabled && current_user_can( 'upload_files' ) ? admin_url( 'admin.php?page=' . $media_replace_slug ) : '',
				'visit_url' => current_user_can( 'upload_files' ) ? admin_url( 'admin.php?page=' . $media_replace_slug ) : '',
				'enabled'   => $media_replace_enabled,
				'state'     => $media_replace_enabled ? 'ok' : 'off',
			];
		}

		if ( current_user_can( 'manage_options' ) ) {
			$cms_tools_cards[] = [
				'id'    => 'mail',
				'title' => __( 'Mail', 'core-blueprint' ),
				'meta'  => ! $mail_enabled
					? $disabled_meta
					: ( $mail_runtime_active
						? sprintf( __( 'Outbound delivery via %s', 'core-blueprint' ), MailSettings::provider_label() )
						: __( 'Enabled - runtime requires attention', 'core-blueprint' ) ),
				'url'         => $mail_enabled ? admin_url( 'admin.php?page=' . $mail_slug ) : '',
				'visit_url'   => admin_url( 'admin.php?page=' . $mail_slug ),
				'enabled'     => $mail_enabled,
				'menu_detail' => $mail_enabled && ! $mail_runtime_active ? __( 'Configuration required', 'core-blueprint' ) : '',
				'state'       => ! $mail_enabled ? 'off' : ( $mail_runtime_active ? 'ok' : 'warn' ),
			];
		}

		if ( current_user_can( 'manage_options' ) && ( current_user_can( 'install_plugins' ) || current_user_can( 'switch_themes' ) ) ) {
			$cms_tools_cards[] = [
				'id'    => 'package-downloads',
				'title' => __( 'Package Downloads', 'core-blueprint' ),
				'meta'  => $package_download_enabled
					? __( 'Download installed plugins and themes as ZIP files', 'core-blueprint' )
					: $disabled_meta,
				'url'       => $package_download_enabled ? admin_url( 'admin.php?page=' . $package_download_slug ) : '',
				'visit_url' => admin_url( 'admin.php?page=' . $package_download_slug ),
				'enabled'   => $package_download_enabled,
				'state'     => $package_download_enabled ? 'ok' : 'off',
			];
		}


		$operations_cards = self::attach_dashboard_shortcuts( $operations_cards, 'operation' );
		$cms_tools_cards  = self::attach_dashboard_shortcuts( $cms_tools_cards, 'module' );
		$operations_cards = self::attach_module_status_menus( $operations_cards );
		$cms_tools_cards  = self::attach_module_status_menus( $cms_tools_cards );

		// Extension cards use the canonical ExtensionRegistry ID as their stable
		// Dashboard Card identity. WordPress plugin_file remains inventory-only.
		foreach ( $extensions as $extension_id => &$extension ) {
			if ( ! is_array( $extension ) ) {
				continue;
			}
			$id = (string) ( $extension['id'] ?? $extension_id );
			if ( ! ExtensionRegistry::is_valid_id( $id ) ) {
				continue;
			}
			$extension['state']       = self::extension_dashboard_state( $extension );
			$extension['status_line'] = self::extension_status_line( $extension );
			$extension['shortcuts']   = DashboardCardRegistry::shortcuts( $id, [
				'type'      => 'extension',
				'active'    => ! empty( $extension['active'] ),
				'extension' => $extension,
			] );
			$extension['status_menu'] = self::extension_status_menu( $extension );
		}
		unset( $extension );

		// Preferences cards - mirror the tabs in render order. Report
		// Branding is operator-only, mirroring the tab visibility.
		$preferences_cards = [
			[
				'title' => __( 'Privacy',       'core-blueprint' ),
				'meta'  => __( 'Data retention and IP handling', 'core-blueprint' ),
				'url'   => add_query_arg( 'tab', 'privacy',       admin_url( 'admin.php?page=' . $preferences_slug ) ),
			],
			[
				'title' => __( 'Notifications', 'core-blueprint' ),
				'meta'  => __( 'Email recipients and triggers',  'core-blueprint' ),
				'url'   => add_query_arg( 'tab', 'notifications', admin_url( 'admin.php?page=' . $preferences_slug ) ),
			],
			[
				'title' => __( 'Permissions',   'core-blueprint' ),
				'meta'  => __( 'Operator role and admin toggles', 'core-blueprint' ),
				'url'   => add_query_arg( 'tab', 'permissions',   admin_url( 'admin.php?page=' . $preferences_slug ) ),
			],
			[
				'title' => __( 'Appearance',    'core-blueprint' ),
				'meta'  => __( 'Theme and dashboard density',     'core-blueprint' ),
				'url'   => add_query_arg( 'tab', 'appearance',    admin_url( 'admin.php?page=' . $preferences_slug ) ),
			],
			[
				'title' => __( 'Language',      'core-blueprint' ),
				'meta'  => __( 'Site-wide CB locale',             'core-blueprint' ),
				'url'   => add_query_arg( 'tab', 'language',      admin_url( 'admin.php?page=' . $preferences_slug ) ),
			],
		];


		if ( current_user_can( 'cb_manage_branding' ) || current_user_can( 'cb_manage_reports' ) ) {
			$preferences_cards[] = [
				'title' => __( 'Reports', 'core-blueprint' ),
				'meta'  => __( 'Branding and provider details for client reports', 'core-blueprint' ),
				'url'   => add_query_arg( 'tab', 'reports', admin_url( 'admin.php?page=' . $preferences_slug ) ),
			];
		}

		if ( current_user_can( 'cb_manage_notes' ) ) {
			$preferences_cards[] = [
				'title' => __( 'Notes', 'core-blueprint' ),
				'meta'  => __( 'Defaults for site-bound notes', 'core-blueprint' ),
				'url'   => add_query_arg( 'tab', 'notes', admin_url( 'admin.php?page=' . $preferences_slug ) ),
			];
		}

		$about_card = [
			'title' => __( 'About', 'core-blueprint' ),
			'meta'  => __( 'Suite overview, version, credits', 'core-blueprint' ),
			'url'   => add_query_arg( 'tab', 'about', admin_url( 'admin.php?page=' . $preferences_slug ) ),
		];

		include CB_CORE_DIR . 'templates/dashboard.php';
	}



	/**
	 * Attach Dashboard cockpit controls to the configurable Safeguards cards.
	 *
	 * Access Mode is a four-state policy rather than a binary module. Login
	 * Shield, Core Shield and Core Scanner use ActivationRegistry so Dashboard
	 * master activation shares the same canonical state as their runtimes.
	 * Failsafe intentionally remains a read-only health card because its bypass
	 * and recovery semantics are not a normal module activation boundary.
	 *
	 * @param array<string,array<string,mixed>> $cards
	 * @return array<string,array<string,mixed>>
	 */
	private static function attach_safeguard_status_menus( array $cards ): array {
		$binary_modules = [
			'login-shield' => 'login-shield',
			'core-shield'  => 'core-shield',
			'core-scanner' => 'core-scanner',
		];

		foreach ( $cards as $safeguard_id => &$card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}

			$card['title'] = (string) ( $card['label'] ?? '' );
			$card['meta']  = (string) ( $card['detail'] ?? '' );

			if ( 'access-mode' === $safeguard_id && class_exists( AccessMode::class ) ) {
				$current = AccessMode::current();
				$actions = [];
				if ( current_user_can( 'manage_options' ) ) {
					$mode_labels = [
						AccessMode::MODE_PUBLIC      => __( 'Public', 'core-blueprint' ),
						AccessMode::MODE_COMING_SOON => __( 'Coming Soon', 'core-blueprint' ),
						AccessMode::MODE_MAINTENANCE => __( 'Maintenance', 'core-blueprint' ),
						AccessMode::MODE_ADMIN_ONLY  => __( 'Admin-Only', 'core-blueprint' ),
					];
					foreach ( $mode_labels as $mode => $label ) {
						$actions[] = [
							'type'  => 'button',
							'label' => $label,
							'attrs' => [
								'data-cb-core-access-mode-action' => $mode,
								'aria-current' => $current === $mode ? 'true' : 'false',
							],
						];
					}
				}
				if ( ! empty( $card['url'] ) ) {
					$actions[] = [
						'type'  => 'link',
						'label' => __( 'Visit module', 'core-blueprint' ),
						'url'   => (string) $card['url'],
					];
				}
				$card['status_menu'] = StatusMenu::render( [
					'id'      => 'cb-dashboard-status-access-mode',
					'state'   => self::status_menu_state( (string) ( $card['state'] ?? 'off' ) ),
					'label'   => AccessMode::mode_label( $current ),
					'actions' => $actions,
				] );
				continue;
			}

			$module_id = $binary_modules[ $safeguard_id ] ?? '';
			if ( '' === $module_id ) {
				continue;
			}

			$definition = ActivationRegistry::definition( $module_id );
			if ( null === $definition ) {
				continue;
			}
			$state_class = $definition['state'];
			$enabled     = (bool) $state_class::is_enabled();
			$actions     = [];

			if ( current_user_can( $definition['capability'] ) ) {
				$actions[] = [
					'type'    => 'button',
					'label'   => $enabled ? __( 'Turn off', 'core-blueprint' ) : __( 'Turn on', 'core-blueprint' ),
					'variant' => $enabled ? 'danger' : 'default',
					'attrs'   => [
						'data-cb-core-module-action'  => $module_id,
						'data-cb-core-module-enabled' => $enabled ? '0' : '1',
					],
				];
			}
			if ( ! empty( $card['url'] ) ) {
				$actions[] = [
					'type'  => 'link',
					'label' => __( 'Visit module', 'core-blueprint' ),
					'url'   => (string) $card['url'],
				];
			}

			$card['status_menu'] = StatusMenu::render( [
				'id'      => 'cb-dashboard-status-' . sanitize_html_class( $module_id ),
				'state'   => self::status_menu_state( (string) ( $card['state'] ?? 'off' ) ),
				'label'   => $enabled ? __( 'On', 'core-blueprint' ) : __( 'Off', 'core-blueprint' ),
				'actions' => $actions,
			] );
		}
		unset( $card );

		return $cards;
	}

	/** Attach compact module activation/status menus to activation-registry cards. */
	private static function attach_module_status_menus( array $cards ): array {
		foreach ( $cards as &$card ) {
			if ( ! is_array( $card ) || ! array_key_exists( 'enabled', $card ) ) {
				continue;
			}

			$enabled    = (bool) $card['enabled'];
			$module_id  = sanitize_key( (string) ( $card['id'] ?? '' ) );
			$definition = '' !== $module_id ? ActivationRegistry::definition( $module_id ) : null;
			$actions    = [];

			// Activation remains capability-gated by the canonical registry. The
			// AJAX endpoint repeats this check server-side; hiding the action here
			// keeps the cockpit honest for custom manage_options roles.
			if ( is_array( $definition ) && current_user_can( $definition['capability'] ) ) {
				$actions[] = [
					'type'    => 'button',
					'label'   => $enabled ? __( 'Turn off', 'core-blueprint' ) : __( 'Turn on', 'core-blueprint' ),
					'variant' => $enabled ? 'danger' : 'default',
					'attrs'   => [
						'data-cb-core-module-action'  => $module_id,
						'data-cb-core-module-enabled' => $enabled ? '0' : '1',
					],
				];
			}

			if ( $enabled && ! empty( $card['visit_url'] ) ) {
				$actions[] = [
					'type'  => 'link',
					'label' => __( 'Visit module', 'core-blueprint' ),
					'url'   => (string) $card['visit_url'],
				];
			}

			if ( ! empty( $card['preferences_url'] ) ) {
				$actions[] = [
					'type'  => 'link',
					'label' => __( 'Module preferences', 'core-blueprint' ),
					'url'   => (string) $card['preferences_url'],
				];
			}

			foreach ( (array) ( $card['shortcuts'] ?? [] ) as $shortcut ) {
				if ( ! is_array( $shortcut ) ) {
					continue;
				}
				$actions[] = [
					'type'   => 'link',
					'label'  => (string) ( $shortcut['label'] ?? '' ),
					'url'    => (string) ( $shortcut['url'] ?? '' ),
					'target' => (string) ( $shortcut['target'] ?? '_self' ),
				];
			}

			$card['status_menu'] = StatusMenu::render( [
				'id'      => 'cb-dashboard-status-' . sanitize_html_class( (string) ( $card['id'] ?? '' ) ),
				'state'   => self::status_menu_state( (string) ( $card['state'] ?? 'off' ) ),
				'label'   => $enabled ? __( 'On', 'core-blueprint' ) : __( 'Off', 'core-blueprint' ),
				'detail'  => (string) ( $card['menu_detail'] ?? '' ),
				'actions' => $actions,
			] );
		}
		unset( $card );
		return $cards;
	}

	/** Build the Base-owned lifecycle/status menu for a sibling extension card. */
	private static function extension_status_menu( array $extension ): string {
		$active       = ! empty( $extension['active'] );
		$state        = self::extension_dashboard_state( $extension );
		$extension_id = sanitize_key( (string) ( $extension['id'] ?? '' ) );
		$capability   = '' !== $extension_id ? ExtensionLifecycle::capability( $extension_id ) : null;
		$actions      = [];

		if ( null !== $capability && current_user_can( $capability ) ) {
			$actions[] = [
				'type'    => 'button',
				'label'   => $active ? __( 'Turn off', 'core-blueprint' ) : __( 'Turn on', 'core-blueprint' ),
				'variant' => $active ? 'danger' : 'default',
				'attrs'   => [
					'data-cb-core-extension-action' => $extension_id,
					'data-cb-core-extension-active' => $active ? '0' : '1',
				],
			];
		}

		$menu_url = esc_url_raw( (string) ( $extension['menu_url'] ?? '' ) );
		if ( $active && '' !== $menu_url ) {
			$actions[] = [ 'type' => 'link', 'label' => __( 'Visit module', 'core-blueprint' ), 'url' => $menu_url ];
		} elseif ( ! $active || '' === $menu_url ) {
			$actions[] = [ 'type' => 'link', 'label' => __( 'Manage plugin', 'core-blueprint' ), 'url' => admin_url( 'plugins.php' ) ];
		}
		foreach ( (array) ( $extension['shortcuts'] ?? [] ) as $shortcut ) {
			if ( ! is_array( $shortcut ) ) {
				continue;
			}
			$actions[] = [
				'type'   => 'link',
				'label'  => (string) ( $shortcut['label'] ?? '' ),
				'url'    => (string) ( $shortcut['url'] ?? '' ),
				'target' => (string) ( $shortcut['target'] ?? '_self' ),
			];
		}

		return StatusMenu::render( [
			'id'      => 'cb-dashboard-status-' . sanitize_html_class( (string) ( $extension['id'] ?? 'extension' ) ),
			'state'   => $state,
			'label'   => $active ? __( 'On', 'core-blueprint' ) : __( 'Off', 'core-blueprint' ),
			'detail'  => self::extension_status_line( $extension ),
			'actions' => $actions,
		] );
	}

	/** Map extension inventory/health state to the existing Dashboard vocabulary. */
	private static function extension_dashboard_state( array $extension ): string {
		if ( empty( $extension['active'] ) ) {
			return 'inactive';
		}
		if ( empty( $extension['registered'] ) ) {
			return 'warning';
		}
		if ( false === ( $extension['compatible'] ?? null ) ) {
			return 'error';
		}

		return match ( (string) ( $extension['health'] ?? 'unknown' ) ) {
			'ok'   => 'active',
			'warn' => 'warning',
			'err'  => 'error',
			'off'  => 'idle',
			default => 'idle',
		};
	}

	/** Human-readable Dashboard detail without collapsing registry state. */
	private static function extension_status_line( array $extension ): string {
		if ( empty( $extension['active'] ) ) {
			return __( 'inactive', 'core-blueprint' );
		}
		if ( empty( $extension['registered'] ) ) {
			return __( 'Registration unavailable', 'core-blueprint' );
		}
		if ( false === ( $extension['compatible'] ?? null ) ) {
			return __( 'Incompatible with Core API', 'core-blueprint' );
		}

		$detail = trim( (string) ( $extension['health_detail'] ?? '' ) );
		if ( '' !== $detail ) {
			return $detail;
		}

		return match ( (string) ( $extension['health'] ?? 'unknown' ) ) {
			'ok'   => __( 'Healthy', 'core-blueprint' ),
			'warn' => __( 'Needs attention', 'core-blueprint' ),
			'err'  => __( 'Error', 'core-blueprint' ),
			'off'  => __( 'Feature inactive', 'core-blueprint' ),
			default => __( 'Status unavailable', 'core-blueprint' ),
		};
	}

	private static function status_menu_state( string $state ): string {
		return match ( $state ) {
			'ok'   => 'active',
			'warn' => 'warning',
			'err'  => 'error',
			default => 'inactive',
		};
	}

	/**
	 * Attach public Dashboard Card API shortcuts to cards with stable IDs.
	 *
	 * @param array<int,array<string,mixed>> $cards
	 * @return array<int,array<string,mixed>>
	 */
	private static function attach_dashboard_shortcuts( array $cards, string $type ): array {
		foreach ( $cards as &$card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$card_id = sanitize_key( (string) ( $card['id'] ?? '' ) );
			if ( '' === $card_id ) {
				continue;
			}
			$card['shortcuts'] = DashboardCardRegistry::shortcuts( $card_id, [
				'type'  => $type,
				'state' => (string) ( $card['state'] ?? '' ),
				'card'  => $card,
			] );
		}
		unset( $card );

		return $cards;
	}

}
