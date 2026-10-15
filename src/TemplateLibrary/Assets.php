<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

use CoreBlueprint\Core\UI\Assets as UiAssets;

defined( 'ABSPATH' ) || exit;

/**
 * Public @cb-core/template-browser asset boundary for Base and extensions.
 * No marketplace traffic; consumers own preview/application and storage.
 */
final class Assets {
	public const MODULE_ID = '@cb-core/template-browser';
	private static bool $data_registered = false;

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$core_page = $screen && 'core-blueprint_page_core-blueprint-template-library' === $screen->id;
		UiAssets::enqueue_modals( $core_page
			? UiAssets::MODAL_PRESENTATION_CORE : UiAssets::MODAL_PRESENTATION_WP_NATIVE );
		wp_enqueue_style(
			'cb-core-template-library',
			CB_CORE_URL . 'assets/css/components/template-library.css',
			[],
			self::version( 'assets/css/components/template-library.css' )
		);
		wp_enqueue_script_module(
			self::MODULE_ID,
			CB_CORE_URL . 'assets/js/core/template-browser.js',
			[ '@cb-core/modal' ],
			self::version( 'assets/js/core/template-browser.js' )
		);
		if ( ! self::$data_registered ) {
			add_filter( 'script_module_data_' . self::MODULE_ID, [ self::class, 'data' ] );
			self::$data_registered = true;
		}
	}

	/** @param array<string,mixed> $existing
	 *  @return array<string,mixed>
	 */
	public static function data( array $existing ): array {
		$existing['restRoot'] = esc_url_raw( rest_url( BrowserController::ROUTE . '/' ) );
		$existing['nonce'] = wp_create_nonce( 'wp_rest' );
		$existing['i18n'] = [
			'library'     => __( 'Template Library', 'core-blueprint' ),
			'browse'      => __( 'Browse templates', 'core-blueprint' ),
			'search'      => __( 'Search templates', 'core-blueprint' ),
			'all'         => __( 'All templates', 'core-blueprint' ),
			'origin'      => __( 'Origin', 'core-blueprint' ),
			'source'      => __( 'Source', 'core-blueprint' ),
			'designer'    => __( 'Designer type', 'core-blueprint' ),
			'category'    => __( 'Category', 'core-blueprint' ),
			'builtin'     => __( 'Built-in', 'core-blueprint' ),
			'personal'    => __( 'My templates', 'core-blueprint' ),
			'site'        => __( 'Site templates', 'core-blueprint' ),
			'packs'       => __( 'Installed packs', 'core-blueprint' ),
			'loadMore'    => __( 'Load more', 'core-blueprint' ),
			'noResults'   => __( 'No templates found.', 'core-blueprint' ),
			'error'       => __( 'Could not load templates.', 'core-blueprint' ),
			'loading'     => __( 'Loading templates…', 'core-blueprint' ),
			'use'         => __( 'Use template', 'core-blueprint' ),
			'select'      => __( 'Select a template.', 'core-blueprint' ),
			'close'       => __( 'Close', 'core-blueprint' ),
			'compatible'  => __( 'Compatible', 'core-blueprint' ),
			'incompatible'=> __( 'Not compatible', 'core-blueprint' ),
			'details'     => __( 'Templates are managed by their owning extension.', 'core-blueprint' ),
		];
		return $existing;
	}

	private static function version( string $path ): string {
		$file = CB_CORE_DIR . $path;
		return is_file( $file ) ? substr( hash_file( 'sha256', $file ), 0, 16 ) : CB_CORE_VERSION;
	}
}
