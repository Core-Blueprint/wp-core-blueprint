<?php
declare(strict_types=1);
/**
 * Public asset boundary for the Core Blueprint Data Mapper workspace.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange\Mapper;

use CB\Core\Design\Editor\Assets as DesignerAssets;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const MODULE_ID = '@cb-core/data-mapper';
	public const STYLE_ID  = 'cb-core-data-mapper';

	public static function enqueue( string $title = '' ): void {
		$title = sanitize_text_field( trim( $title ) );
		if ( '' === $title ) {
			$title = __( 'Data Mapper', 'core-blueprint' );
		}

		DesignerAssets::enqueue_designer_mode( $title );

		wp_enqueue_style(
			self::STYLE_ID,
			CB_CORE_URL . 'assets/css/data-exchange/data-mapper.css',
			[ DesignerAssets::DESIGNER_MODE_STYLE ],
			self::asset_version( 'assets/css/data-exchange/data-mapper.css' )
		);

		wp_enqueue_script_module(
			self::MODULE_ID,
			CB_CORE_URL . 'assets/js/data-exchange/data-mapper.js',
			[ DesignerAssets::MODULE_ID ],
			self::asset_version( 'assets/js/data-exchange/data-mapper.js' )
		);
	}

	private static function asset_version( string $relative_path ): string {
		$path = CB_CORE_DIR . $relative_path;
		if ( ! is_readable( $path ) ) {
			return CB_CORE_VERSION;
		}
		$hash = hash_file( 'sha256', $path );
		return is_string( $hash ) && '' !== $hash
			? CB_CORE_VERSION . '-' . substr( $hash, 0, 12 )
			: CB_CORE_VERSION;
	}

	private function __construct() {}
}
