<?php
declare(strict_types=1);
/**
 * Read-only media capability detection.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats;

defined( 'ABSPATH' ) || exit;

final class Environment {
	public static function svg_supported(): bool {
		return extension_loaded( 'dom' )
			&& extension_loaded( 'libxml' )
			&& class_exists( '\DOMDocument' );
	}

	public static function webp_supported(): bool {
		return self::editor_supports( 'image/webp' );
	}

	public static function avif_supported(): bool {
		return self::editor_supports( 'image/avif' );
	}

	public static function heic_supported(): bool {
		return self::editor_supports( 'image/heic' ) || self::editor_supports( 'image/heif' );
	}

	public static function jxl_processing_supported(): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}
		try {
			return ! empty( \Imagick::queryFormats( 'JXL' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function image_editor_label(): string {
		$editors = apply_filters( 'wp_image_editors', [ 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ] );
		if ( ! is_array( $editors ) ) {
			$editors = [];
		}

		foreach ( $editors as $editor ) {
			$editor = is_string( $editor ) ? $editor : '';
			if ( '' === $editor || ! class_exists( $editor ) ) {
				continue;
			}
			if ( is_callable( [ $editor, 'test' ] ) && ! $editor::test() ) {
				continue;
			}

			if ( str_contains( strtolower( $editor ), 'imagick' ) ) {
				return 'Imagick';
			}
			if ( str_contains( strtolower( $editor ), '_gd' ) || str_ends_with( strtolower( $editor ), 'gd' ) ) {
				return 'GD';
			}
			return $editor;
		}

		return __( 'Unavailable', 'core-blueprint' );
	}

	public static function max_upload_bytes(): int {
		return function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
	}

	private static function editor_supports( string $mime ): bool {
		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			return false;
		}
		return (bool) wp_image_editor_supports( [ 'mime_type' => $mime ] );
	}
}
