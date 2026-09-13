<?php
declare(strict_types=1);
/**
 * Public asset boundary for the shared Design Foundation editor engine.
 *
 * Extensions opt into this capability semantically through PageRegistry or
 * SettingsRegistry. Base owns module/script identifiers, versioning and private
 * source layout behind these public Design Foundation asset methods.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Design\Editor;

use CB\Core\Brand\CoreBlueprintMark;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const MODULE_ID = '@cb-core/design-editor';
	public const SHELL_STYLE = 'cb-core-design-editor-shell';
	public const DESIGNER_MODE_STYLE = 'cb-core-designer-mode';
	public const DESIGNER_MODE_SCRIPT = 'cb-core-designer-mode';
	private const TOKEN_STYLE = 'cb-core-css-tokens';
	private const BUTTON_STYLE = 'cb-core-css-buttons';
	private const MOTION_MODULE_ID = '@cb-core/design-motion';

	public static function enqueue(): void {
		// The global Admin Theme normally enqueues semantic tokens first on
		// wp-admin. This public editor boundary still owns the same canonical
		// stylesheet dependency so its asset graph does not depend on incidental
		// enqueue order. Theme-state resolution remains owned by AdminTheme.
		wp_enqueue_style(
			self::TOKEN_STYLE,
			CB_CORE_URL . 'assets/css/tokens.css',
			[],
			self::asset_version( 'assets/css/tokens.css' )
		);

		wp_enqueue_style(
			self::SHELL_STYLE,
			CB_CORE_URL . 'assets/css/design/editor-shell.css',
			[ self::TOKEN_STYLE ],
			self::asset_version( 'assets/css/design/editor-shell.css' )
		);

		wp_register_script_module(
			self::MOTION_MODULE_ID,
			CB_CORE_URL . 'assets/js/design/core/motion.js',
			[],
			self::asset_version( 'assets/js/design/core/motion.js' )
		);

		wp_enqueue_script_module(
			self::MODULE_ID,
			CB_CORE_URL . 'assets/js/design/editor.js',
			[ self::MOTION_MODULE_ID ],
			self::asset_version( 'assets/js/design/editor.js' )
		);
	}

	/**
	 * Enqueue the canonical Core Blueprint Designer Mode around a consumer shell.
	 *
	 * Consumers provide their translated mode title plus declarative
	 * `data-cb-design-*` shell contracts and domain callbacks. Base owns launch/
	 * focus chrome, brand, shared labels and the private Designer Mode source path.
	 * Designer Mode also owns the narrow shared Button presentation required by
	 * the Base chrome it composes; consumers do not need the full Core Admin theme.
	 */
	public static function enqueue_designer_mode( string $title = '' ): void {
		self::enqueue();

		$title = sanitize_text_field( trim( $title ) );
		if ( '' === $title ) {
			$title = __( 'Design with Core Blueprint', 'core-blueprint' );
		}

		wp_enqueue_style(
			self::BUTTON_STYLE,
			CB_CORE_URL . 'assets/css/components/buttons.css',
			[ self::TOKEN_STYLE ],
			self::asset_version( 'assets/css/components/buttons.css' )
		);

		wp_enqueue_style(
			self::DESIGNER_MODE_STYLE,
			CB_CORE_URL . 'assets/css/design/designer-mode.css',
			[ self::SHELL_STYLE, self::BUTTON_STYLE ],
			self::asset_version( 'assets/css/design/designer-mode.css' )
		);

		wp_enqueue_script(
			self::DESIGNER_MODE_SCRIPT,
			CB_CORE_URL . 'assets/js/features/designer-launch.js',
			[],
			self::asset_version( 'assets/js/features/designer-launch.js' ),
			true
		);
		wp_localize_script(
			self::DESIGNER_MODE_SCRIPT,
			'cbCoreDesignerLaunch',
			[
				'title'         => $title,
				'label'         => __( 'Design with Core Blueprint', 'core-blueprint' ),
				'ariaLabel'     => __( 'Open Designer Mode', 'core-blueprint' ),
				'iconUrl'       => CoreBlueprintMark::data_uri(),
				// WordPress editor vocabulary intentionally uses the default text domain.
				'closeLabel'    => __( 'Close', 'default' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional WordPress platform vocabulary.
				'panelLabels'   => [
					'collapse' => __( 'Collapse', 'default' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional WordPress platform vocabulary.
					'expand'   => __( 'Expand', 'default' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional WordPress platform vocabulary.
				],
				'sidebarLabels' => [
					'inspector' => __( 'Inspector', 'core-blueprint' ),
					// WordPress editor vocabulary intentionally uses the default text domain.
					'layers'    => __( 'Layers', 'default' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional WordPress platform vocabulary.
					'settings'  => __( 'Settings', 'core-blueprint' ),
				],
			]
		);
	}

	/**
	 * Return a stable content revision for a public Design Foundation asset.
	 *
	 * Base can evolve during one RC without forcing the plugin version to change
	 * for every internal build. Content fingerprints make the browser request the
	 * current registered asset after an update while retaining the plugin version
	 * as a safe fallback when the file cannot be read.
	 */
	private static function asset_version( string $relative_path ): string {
		$path = CB_CORE_DIR . $relative_path;
		if ( ! is_readable( $path ) ) {
			return CB_CORE_VERSION;
		}

		$hash = hash_file( 'sha256', $path );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return CB_CORE_VERSION;
		}

		return CB_CORE_VERSION . '-' . substr( $hash, 0, 12 );
	}

	private function __construct() {}
}
