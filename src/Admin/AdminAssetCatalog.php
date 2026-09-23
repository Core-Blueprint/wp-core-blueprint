<?php
declare(strict_types=1);
/**
 * Private catalog for Core Admin assets and read-only providers.
 *
 * Asset IDs, WordPress handles and filenames are Base internals. Extensions
 * continue to consume PageRegistry semantic requirements rather than this
 * catalog directly.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin;

use CB\Core\UI\Assets as UiAssets;

defined( 'ABSPATH' ) || exit;

final class AdminAssetCatalog {

	public const EXTENSION_TAB_FULL_SET = 'provider.extension-tab-full-set';

	/** Enqueue one selected private asset/provider. */
	public static function enqueue( string $asset_id, ScreenContext $context ): void {
		$definition = self::definition( $asset_id );
		if ( null === $definition ) {
			return;
		}

		$type = (string) ( $definition['type'] ?? '' );
		if ( 'provider' === $type && is_callable( $definition['provider'] ?? null ) ) {
			$definition['provider']( $context );
			return;
		}
		if ( 'foundation' === $type ) {
			self::enqueue_foundation( (string) $definition['foundation'] );
			return;
		}
		if ( 'module' === $type ) {
			AdminModuleCatalog::enqueue( (string) $definition['module'], $context );
			return;
		}
		if ( 'style' === $type ) {
			wp_enqueue_style(
				(string) $definition['handle'],
				CB_CORE_URL . (string) $definition['src'],
				(array) ( $definition['deps'] ?? [] ),
				CB_CORE_VERSION
			);
		}
	}

	/** Stable cascade weight used by AdminAssetResolver. */
	public static function priority( string $asset_id ): int {
		if ( 'shell.tokens' === $asset_id ) {
			return 10;
		}
		if ( str_starts_with( $asset_id, 'foundation.' ) ) {
			return 14;
		}
		if ( 'shell.scrollbar' === $asset_id ) {
			return 20;
		}
		if ( 'component.meta' === $asset_id ) {
			return 30;
		}
		if ( 'component.mode-switcher' === $asset_id ) {
			return 31;
		}
		if ( 'component.filter-bar' === $asset_id ) {
			return 32;
		}
		if ( 'shell.layout' === $asset_id ) {
			return 40;
		}
		if ( str_starts_with( $asset_id, 'component.' ) ) {
			return 50;
		}
		if ( in_array( $asset_id, [ 'shell.buttons', 'shell.form-controls' ], true ) ) {
			return 60;
		}
		if ( 'shell.theme-canvas' === $asset_id ) {
			return 70;
		}
		if ( str_starts_with( $asset_id, 'page.' ) ) {
			return 80;
		}
		return 90;
	}

	/** Genuine universal Core Admin shell. */
	public static function minimal_shell(): array {
		return [
			'shell.tokens',
			'shell.scrollbar',
			'shell.layout',
			'shell.buttons',
			'shell.form-controls',
			'shell.theme-canvas',
		];
	}

	/**
	 * Complete private Base asset inventory used only by registered open addon
	 * tabs that cannot yet declare semantic per-tab requirements.
	 *
	 * @return string[]
	 */
	public static function extension_tab_full_set(): array {
		$assets = [
			'shell.tokens',
			'foundation.icons',
			'foundation.modal',
			'foundation.toast',
			'foundation.clipboard',
			'foundation.time-picker',
			'foundation.object-picker',
			'foundation.icon-picker',
			'foundation.capability-picker',
			'foundation.select-picker',
			'foundation.reorder',
			'foundation.choice-group',
			'foundation.token-input',
			'shell.scrollbar',
			'component.meta',
			'component.mode-switcher',
			'component.filter-bar',
			'shell.layout',
		];

		foreach ( [
			'activity-chart', 'maintenance-summary', 'tile-grid', 'kv-tables', 'badges', 'rack-modules', 'inert-text', 'meta-bar', 'panels', 'utility',
			'policy-table', 'overview-framework', 'form-status', 'disclosure', 'hud', 'cards', 'modals', 'toasts',
			'interactive-surfaces', 'state-badges', 'status-indicators', 'spinner', 'empty-state', 'nav-tabs',
			'table-cols', 'actions', 'log-table', 'field', 'radio-card', 'master-switch', 'choice-group',
			'integration-grid', 'detail-rows', 'status-menu', 'notices',
		] as $component ) {
			$assets[] = 'component.' . $component;
		}

		$assets[] = 'shell.buttons';
		$assets[] = 'shell.form-controls';
		$assets[] = 'shell.theme-canvas';

		foreach ( [
			'dashboard', 'logs', 'reports', 'preferences', 'privacy', 'appearance', 'language', 'security',
			'preferences-notifications', 'preferences-permissions', 'preferences-cli', 'console', 'mail',
			'safeguards-modules', 'safeguards-failsafe', 'safeguards-login-shield', 'safeguards-site-mode',
			'safeguards-core-shield', 'safeguards-core-scanner', 'notes', 'user-roles', 'media-replace',
			'media-formats', 'content-models', 'snippets',
		] as $page ) {
			$assets[] = 'page.' . $page;
		}

		$assets[] = 'page.preferences-floating-menu';
		return $assets;
	}

	/**
	 * Resolve one public Core Admin Design Foundation component requirement.
	 *
	 * Semantic component IDs are public through PageRegistry; the private asset
	 * IDs, WordPress handles, filenames and bundle boundaries mapped here are not.
	 *
	 * @internal PageRegistry is the public caller.
	 */
	public static function enqueue_component_requirement( string $component, ScreenContext $context ): void {
		$map = [
			'actions'            => [ 'component.actions' ],
			'badges'             => [ 'component.badges' ],
			'cards'              => [ 'component.cards' ],
			'description-toggle' => [ 'component.disclosure', 'module.description-toggle' ],
			'detail-rows'        => [ 'component.status-indicators', 'component.detail-rows', 'shell.buttons' ],
			'disclosure'         => [ 'foundation.icons', 'component.interactive-surfaces' ],
			'empty-state'        => [ 'component.empty-state' ],
			'fields'             => [ 'component.field' ],
			'form-controls'      => [ 'shell.form-controls' ],
			'integration-grid'   => [ 'component.status-indicators', 'component.integration-grid', 'shell.buttons' ],
			'kv-table'           => [ 'component.kv-tables' ],
			'master-switch'      => [ 'component.master-switch' ],
			'metric-tiles'       => [ 'component.tile-grid' ],
			'nav-tabs'           => [ 'component.nav-tabs' ],
			'notices'            => [ 'foundation.icons', 'component.notices' ],
			'overview'           => [ 'foundation.icons', 'component.overview-framework' ],
			'panels'             => [ 'component.panels' ],
			'policy-table'       => [ 'component.policy-table' ],
			'radio-cards'        => [ 'component.radio-card', 'component.interactive-surfaces' ],
			'state-badges'       => [ 'component.state-badges' ],
			'status'             => [ 'component.status-indicators' ],
		];

		foreach ( $map[ $component ] ?? [] as $asset_id ) {
			self::enqueue( $asset_id, $context );
		}
	}

	/** Whether an asset ID resolves to a known private definition. */
	public static function has( string $asset_id ): bool {
		return null !== self::definition( $asset_id );
	}

	/** @return array<string,mixed>|null */
	private static function definition( string $asset_id ): ?array {
		if ( self::EXTENSION_TAB_FULL_SET === $asset_id ) {
			return [
				'type'     => 'provider',
				'provider' => static function ( ScreenContext $context ): void {
					AdminExtensionTabAssetProvider::enqueue( $context );
				},
			];
		}

		$foundation = self::foundation_id( $asset_id );
		if ( null !== $foundation ) {
			return [ 'type' => 'foundation', 'foundation' => $foundation ];
		}

		if ( str_starts_with( $asset_id, 'module.' ) ) {
			return [
				'type'   => 'module',
				'module' => '@cb-core/' . substr( $asset_id, strlen( 'module.' ) ),
			];
		}

		if ( 'provider.preferences-media' === $asset_id ) {
			return [
				'type'     => 'provider',
				'provider' => static function (): void {
					wp_enqueue_media();
				},
			];
		}
		if ( 'provider.snippets-list' === $asset_id || 'provider.snippets-editor' === $asset_id ) {
			$editor = 'provider.snippets-editor' === $asset_id;
			return [
				'type'     => 'provider',
				'provider' => static function ( ScreenContext $context ) use ( $editor ): void {
					\CB\Core\Snippets\Admin\Assets::enqueue( $context->hook(), $editor );
				},
			];
		}

		$style = self::style_definition( $asset_id );
		return null === $style ? null : array_merge( [ 'type' => 'style' ], $style );
	}

	private static function foundation_id( string $asset_id ): ?string {
		$map = [
			'foundation.icons'             => 'icons',
			'foundation.modal'             => 'modal',
			'foundation.toast'             => 'toast',
			'foundation.clipboard'         => 'clipboard',
			'foundation.time-picker'       => 'time-picker',
			'foundation.object-picker'     => 'object-picker',
			'foundation.icon-picker'       => 'icon-picker',
			'foundation.capability-picker' => 'capability-picker',
			'foundation.select-picker'     => 'select-picker',
			'foundation.reorder'           => 'reorder',
			'foundation.choice-group'      => 'choice-group',
			'foundation.token-input'       => 'token-input',
		];
		return $map[ $asset_id ] ?? null;
	}

	private static function enqueue_foundation( string $foundation ): void {
		switch ( $foundation ) {
			case 'icons':
				UiAssets::enqueue_icons();
				break;
			case 'modal':
				UiAssets::enqueue_modals( UiAssets::MODAL_PRESENTATION_CORE );
				break;
			case 'toast':
				UiAssets::enqueue_toasts( UiAssets::TOAST_PRESENTATION_CORE );
				break;
			case 'clipboard':
				UiAssets::enqueue_clipboard( UiAssets::CLIPBOARD_PRESENTATION_CORE );
				break;
			case 'time-picker':
				UiAssets::enqueue_time_picker( UiAssets::TIME_PICKER_PRESENTATION_CORE );
				break;
			case 'object-picker':
				UiAssets::enqueue_object_picker( UiAssets::OBJECT_PICKER_PRESENTATION_CORE );
				break;
			case 'icon-picker':
				UiAssets::enqueue_icon_picker( UiAssets::ICON_PICKER_PRESENTATION_CORE );
				break;
			case 'capability-picker':
				UiAssets::enqueue_capability_picker( UiAssets::CAPABILITY_PICKER_PRESENTATION_CORE );
				break;
			case 'select-picker':
				UiAssets::enqueue_select_picker( UiAssets::SELECT_PICKER_PRESENTATION_CORE );
				break;
			case 'reorder':
				UiAssets::enqueue_reorder( UiAssets::REORDER_PRESENTATION_CORE );
				break;
			case 'choice-group':
				UiAssets::enqueue_choice_group( UiAssets::CHOICE_GROUP_PRESENTATION_CORE );
				break;
			case 'token-input':
				UiAssets::enqueue_token_inputs( UiAssets::TOKEN_INPUT_PRESENTATION_CORE );
				break;
		}
	}

	/** @return array{handle:string,src:string,deps:string[]}|null */
	private static function style_definition( string $asset_id ): ?array {
		$fixed = [
			'shell.tokens'        => [ 'handle' => 'cb-core-css-tokens', 'src' => 'assets/css/tokens.css', 'deps' => [] ],
			'shell.scrollbar'     => [ 'handle' => 'cb-core-css-scrollbar', 'src' => 'assets/css/components/scrollbar.css', 'deps' => [ 'cb-core-css-tokens' ] ],
			'shell.layout'        => [ 'handle' => 'cb-core-css-layout', 'src' => 'assets/css/layout.css', 'deps' => [ 'cb-core-css-tokens' ] ],
			'shell.buttons'       => [ 'handle' => 'cb-core-css-buttons', 'src' => 'assets/css/components/buttons.css', 'deps' => [ 'cb-core-css-tokens' ] ],
			'shell.form-controls' => [ 'handle' => 'cb-core-css-form-controls', 'src' => 'assets/css/components/form-controls.css', 'deps' => [ 'cb-core-css-tokens' ] ],
			'shell.theme-canvas'  => [ 'handle' => 'cb-core-css-theme-canvas', 'src' => 'assets/css/themes/canvas.css', 'deps' => [ 'cb-core-css-tokens' ] ],
			'component.meta'          => [ 'handle' => 'cb-core-css-meta', 'src' => 'assets/css/components/meta.css', 'deps' => [ 'cb-core-css-tokens' ] ],
			'component.mode-switcher' => [ 'handle' => 'cb-core-css-mode-switcher', 'src' => 'assets/css/components/mode-switcher.css', 'deps' => [ 'cb-core-css-tokens' ] ],
			'component.filter-bar'    => [ 'handle' => 'cb-core-css-filter-bar', 'src' => 'assets/css/components/filter-bar.css', 'deps' => [ 'cb-core-css-tokens', 'cb-core-css-mode-switcher' ] ],
			'page.preferences-floating-menu' => [
				'handle' => 'cb-core-css-page-preferences-floating-menu',
				'src'    => 'assets/css/pages/preferences-floating-menu.css',
				'deps'   => [ 'cb-core-css-tokens', 'cb-core-css-buttons', 'cb-core-css-form-controls', 'cb-core-css-state-badges', 'cb-core-css-page-preferences' ],
			],
		];
		if ( isset( $fixed[ $asset_id ] ) ) {
			return $fixed[ $asset_id ];
		}

		if ( str_starts_with( $asset_id, 'component.' ) ) {
			$name = substr( $asset_id, strlen( 'component.' ) );
			$allowed = [
				'activity-chart', 'maintenance-summary', 'tile-grid', 'kv-tables', 'badges', 'rack-modules', 'inert-text', 'meta-bar', 'panels', 'utility',
				'policy-table', 'overview-framework', 'form-status', 'disclosure', 'hud', 'cards', 'modals', 'toasts',
				'interactive-surfaces', 'state-badges', 'status-indicators', 'spinner', 'empty-state', 'nav-tabs',
				'table-cols', 'actions', 'log-table', 'field', 'radio-card', 'master-switch', 'choice-group',
				'integration-grid', 'detail-rows', 'status-menu', 'notices',
			];
			if ( in_array( $name, $allowed, true ) ) {
				return [
					'handle' => 'cb-core-css-' . $name,
					'src'    => 'assets/css/components/' . $name . '.css',
					'deps'   => [ 'cb-core-css-tokens' ],
				];
			}
		}

		if ( str_starts_with( $asset_id, 'page.' ) ) {
			$name = substr( $asset_id, strlen( 'page.' ) );
			$allowed = [
				'dashboard', 'logs', 'reports', 'preferences', 'privacy', 'appearance', 'language', 'security',
				'preferences-notifications', 'preferences-permissions', 'preferences-cli', 'console', 'mail',
				'safeguards-modules', 'safeguards-failsafe', 'safeguards-login-shield', 'safeguards-site-mode',
				'safeguards-core-shield', 'safeguards-core-scanner', 'notes', 'user-roles', 'media-replace',
				'media-formats', 'content-models', 'snippets',
			];
			if ( in_array( $name, $allowed, true ) ) {
				return [
					'handle' => 'cb-core-css-page-' . $name,
					'src'    => 'assets/css/pages/' . $name . '.css',
					'deps'   => [ 'cb-core-css-tokens' ],
				];
			}
		}

		return null;
	}
}
