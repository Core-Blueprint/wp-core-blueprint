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
			CB_CORE_VERSION
		);

		if ( 'templates' !== $tab || ! DesignEditorAssets::platform_fullscreen_requested()
			|| ! current_user_can( 'manage_options' )
			|| ! \CoreBlueprint\Core\Mail\DesignerState::is_enabled() ) {
			return;
		}

		// Native Base Platform v1 is the sole Mail session/History/Save owner.
		// Deliberately do NOT enqueue mail-designer.js or its POST Save shim.
		DesignEditorAssets::enqueue_platform();
	}

	private function __construct() {}
}
