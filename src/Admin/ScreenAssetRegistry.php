<?php
declare(strict_types=1);
/**
 * Private Base-owned screen/view asset manifest.
 *
 * ScreenContext answers where the request is. This registry maps that normalized
 * context to private Base assets. Public extension requirements remain owned by
 * PageRegistry and never expose these IDs/handles.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class ScreenAssetRegistry {

	private const BASE_PAGES = [
		'core-blueprint',
		'core-blueprint-logs',
		'core-blueprint-reports',
		'core-blueprint-notes',
		'core-blueprint-user-roles',
		'core-blueprint-media-replace',
		'core-blueprint-package-downloads',
		'core-blueprint-mail',
		'core-blueprint-safeguards',
		'core-blueprint-preferences',
		'core-blueprint-console',
		'core-blueprint-media-formats',
		'core-blueprint-content-models',
		'core-blueprint-snippets',
	];

	/** Whether the normalized context belongs to a registered Core Admin screen. */
	public static function owns( ScreenContext $context ): bool {
		return '' !== $context->registered_slug()
			|| $context->hook() === 'toplevel_page_' . CB_CORE_PARENT_MENU;
	}

	/**
	 * Registered Base surfaces with open extension-tab registries retain the
	 * complete Base asset set for unknown tabs until those extension contracts
	 * can declare their semantic requirements directly.
	 */
	public static function requires_full_set( ScreenContext $context ): bool {
		if ( ! self::owns( $context ) ) {
			return false;
		}

		if ( 'core-blueprint-logs' === $context->page()
			&& ! in_array( $context->tab(), [ 'overview', 'audit', 'system', 'maintenance', 'ai-activity', 'retention', 'mail' ], true ) ) {
			return true;
		}

		if ( 'core-blueprint-reports' === $context->page()
			&& ! in_array( $context->tab(), [ 'overview', 'maintenance' ], true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve private requirements for a scoped Core Admin screen.
	 *
	 * @return string[]
	 */
	public static function requirements( ScreenContext $context ): array {
		if ( ! self::owns( $context ) || self::requires_full_set( $context ) ) {
			return [];
		}

		$requirements = self::cross_page_requirements();
		$page = $context->page();

		// A registered extension page gets the Base shell + cross-page HUD only.
		// Its own Foundations/components resolve through PageRegistry and its own
		// plugin remains responsible for extension-specific CSS/JS.
		if ( ! in_array( $page, self::BASE_PAGES, true ) ) {
			return $requirements;
		}

		$requirements = array_merge( $requirements, self::base_page_requirements( $context ) );
		return array_values( array_unique( $requirements ) );
	}

	/** Cross-page Base features shared by every registered Core Admin screen. */
	private static function cross_page_requirements(): array {
		return [
			'foundation.icons',
			'component.hud',
			'component.mode-switcher',
			'module.mode-switcher',
			'module.hud',
			'component.utility',
			'component.notices',
			'component.state-badges',
			'component.status-indicators',
			'component.actions',
			'component.field',
			'component.spinner',
			'component.empty-state',
		];
	}

	/** @return string[] */
	private static function base_page_requirements( ScreenContext $context ): array {
		$page = $context->page();
		$tab  = $context->tab();
		$view = $context->view();

		switch ( $page ) {
			case 'core-blueprint':
				return [
					'page.dashboard',
					'component.tile-grid',
					'component.status-menu',
					'foundation.toast',
					'module.status-menu',
					'module.module-activation',
				];

			case 'core-blueprint-logs':
				$items = [
					'page.logs', 'page.security', 'component.nav-tabs', 'component.meta',
					'component.badges', 'component.table-cols', 'module.logs-toggle', 'module.log-exports',
				];
				if ( 'overview' === $tab ) {
					$items[] = 'component.overview-framework';
				}
				if ( in_array( $tab, [ 'audit', 'system', 'maintenance' ], true ) ) {
					$items[] = 'component.filter-bar';
					$items[] = 'component.activity-chart';
				}
				if ( 'maintenance' === $tab ) {
					$items[] = 'component.tile-grid';
					$items[] = 'component.maintenance-summary';
				}
				if ( 'ai-activity' === $tab ) {
					$items[] = 'component.filter-bar';
					$items[] = 'component.log-table';
					$items[] = 'component.kv-tables';
				}
				if ( 'retention' === $tab ) {
					$items[] = 'component.policy-table';
				}
				if ( 'mail' === $tab ) {
					$items = array_merge( $items, [ 'page.mail', 'foundation.modal', 'module.mail-log' ] );
				}
				return $items;

			case 'core-blueprint-reports':
				return [
					'page.reports', 'page.security', 'component.nav-tabs', 'component.meta', 'component.filter-bar',
					'component.log-table', 'component.activity-chart', 'component.maintenance-summary', 'component.tile-grid',
					'component.badges', 'component.table-cols', 'foundation.modal', 'foundation.toast', 'module.reports',
				];

			case 'core-blueprint-notes':
				return [
					'page.notes', 'page.security', 'component.cards', 'component.badges',
					'component.interactive-surfaces', 'foundation.modal', 'foundation.toast', 'module.notes',
				];

			case 'core-blueprint-user-roles':
				return [ 'page.user-roles', 'page.security', 'foundation.modal', 'foundation.toast', 'module.user-roles' ];

			case 'core-blueprint-media-replace':
				return [ 'page.media-replace', 'page.security', 'component.kv-tables' ];

			case 'core-blueprint-package-downloads':
				return [ 'page.security', 'component.overview-framework' ];

			case 'core-blueprint-mail':
				return self::mail_requirements( $tab );

			case 'core-blueprint-safeguards':
				return self::safeguards_requirements( $tab );

			case 'core-blueprint-preferences':
				return self::preferences_requirements( $tab );

			case 'core-blueprint-console':
				return [ 'page.console', 'page.security', 'component.badges', 'foundation.modal', 'module.console' ];

			case 'core-blueprint-media-formats':
				return [ 'page.media-formats', 'page.security', 'component.panels', 'component.kv-tables', 'module.media-formats' ];

			case 'core-blueprint-content-models':
				return self::content_models_requirements( $tab, $view );

			case 'core-blueprint-snippets':
				return self::snippets_requirements( $tab, $view );
		}

		return [];
	}

	/** @return string[] */
	private static function mail_requirements( string $tab ): array {
		$items = [ 'page.mail', 'page.security', 'component.nav-tabs', 'component.badges' ];
		if ( 'test' === $tab ) {
			return array_merge( $items, [ 'foundation.toast', 'module.mail-test' ] );
		}
		if ( 'logs' === $tab ) {
			return array_merge( $items, [ 'component.meta', 'component.table-cols', 'foundation.modal', 'module.mail-log' ] );
		}
		return array_merge( $items, [ 'component.panels', 'module.mail-settings' ] );
	}

	/** @return string[] */
	private static function safeguards_requirements( string $tab ): array {
		$items = [ 'page.security', 'component.nav-tabs', 'component.badges' ];
		switch ( $tab ) {
			case 'access-mode':
				return array_merge( $items, [
					'page.safeguards-site-mode', 'component.panels', 'component.form-status',
					'foundation.object-picker', 'foundation.time-picker', 'foundation.choice-group', 'foundation.toast', 'module.site-mode',
				] );
			case 'login-shield':
				return array_merge( $items, [
					'page.safeguards-login-shield', 'component.panels', 'component.radio-card', 'component.form-status',
					'foundation.modal', 'module.login-shield',
				] );
			case 'two-factor':
				return array_merge( $items, [
					'component.panels', 'component.radio-card',
					'foundation.modal', 'foundation.toast', 'module.two-factor-policy',
				] );
			case 'core-shield':
				return array_merge( $items, [
					'page.safeguards-modules', 'page.safeguards-core-shield', 'component.rack-modules', 'component.radio-card',
					'component.interactive-surfaces', 'component.disclosure', 'component.form-status',
					'foundation.modal', 'foundation.toast', 'module.core-shield', 'module.description-toggle',
				] );
			case 'core-scanner':
				return array_merge( $items, [
					'page.safeguards-core-scanner', 'component.panels', 'component.filter-bar', 'component.log-table',
					'foundation.modal', 'foundation.toast', 'module.core-scanner',
				] );
			case 'failsafe':
				return array_merge( $items, [
					'page.safeguards-failsafe', 'component.panels', 'component.kv-tables', 'component.table-cols',
					'foundation.modal', 'foundation.toast', 'module.failsafe',
				] );
			default:
				return array_merge( $items, [ 'component.overview-framework', 'component.cards', 'component.tile-grid' ] );
		}
	}

	/** @return string[] */
	private static function preferences_requirements( string $tab ): array {
		$items = [ 'page.preferences', 'page.security', 'component.nav-tabs' ];
		switch ( $tab ) {
			case 'privacy':
				return array_merge( $items, [
					'page.privacy', 'component.panels', 'component.radio-card',
					'foundation.modal', 'foundation.toast', 'module.privacy',
				] );
			case 'notifications':
				return array_merge( $items, [
					'page.preferences-notifications', 'component.badges', 'component.policy-table', 'component.table-cols',
					'module.notifications', 'module.alert-recipients',
				] );
			case 'language':
				return array_merge( $items, [ 'page.language', 'component.radio-card', 'module.language' ] );
			case 'appearance':
				return array_merge( $items, [ 'page.appearance', 'component.cards', 'component.interactive-surfaces', 'module.appearance' ] );
			case 'floating-menu':
				return array_merge( $items, [ 'page.preferences-floating-menu', 'module.preferences-floating-menu' ] );
			case 'reports':
				return array_merge( $items, [
					'page.reports', 'component.panels', 'foundation.modal',
					'module.reports-preferences', 'provider.preferences-media',
				] );
			case 'permissions':
				return array_merge( $items, [
					'page.preferences-permissions', 'component.panels', 'component.badges', 'component.table-cols',
					'module.permissions',
				] );
			case 'notes':
				return array_merge( $items, [ 'page.notes', 'component.panels' ] );
			case 'cli':
				return array_merge( $items, [
					'page.preferences-cli', 'component.badges', 'foundation.clipboard', 'module.preferences-cli',
				] );
			case 'about':
				return $items;
			default:
				return array_merge( $items, [ 'component.overview-framework', 'component.cards' ] );
		}
	}

	/** @return string[] */
	private static function content_models_requirements( string $tab, string $view ): array {
		$items = [ 'page.content-models', 'page.security', 'component.nav-tabs', 'component.panels' ];

		if ( 'post-types' === $tab && in_array( $view, [ 'edit', 'duplicate' ], true ) ) {
			$items[] = 'foundation.icon-picker';
			$items[] = 'foundation.choice-group';
		}
		if ( 'taxonomies' === $tab && in_array( $view, [ 'edit', 'duplicate' ], true ) ) {
			$items[] = 'foundation.choice-group';
		}
		if ( 'option-pages' === $tab && in_array( $view, [ 'edit', 'duplicate' ], true ) ) {
			$items[] = 'foundation.icon-picker';
			$items[] = 'foundation.capability-picker';
		}
		if ( 'field-groups' === $tab && in_array( $view, [ 'edit', 'field' ], true ) ) {
			$items = array_merge( $items, [
				'foundation.modal', 'foundation.select-picker', 'foundation.choice-group', 'foundation.reorder', 'module.content-models',
			] );
		}

		return $items;
	}

	/** @return string[] */
	private static function snippets_requirements( string $tab, string $view ): array {
		$items = [ 'page.snippets', 'page.security', 'component.nav-tabs' ];
		if ( 'snippets' !== $tab ) {
			return $items;
		}

		$items[] = 'foundation.modal';
		$items[] = 'edit' === $view ? 'provider.snippets-editor' : 'provider.snippets-list';
		return $items;
	}
}
