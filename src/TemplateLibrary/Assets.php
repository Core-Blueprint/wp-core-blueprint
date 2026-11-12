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
		$core_page = $screen && ( 'core-blueprint_page_core-blueprint-template-library' === $screen->id
			|| ( $screen->id === \CoreBlueprint\Core\Admin\PageRegistry::hook_suffix( \CoreBlueprint\Core\Mail\Admin\Page::SLUG )
				&& \CoreBlueprint\Core\Design\Editor\Assets::platform_fullscreen_requested() ) );
		UiAssets::enqueue_modals( $core_page
			? UiAssets::MODAL_PRESENTATION_CORE : UiAssets::MODAL_PRESENTATION_WP_NATIVE );
		wp_enqueue_style(
			'cb-core-template-library',
			CB_CORE_URL . 'assets/css/components/template-library.css',
			[],
			self::version( 'assets/css/components/template-library.css' )
		);
		wp_enqueue_script_module(
			'@cb-core/template-actions',
			CB_CORE_URL . 'assets/js/core/template-actions.js',
			[ '@cb-core/modal', '@cb-core/icon' ],
			self::version( 'assets/js/core/template-actions.js' )
		);
		wp_enqueue_script_module(
			self::MODULE_ID,
			CB_CORE_URL . 'assets/js/core/template-browser.js',
			[ '@cb-core/modal', '@cb-core/template-actions' ],
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
			'browser'     => __( 'Browser', 'core-blueprint' ),
			'designs'     => __( 'Designs', 'core-blueprint' ),
			'templates'   => __( 'Templates', 'core-blueprint' ),
			'folders'     => __( 'Collections', 'core-blueprint' ),
			'allDesigns'  => __( 'All designs', 'core-blueprint' ),
			'current'     => __( 'Current', 'core-blueprint' ),
			'noDesigns'   => __( 'No designs found.', 'core-blueprint' ),
			'openDesign'  => __( 'Open design', 'core-blueprint' ),
			'saveAs'      => __( 'Save as template', 'core-blueprint' ),
			'browse'      => __( 'Browse templates', 'core-blueprint' ),
			'search'      => __( 'Search templates', 'core-blueprint' ),
			'searchDesigns'=> __( 'Search designs', 'core-blueprint' ),
			'all'         => __( 'All templates', 'core-blueprint' ),
			'origin'      => __( 'Origin', 'core-blueprint' ),
			'source'      => __( 'Source', 'core-blueprint' ),
			'plugin'      => __( 'Plugin', 'core-blueprint' ),
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
			'selectDesign'=> __( 'Select a design.', 'core-blueprint' ),
			'close'       => __( 'Close', 'core-blueprint' ),
			'compatible'  => __( 'Compatible', 'core-blueprint' ),
			'incompatible'=> __( 'Not compatible', 'core-blueprint' ),
			'actions'     => __( 'Actions', 'core-blueprint' ),
			'rename'      => __( 'Rename', 'core-blueprint' ),
			'duplicate'   => __( 'Duplicate', 'core-blueprint' ),
			'exportJson'  => __( 'Export JSON', 'core-blueprint' ),
			'delete'      => __( 'Delete', 'core-blueprint' ),
			'saveAction'  => __( 'Save', 'core-blueprint' ),
			'cancel'      => __( 'Cancel', 'core-blueprint' ),
			'templateName'=> __( 'Template name', 'core-blueprint' ),
			'renameHelp'  => __( 'Choose a new template name.', 'core-blueprint' ),
			'duplicateHelp'=> __( 'Name the new copy of this template.', 'core-blueprint' ),
			'deleteWarning'=> __( 'Permanently delete this saved template?', 'core-blueprint' ),
			'deleteConfirm'=> __( 'I understand this permanently deletes the saved template.', 'core-blueprint' ),
			'exportReady' => __( 'Template JSON downloaded.', 'core-blueprint' ),
			'actionDone'  => __( 'Template action completed.', 'core-blueprint' ),
			'actionConflict'=> __( 'Template changed elsewhere. Reload and try again.', 'core-blueprint' ),
			'actionRefused'=> __( 'You can no longer manage this template.', 'core-blueprint' ),
			'actionUnsupported'=> __( 'This action is not supported for this template.', 'core-blueprint' ),
			'actionFailed'=> __( 'The action could not be confirmed. Check the template before retrying.', 'core-blueprint' ),
			'actionRefresh'=> __( 'Templates refreshed; verify the latest state.', 'core-blueprint' ),
			'details'     => __( 'Templates are managed by their owning extension.', 'core-blueprint' ),
		];
		return $existing;
	}

	private static function version( string $path ): string {
		$file = CB_CORE_DIR . $path;
		return is_file( $file ) ? substr( hash_file( 'sha256', $file ), 0, 16 ) : CB_CORE_VERSION;
	}
}
