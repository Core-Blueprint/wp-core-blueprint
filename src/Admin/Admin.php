<?php
declare(strict_types=1);
/**
 * Core Blueprint admin infrastructure.
 *
 * Owns the parent menu and Base page registration. Page rendering and admin
 * asset resolution are delegated to their dedicated services.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin;

use CB\Core\Admin\Pages\Dashboard;
use CB\Core\Admin\Pages\Logs;
use CB\Core\Admin\Pages\Preferences;
use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Admin\Pages\Settings as SettingsPage;
use CB\Core\AdminNotices\Admin as AdminNoticesAdmin;
use CB\Core\AdminNotices\Capabilities as AdminNoticesCapabilities;
use CB\Core\Compliance\Admin\Page as CompliancePage;
use CB\Core\UI\AdminTheme;
use CB\Core\UI\AdminThemeAdapters;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const MENU_SLUG        = 'core-blueprint';
	const LOGS_SLUG        = 'core-blueprint-logs';
	const SAFEGUARDS_SLUG  = 'core-blueprint-safeguards';
	const SETTINGS_SLUG    = 'core-blueprint-settings';
	const PREFERENCES_SLUG = 'core-blueprint-preferences';
	const CONSOLE_SLUG     = 'core-blueprint-console';

	public static function init(): void {
		AdminTheme::init();
		AdminThemeAdapters::init();
		MenuGroupRegistry::init();
		add_action( 'admin_menu', [ __CLASS__, 'register_parent_menu' ], 5 );
		add_action( 'admin_menu', [ __CLASS__, 'remove_duplicate_submenu' ], 999 );
		add_action( 'cb_core_register_pages', [ __CLASS__, 'register_foundation_pages' ] );
	}

	/** Register the Core Blueprint top-level menu if no sibling already did. */
	public static function register_parent_menu(): void {
		global $menu;

		$parent_exists = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && CB_CORE_PARENT_MENU === $item[2] ) {
					$parent_exists = true;
					break;
				}
			}
		}

		if ( $parent_exists ) {
			return;
		}

		$menu_capability = (string) apply_filters(
			'cb_core_menu_capability',
			self::parent_menu_capability()
		);

		$hook = add_menu_page(
			'Core Blueprint',
			'Core Blueprint',
			/**
			 * Filter: cb_core_menu_capability
			 *
			 * The capability required to see the Core Blueprint parent menu.
			 * Defaults to manage_options, or the delegated Admin Notices capability
			 * for an approved manager who intentionally lacks manage_options.
			 */
			$menu_capability,
			CB_CORE_PARENT_MENU,
			[ __CLASS__, 'render_parent_landing' ],
			self::get_menu_icon(),
			81
		);

		if ( $hook && ! current_user_can( 'manage_options' ) && AdminNoticesAdmin::can_manage() ) {
			add_action( 'load-' . $hook, [ __CLASS__, 'redirect_delegated_parent_landing' ] );
		}
	}

	/** Capability used for the parent menu for the current user. */
	public static function parent_menu_capability(): string {
		if ( current_user_can( 'manage_options' ) ) {
			return 'manage_options';
		}

		return AdminNoticesAdmin::can_manage()
			? AdminNoticesCapabilities::MANAGE
			: 'manage_options';
	}

	/**
	 * Keep delegated Admin Notices managers out of the Dashboard surface.
	 *
	 * WordPress may route a parent-menu click through the auto-generated
	 * top-level submenu item. Redirect before admin output starts so the
	 * delegated user lands on the only Preferences tab they are allowed to use.
	 */
	public static function redirect_delegated_parent_landing(): void {
		if ( current_user_can( 'manage_options' ) || ! AdminNoticesAdmin::can_manage() ) {
			return;
		}

		wp_safe_redirect(
			admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=admin-notices' )
		);
		exit;
	}

	/** Render the dashboard when the top-level parent entry is selected. */
	public static function render_parent_landing(): void {
		if ( class_exists( Dashboard::class ) ) {
			$dashboard = new Dashboard();
			$dashboard->render();
			return;
		}

		echo '<div class="wrap"><h1>Core Blueprint</h1><p>';
		esc_html_e( 'Dashboard not available.', 'core-blueprint' );
		echo '</p></div>';
	}

	/** Register Base-owned submenu pages through the canonical page registry. */
	public static function register_foundation_pages(): void {
		PageRegistry::register_base( new Logs() );
		PageRegistry::register_base( new Safeguards() );

		PageRegistry::register_base(
			new CompliancePage(),
			[
				'foundations' => [ 'object-picker', 'toast' ],
				'components'  => [ 'fields', 'form-controls', 'disclosure', 'badges', 'state-badges', 'notices' ],
			]
		);

		$provider_requirements = SettingsRegistry::selected_requirements();
		PageRegistry::register_base(
			new SettingsPage(),
			[
				'foundations' => $provider_requirements['foundations'],
				'components'  => array_values( array_unique( array_merge(
					[ 'overview', 'cards', 'badges', 'empty-state' ],
					$provider_requirements['components']
				) ) ),
			]
		);

		PageRegistry::register_base(
			new Preferences(),
			[
				'components' => [ 'cards', 'fields', 'form-controls', 'kv-table', 'notices', 'status' ],
			]
		);
	}

	/** Normalize the auto-generated parent submenu and remove obsolete theme UI. */
	public static function remove_duplicate_submenu(): void {
		global $submenu;

		if ( isset( $submenu[ CB_CORE_PARENT_MENU ] ) ) {
			foreach ( $submenu[ CB_CORE_PARENT_MENU ] as $i => $item ) {
				if ( isset( $item[2] ) && CB_CORE_PARENT_MENU === $item[2] ) {
					$submenu[ CB_CORE_PARENT_MENU ][ $i ][0] = __( 'Dashboard', 'core-blueprint' );
					if ( isset( $submenu[ CB_CORE_PARENT_MENU ][ $i ][3] ) ) {
						$submenu[ CB_CORE_PARENT_MENU ][ $i ][3] = __( 'Dashboard', 'core-blueprint' );
					}
					break;
				}
			}
		}

		remove_submenu_page( CB_CORE_PARENT_MENU, 'core-blueprint-site-mode' );
	}

	private static function get_menu_icon(): string {
		$icon_path = CB_CORE_DIR . 'assets/core-blueprint-icon.svg';
		if ( ! file_exists( $icon_path ) ) {
			return 'dashicons-shield-alt';
		}

		$svg = file_get_contents( $icon_path );
		if ( false === $svg ) {
			return 'dashicons-shield-alt';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
