<?php
declare(strict_types=1);
/**
 * Lazy admin assets for the Base-owned Mail Designer surface.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Mail\Admin;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Design\Editor\Assets as DesignEditorAssets;
use CoreBlueprint\Core\TemplateLibrary\Assets as TemplateLibraryAssets;

defined( 'ABSPATH' ) || exit;

final class DesignerAssets {
	public const MODULE_ID = '@cb-core/mail-designer';
	public const SAVE_MODULE_ID = '@cb-core/mail-designer-save';
	public const STYLE_HANDLE = 'cb-core-mail-designer';

	public static function enqueue( string $hook ): void {
		if ( $hook !== PageRegistry::hook_suffix( Page::SLUG ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presentation routing.
		if ( ! in_array( $tab, [ 'overview', 'templates' ], true ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			CB_CORE_URL . 'assets/css/pages/mail-designer.css',
			[],
			is_file( CB_CORE_DIR . 'assets/css/pages/mail-designer.css' )
				? substr( hash_file( 'sha256', CB_CORE_DIR . 'assets/css/pages/mail-designer.css' ), 0, 16 )
				: CB_CORE_VERSION
		);

		if ( 'templates' !== $tab || ! current_user_can( 'manage_options' )
			|| ! \CoreBlueprint\Core\Mail\DesignerState::is_enabled() ) {
			return;
		}
		$fullscreen = DesignEditorAssets::platform_fullscreen_requested();
		if ( $fullscreen ) {
			// Native Base Platform v1 remains the only Mail session/Save owner.
			// Deliberately do NOT enqueue mail-designer.js or its separate Save shim.
			DesignEditorAssets::enqueue_platform();
		}
		TemplateLibraryAssets::enqueue();
		wp_register_script( 'cb-core-mail-saved-config', false, [], CB_CORE_VERSION, true );
		wp_enqueue_script( 'cb-core-mail-saved-config' );
		wp_add_inline_script( 'cb-core-mail-saved-config',
			'window.cbCoreMailSaved=' . wp_json_encode( [
				'restRoot' => esc_url_raw( rest_url( 'core-blueprint/v1/mail-templates/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'labels' => [
					'saved' => __( 'Saved Mail templates', 'core-blueprint' ),
					'personal' => __( 'My templates', 'core-blueprint' ),
					'site' => __( 'Site templates', 'core-blueprint' ),
					'rename' => __( 'Rename', 'core-blueprint' ),
					'duplicate' => __( 'Duplicate', 'core-blueprint' ),
					'delete' => __( 'Delete', 'core-blueprint' ),
					'loadMore' => __( 'Load more', 'core-blueprint' ),
					'empty' => __( 'No saved templates yet.', 'core-blueprint' ),
					'error' => __( 'Template operation could not be verified. Reload before retrying.', 'core-blueprint' ),
					'templateName' => __( 'Template name', 'core-blueprint' ),
					'availability' => __( 'Availability', 'core-blueprint' ),
					'use' => __( 'Use template', 'core-blueprint' ),
					'browseAction' => __( 'Browse and use a Mail template', 'core-blueprint' ),
					'saveAs' => __( 'Save as template', 'core-blueprint' ),
					'saveAsHelp' => __( 'Save a reusable copy of this Mail design', 'core-blueprint' ),
					'actions' => __( 'Mail template actions', 'core-blueprint' ),
					'templates' => __( 'Templates', 'core-blueprint' ),
					'saveBusy' => __( 'Finish or verify the current Save before continuing.', 'core-blueprint' ),
					'useTitle' => __( 'Use a Mail template', 'core-blueprint' ),
					'notAuthorized' => __( 'The selected template is not authorized for this Mail Designer.', 'core-blueprint' ),
					'applied' => __( 'Template applied. Save the Mail design to keep these changes.', 'core-blueprint' ),
					'applyRefused' => __( 'Template Apply was refused or the context changed. No new Save was made.', 'core-blueprint' ),
					'saveAsTitle' => __( 'Save as Mail template', 'core-blueprint' ),
					'saveAction' => __( 'Save template', 'core-blueprint' ),
					'nameAndState' => __( 'Enter a name and verify the current Designer Save state.', 'core-blueprint' ),
					'contextChanged' => __( 'The Designer context changed. No template was saved.', 'core-blueprint' ),
					'savedConfirmation' => __( 'Reusable Mail template saved.', 'core-blueprint' ),
					'deleteTitle' => __( 'Delete Mail template', 'core-blueprint' ),
					'deleteBody' => __( 'Delete this reusable template? The live Mail design will not be changed.', 'core-blueprint' ),
					'deleteAction' => __( 'Delete template', 'core-blueprint' ),
					'renameTitle' => __( 'Rename Mail template', 'core-blueprint' ),
					'duplicateTitle' => __( 'Duplicate Mail template', 'core-blueprint' ),
					'nameRequired' => __( 'Enter a template name.', 'core-blueprint' ),
					'updated' => __( 'Saved Mail template updated.', 'core-blueprint' ),
					'conflict' => __( 'This template was changed elsewhere. Reload before trying again.', 'core-blueprint' ),
				],
			] ) . ';', 'before'
		);
		wp_enqueue_script_module(
			'@cb-core/mail-saved-library',
			CB_CORE_URL . 'assets/js/design/mail/saved-library.js',
			[ '@cb-core/template-browser', '@cb-core/modal', '@cb-core/toast' ],
			is_file( CB_CORE_DIR . 'assets/js/design/mail/saved-library.js' )
				? substr( hash_file( 'sha256', CB_CORE_DIR . 'assets/js/design/mail/saved-library.js' ), 0, 16 )
				: CB_CORE_VERSION
		);
	}

	private function __construct() {}
}
