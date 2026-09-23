<?php
declare(strict_types=1);
/**
 * PageRegistry - public registration boundary for Core Blueprint admin pages.
 *
 * Extensions register Page implementations during cb_core_register_pages.
 * Base owns menu wiring, hook suffixes, the minimal Core Admin shell and
 * semantic shared-UI requirements. Asset handles and filenames are internal.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Admin;

use CB\Core\Design\Editor\Assets as DesignEditorAssets;
use CB\Core\UI\Assets as UiAssets;

defined( 'ABSPATH' ) || exit;

final class PageRegistry {

	/** Parent menu slug - shared with the Core Blueprint top-level menu. */
	public const PARENT_SLUG = 'core-blueprint';

	/** Public extension pages may use null or positions at/after this boundary. */
	private const EXTENSION_POSITION_MIN = 100;

	/** Base-owned slugs are protected independently of registration order. */
	private const BASE_RESERVED_SLUGS = [
		'core-blueprint',
		'core-blueprint-console',
		'core-blueprint-content-models',
		'core-blueprint-logs',
		'core-blueprint-mail',
		'core-blueprint-media-formats',
		'core-blueprint-media-replace',
		'core-blueprint-notes',
		'core-blueprint-package-downloads',
		'core-blueprint-preferences',
		'core-blueprint-config-profiles',
		'core-blueprint-reports',
		'core-blueprint-safeguards',
		'core-blueprint-settings',
		'core-blueprint-snippets',
		'core-blueprint-user-roles',
	];

	/** Public semantic Foundation requirements. */
	private const FOUNDATION_REQUIREMENTS = [
		'capability-picker',
		'choice-group',
		'clipboard',
		'design-editor',
		'icon-picker',
		'icons',
		'modal',
		'object-picker',
		'reorder',
		'select-picker',
		'time-picker',
		'toast',
		'token-input',
	];

	/** Public semantic Core Admin component requirements. */
	private const COMPONENT_REQUIREMENTS = [
		'actions',
		'badges',
		'cards',
		'description-toggle',
		'detail-rows',
		'disclosure',
		'empty-state',
		'fields',
		'form-controls',
		'integration-grid',
		'kv-table',
		'master-switch',
		'metric-tiles',
		'nav-tabs',
		'notices',
		'overview',
		'panels',
		'policy-table',
		'radio-cards',
		'state-badges',
		'status',
	];

	/** @var array<string, Page> slug -> page instance */
	private static array $pages = [];

	/** @var array<string, array{foundations:string[],components:string[]}> */
	private static array $requirements = [];

	/** @var array<string, string> slug -> WP hook_suffix */
	private static array $hooks = [];

	/** Boot the WordPress menu integration. */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'on_admin_menu' ], 20 );
	}

	/**
	 * Register an extension-owned Core Admin page.
	 *
	 * Public page slugs use strict lower-case kebab-case, cannot claim a
	 * Base-owned slug, and use either a null position or a position >= 100.
	 * Shared UI requirements are semantic identifiers, never asset handles.
	 *
	 * @param Page  $page         Page implementation.
	 * @param array $requirements Optional semantic requirements with
	 *                            `foundations` and/or `components` arrays.
	 */
	public static function register( Page $page, array $requirements = [] ): bool {
		return self::register_page( $page, $requirements, false );
	}

	/**
	 * Register a Base-owned page.
	 *
	 * Internal lifecycle helper. Not part of the public v1 extension API.
	 *
	 * @internal
	 */
	public static function register_base( Page $page, array $requirements = [] ): bool {
		if ( ! self::is_base_page_implementation( $page ) ) {
			self::diagnostic( 'Base page registration is restricted to page implementations shipped by Core Blueprint Base.' );
			return false;
		}
		return self::register_page( $page, $requirements, true );
	}

	/** Get a registered page by slug, or null if unknown. */
	public static function get( string $slug ): ?Page {
		return self::$pages[ $slug ] ?? null;
	}

	/**
	 * All registered pages, sorted by position.
	 *
	 * @return Page[]
	 */
	public static function all(): array {
		$pages = array_values( self::$pages );
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

	/** Get the WordPress hook_suffix for a registered page. */
	public static function hook_suffix( string $slug ): string {
		return self::$hooks[ $slug ] ?? '';
	}

	/**
	 * All registered hook_suffixes.
	 *
	 * @return string[]
	 * @internal
	 */
	public static function all_hook_suffixes(): array {
		return array_values( array_filter( self::$hooks ) );
	}

	/**
	 * Enqueue semantic requirements for the registered page matching a hook.
	 *
	 * Internal asset resolver. Public callers register semantic requirements;
	 * they do not call this method or depend on its handles/files.
	 *
	 * @internal
	 */
	public static function enqueue_requirements_for_hook( string $hook ): void {
		$slug = array_search( $hook, self::$hooks, true );
		if ( false === $slug || ! isset( self::$requirements[ $slug ] ) ) {
			return;
		}

		self::enqueue_normalized_requirements( self::$requirements[ $slug ], $hook );
	}

	/**
	 * Validate a semantic requirement set for another Base-owned public registry.
	 *
	 * @internal SettingsRegistry reuses the PageRegistry vocabulary so extensions
	 *           never learn private asset handles or filenames.
	 *
	 * @return array{foundations:string[],components:string[]}|null
	 */
	public static function normalize_semantic_requirements( array $requirements, string $consumer ): ?array {
		return self::normalize_requirements( $requirements, $consumer );
	}

	/**
	 * Enqueue an already-normalized semantic requirement set for the current screen.
	 *
	 * @internal
	 * @param array{foundations:string[],components:string[]} $requirements
	 */
	public static function enqueue_semantic_requirements( array $requirements, string $hook ): void {
		self::enqueue_normalized_requirements( $requirements, $hook );
	}

	/** admin_menu handler. */
	public static function on_admin_menu(): void {
		/** Fires once so Core Blueprint extensions can register admin pages. */
		do_action( 'cb_core_register_pages' );

		foreach ( self::all() as $page ) {
			$suffix = add_submenu_page(
				self::PARENT_SLUG,
				$page->title(),
				$page->menu_title(),
				$page->capability(),
				$page->slug(),
				[ $page, 'render' ]
			);
			if ( $suffix ) {
				self::$hooks[ $page->slug() ] = $suffix;
			}
		}
	}

	/**
	 * Reset registry state - for tests only.
	 *
	 * @internal
	 */
	public static function _reset_for_testing(): void {
		self::$pages        = [];
		self::$requirements = [];
		self::$hooks        = [];
	}

	private static function register_page( Page $page, array $requirements, bool $base_owned ): bool {
		$slug = $page->slug();
		if ( ! self::valid_slug( $slug ) ) {
			self::diagnostic( "Invalid Core Admin page slug '{$slug}'. Use lower-case kebab-case." );
			return false;
		}

		$is_reserved = in_array( $slug, self::BASE_RESERVED_SLUGS, true );
		if ( $base_owned !== $is_reserved ) {
			self::diagnostic( $base_owned
				? "Base page slug '{$slug}' is not in the reserved Base page set."
				: "Core Admin page slug '{$slug}' is reserved by Base."
			);
			return false;
		}

		if ( isset( self::$pages[ $slug ] ) ) {
			self::diagnostic( "Core Admin page '{$slug}' is already registered; duplicates are rejected." );
			return false;
		}

		$capability = $page->capability();
		if ( '' === $capability || sanitize_key( $capability ) !== $capability ) {
			self::diagnostic( "Core Admin page '{$slug}' has an invalid capability." );
			return false;
		}

		$position = $page->position();
		if ( $base_owned ) {
			if ( null !== $position && ( $position < 1 || $position >= self::EXTENSION_POSITION_MIN ) ) {
				self::diagnostic( "Base Core Admin page '{$slug}' must use a position between 1 and 99 or null." );
				return false;
			}
		} elseif ( null !== $position && $position < self::EXTENSION_POSITION_MIN ) {
			self::diagnostic( "Extension Core Admin page '{$slug}' must use position 100 or higher, or null." );
			return false;
		}

		$normalized = self::normalize_requirements( $requirements, $slug );
		if ( null === $normalized ) {
			return false;
		}

		self::$pages[ $slug ]        = $page;
		self::$requirements[ $slug ] = $normalized;
		return true;
	}

	/** @return array{foundations:string[],components:string[]}|null */
	private static function normalize_requirements( array $requirements, string $consumer ): ?array {
		$unknown_keys = array_diff( array_keys( $requirements ), [ 'foundations', 'components' ] );
		if ( [] !== $unknown_keys ) {
			self::diagnostic( "Core Admin consumer '{$consumer}' contains unknown requirement groups." );
			return null;
		}

		$normalized = [ 'foundations' => [], 'components' => [] ];
		foreach ( $normalized as $group => $_ ) {
			$items = $requirements[ $group ] ?? [];
			if ( ! is_array( $items ) ) {
				self::diagnostic( "Core Admin consumer '{$consumer}' requirement group '{$group}' must be an array." );
				return null;
			}
			$allowed = 'foundations' === $group ? self::FOUNDATION_REQUIREMENTS : self::COMPONENT_REQUIREMENTS;
			foreach ( $items as $item ) {
				if ( ! is_string( $item ) || ! in_array( $item, $allowed, true ) ) {
					self::diagnostic( "Core Admin consumer '{$consumer}' requested an unknown {$group} identifier." );
					return null;
				}
				$normalized[ $group ][] = $item;
			}
			$normalized[ $group ] = array_values( array_unique( $normalized[ $group ] ) );
		}
		return $normalized;
	}

	/** @param array{foundations:string[],components:string[]} $requirements */
	private static function enqueue_normalized_requirements( array $requirements, string $hook ): void {
		$context = ScreenContext::from_request( $hook );
		foreach ( $requirements['foundations'] as $foundation ) {
			self::enqueue_foundation( $foundation );
		}
		foreach ( $requirements['components'] as $component ) {
			self::enqueue_component( $component, $context );
		}
	}

	private static function is_base_page_implementation( Page $page ): bool {
		if ( ! defined( 'CB_CORE_DIR' ) ) {
			return false;
		}

		$root = realpath( CB_CORE_DIR . 'src' );
		$file = ( new \ReflectionClass( $page ) )->getFileName();
		if ( false === $root || false === $file ) {
			return false;
		}

		$real = realpath( $file );
		return false !== $real && str_starts_with( $real, $root . DIRECTORY_SEPARATOR );
	}

	private static function valid_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $slug );
	}

	private static function enqueue_foundation( string $foundation ): void {
		switch ( $foundation ) {
			case 'toast':
				UiAssets::enqueue_toasts( UiAssets::TOAST_PRESENTATION_CORE );
				break;
			case 'modal':
				UiAssets::enqueue_modals( UiAssets::MODAL_PRESENTATION_CORE );
				break;
			case 'token-input':
				UiAssets::enqueue_token_inputs( UiAssets::TOKEN_INPUT_PRESENTATION_CORE );
				break;
			case 'clipboard':
				UiAssets::enqueue_clipboard( UiAssets::CLIPBOARD_PRESENTATION_CORE );
				break;
			case 'design-editor':
				DesignEditorAssets::enqueue();
				break;
			case 'time-picker':
				UiAssets::enqueue_time_picker( UiAssets::TIME_PICKER_PRESENTATION_CORE );
				break;
			case 'choice-group':
				UiAssets::enqueue_choice_group( UiAssets::CHOICE_GROUP_PRESENTATION_CORE );
				break;
			case 'icon-picker':
				UiAssets::enqueue_icon_picker( UiAssets::ICON_PICKER_PRESENTATION_CORE );
				break;
			case 'capability-picker':
				UiAssets::enqueue_capability_picker( UiAssets::CAPABILITY_PICKER_PRESENTATION_CORE );
				break;
			case 'object-picker':
				UiAssets::enqueue_object_picker( UiAssets::OBJECT_PICKER_PRESENTATION_CORE );
				break;
			case 'select-picker':
				UiAssets::enqueue_select_picker( UiAssets::SELECT_PICKER_PRESENTATION_CORE );
				break;
			case 'reorder':
				UiAssets::enqueue_reorder( UiAssets::REORDER_PRESENTATION_CORE );
				break;
			case 'icons':
				UiAssets::enqueue_icons();
				break;
		}
	}

	private static function enqueue_component( string $component, ScreenContext $context ): void {
		AdminAssetCatalog::enqueue_component_requirement( $component, $context );
	}

	private static function diagnostic( string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __METHOD__, $message, '1.0.0' );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Core Blueprint PageRegistry: ' . $message );
		}
	}
}
