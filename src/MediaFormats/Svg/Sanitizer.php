<?php
declare(strict_types=1);
/**
 * Fail-closed SVG sanitization boundary.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats\Svg;

use CoreBlueprint\Core\MediaFormats\Environment;
use CoreBlueprint\Core\MediaFormats\Vendor;
use CoreBlueprint\Core\MediaFormats\Vendor\SvgSanitize\Sanitizer as UpstreamSanitizer;

defined( 'ABSPATH' ) || exit;

final class Sanitizer {
	public const VERSION = '1.0.0';
	private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

	/** @return true|\WP_Error */
	public static function sanitize_file( string $path ) {
		if ( ! Environment::svg_supported() ) {
			return new \WP_Error( 'cb_media_formats_svg_runtime_missing', __( 'SVG sanitization is unavailable because the required XML extensions are missing.', 'core-blueprint' ) );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) || ! wp_is_writable( $path ) ) {
			return new \WP_Error( 'cb_media_formats_svg_unreadable', __( 'The SVG upload could not be read safely.', 'core-blueprint' ) );
		}

		$size = filesize( $path );
		$max  = (int) apply_filters( 'cb_core_media_formats_svg_max_bytes', self::DEFAULT_MAX_BYTES );
		if ( false === $size || $size <= 0 || $size > max( 1, $max ) ) {
			return new \WP_Error( 'cb_media_formats_svg_size', __( 'The SVG file is empty or exceeds the SVG sanitization limit.', 'core-blueprint' ) );
		}

		$dirty = file_get_contents( $path );
		if ( false === $dirty || '' === $dirty ) {
			return new \WP_Error( 'cb_media_formats_svg_read_failed', __( 'The SVG upload could not be read safely.', 'core-blueprint' ) );
		}

		Vendor::register_svg_sanitizer();
		if ( ! class_exists( UpstreamSanitizer::class ) ) {
			return new \WP_Error( 'cb_media_formats_svg_sanitizer_missing', __( 'The SVG sanitizer could not be loaded.', 'core-blueprint' ) );
		}

		try {
			$sanitizer = new UpstreamSanitizer();
			$sanitizer->removeRemoteReferences( true );
			$sanitizer->minify( false );
			$sanitizer->setAllowHugeFiles( false );
			$clean = $sanitizer->sanitize( $dirty );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'cb_media_formats_svg_sanitize_failed', __( 'The SVG could not be sanitized safely.', 'core-blueprint' ) );
		}

		if ( ! is_string( $clean ) || '' === trim( $clean ) || ! self::is_svg_xml( $clean ) ) {
			return new \WP_Error( 'cb_media_formats_svg_invalid', __( 'The file is not a valid safe SVG.', 'core-blueprint' ) );
		}

		$written = file_put_contents( $path, $clean, LOCK_EX );
		if ( false === $written || $written <= 0 ) {
			return new \WP_Error( 'cb_media_formats_svg_write_failed', __( 'The sanitized SVG could not be written.', 'core-blueprint' ) );
		}

		return true;
	}

	public static function is_svg_file( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::DEFAULT_MAX_BYTES ) {
			return false;
		}
		$xml = file_get_contents( $path );
		return is_string( $xml ) && self::is_svg_xml( $xml );
	}

	private static function is_svg_xml( string $xml ): bool {
		if ( ! Environment::svg_supported() ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$loaded = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement ) {
			return false;
		}
		return 'svg' === strtolower( (string) $doc->documentElement->localName );
	}
}
