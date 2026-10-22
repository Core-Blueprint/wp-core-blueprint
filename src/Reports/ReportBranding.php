<?php
declare(strict_types=1);
/**
 * Reports branding resolver.
 *
 * Report content is immutable, but branding is intentionally resolved at PDF
 * render time. This keeps report rows small and allows report appearance or
 * optional provider details to change without duplicating branding data in snapshots.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ReportBranding {

	const FALLBACK_LOGO_REL = 'assets/core-blueprint-icon.svg';
	const DEFAULT_ACCENT    = '#0064c8';
	const MAX_LOGO_BYTES    = 2097152; // 2 MiB source file.
	const MAX_LOGO_DIMENSION = 4096;
	const PDF_SVG_DENSITY    = 192;
	const PDF_RASTER_MAX_W   = 400;
	const PDF_RASTER_MAX_H   = 110;
	const MAX_PDF_LOGO_BYTES = 4194304; // 4 MiB generated PNG.

	/** @var string[] User-supplied formats deliberately supported for PDF branding. */
	private const SUPPORTED_LOGO_MIMES = [ 'image/jpeg', 'image/png', 'image/svg+xml' ];

	/**
	 * Raw settings shape. Keep this distinct from resolved rendering data.
	 *
	 * @return array{logo_attachment_id:int,show_logo:bool,provider_name:string,provider_contact:string,accent_color:string,surface_style:string,density:string,corner_style:string,text_scale:string}
	 */
	public static function settings_defaults(): array {
		return [
			'logo_attachment_id' => 0,
			'show_logo'          => true,
			'provider_name'      => '',
			'provider_contact'   => '',
			'accent_color'       => self::DEFAULT_ACCENT,
			'surface_style'      => 'cards',
			'density'            => 'comfortable',
			'corner_style'       => 'soft',
			'text_scale'         => 'standard',
		];
	}

	/**
	 * Resolved branding for the admin preview.
	 *
	 * @return array<string,mixed>
	 */
	public static function current(): array {
		$native = DesignDocumentAdapter::current();
		$fields = $native['fields'];
		\CoreBlueprint\Core\Reports\Composer\NativeComposition::assert_fields( $fields );
		$flow = \CoreBlueprint\Core\Reports\Composer\NativeComposition::flow_branding( $fields );
		$logo_id = (int) $flow['logo_attachment_id'];
		$show_logo = $flow['show_logo'];
		$url = $show_logo && $logo_id > 0 ? self::attachment_url( $logo_id ) : '';
		if ( $show_logo && '' === $url ) {
			$url = self::fallback()['logo_url'];
		}
		return [
			'logo_url' => $url, 'show_logo' => $show_logo,
			'provider_name' => $flow['provider_name'],
			'provider_contact' => $flow['provider_contact'],
			'accent_color' => $flow['accent_color'],
			'surface_style' => $flow['surface_style'],
			'density' => $flow['density'],
			'corner_style' => $flow['corner_style'],
			'text_scale' => $flow['text_scale'],
			'is_default' => $fields === \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_fields(),
		];
	}

	/**
	 * Resolved branding safe for the PDF renderer. Logos are embedded locally
	 * as data URIs so Dompdf can keep all remote fetching disabled.
	 *
	 * @return array{logo_url:string,show_logo:bool,provider_name:string,provider_contact:string,accent_color:string,surface_style:string,density:string,corner_style:string,text_scale:string,is_default:bool}
	 */
	public static function for_pdf(): array {
		return MaintenanceFlowBranding::resolve();
	}

	/**
	 * CB-default resolved branding.
	 *
	 * @return array{logo_url:string,show_logo:bool,provider_name:string,provider_contact:string,accent_color:string,surface_style:string,density:string,corner_style:string,text_scale:string,is_default:bool}
	 */
	public static function fallback(): array {
		return [
			'logo_url'          => CB_CORE_URL . self::FALLBACK_LOGO_REL,
			'show_logo'         => true,
			'provider_name'     => '',
			'provider_contact'  => '',
			'accent_color'      => self::DEFAULT_ACCENT,
			'surface_style'     => 'cards',
			'density'           => 'comfortable',
			'corner_style'      => 'soft',
			'text_scale'        => 'standard',
			'is_default'        => true,
		];
	}

	/**
	 * Whether an attachment is acceptable as a user-supplied PDF logo.
	 * Only bounded local JPEG, PNG and SVG Media Library files are accepted.
	 */
	public static function is_supported_logo_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED_LOGO_MIMES, true ) ) {
			return false;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_LOGO_BYTES ) {
			return false;
		}

		if ( 'image/svg+xml' === $mime ) {
			if ( 'svg' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				return false;
			}

			$svg = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return false !== $svg && 1 === preg_match( '/<svg(?:\s|>)/i', $svg );
		}

		$image = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : getimagesize( $path );
		if ( ! is_array( $image ) || empty( $image[0] ) || empty( $image[1] ) ) {
			return false;
		}

		return (int) $image[0] <= self::MAX_LOGO_DIMENSION
			&& (int) $image[1] <= self::MAX_LOGO_DIMENSION;
	}

	/**
	 * Return a validated Media Library attachment as a PDF-safe data URI.
	 *
	 * Dompdf/php-svg-lib does not reliably render SVG paint servers such as
	 * linear gradients. User-supplied SVG logos are therefore rasterized
	 * locally to a transparent PNG in memory. JPEG and PNG attachments keep
	 * their existing direct-data-URI path.
	 */
	public static function attachment_data_uri( int $attachment_id ): string {
		if ( ! self::is_supported_logo_attachment( $attachment_id ) ) {
			return '';
		}

		$path = get_attached_file( $attachment_id );
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! is_string( $path ) ) {
			return '';
		}

		if ( 'image/svg+xml' === $mime ) {
			return self::svg_png_data_uri( $path );
		}

		return self::file_data_uri( $path, $mime, self::MAX_LOGO_BYTES );
	}

	/** Rasterize one validated local SVG logo to a bounded transparent PNG. */
	private static function svg_png_data_uri( string $path ): string {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) || ! class_exists( '\ImagickPixel' ) ) {
			return '';
		}

		try {
			if ( [] === \Imagick::queryFormats( 'SVG' ) || [] === \Imagick::queryFormats( 'PNG' ) ) {
				return '';
			}

			$svg = self::pdf_safe_svg_source( $path );
			if ( '' === $svg ) {
				return '';
			}

			$image = new \Imagick();
			try {
				$image->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
				$image->setResolution( self::PDF_SVG_DENSITY, self::PDF_SVG_DENSITY );
				$image->readImageBlob( $svg );

				if ( $image->getNumberImages() < 1 ) {
					return '';
				}

				$image->setIteratorIndex( 0 );
				$image->setImageFormat( 'png32' );
				$image->thumbnailImage( self::PDF_RASTER_MAX_W, self::PDF_RASTER_MAX_H, true );
				$image->setImagePage( 0, 0, 0, 0 );
				$image->stripImage();

				$width  = $image->getImageWidth();
				$height = $image->getImageHeight();
				if ( $width <= 0 || $height <= 0 || $width > self::PDF_RASTER_MAX_W || $height > self::PDF_RASTER_MAX_H ) {
					return '';
				}

				$png = $image->getImageBlob();
				if ( '' === $png || strlen( $png ) > self::MAX_PDF_LOGO_BYTES || "\x89PNG\r\n\x1a\n" !== substr( $png, 0, 8 ) ) {
					return '';
				}

				return 'data:image/png;base64,' . base64_encode( $png );
			} finally {
				$image->clear();
			}
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Read an SVG source for local PDF rasterization without external resources.
	 * Fragment references such as url(#gradient) and href="#shape" remain valid.
	 */
	private static function pdf_safe_svg_source( string $path ): string {
		$svg = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $svg || '' === $svg ) {
			return '';
		}

		// Do not let the PDF conversion path resolve DTDs, entities, imported
		// stylesheets or non-fragment image/use references.
		if ( preg_match( '/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet|@import/i', $svg ) ) {
			return '';
		}

		if ( preg_match_all( '/(?:xlink:href|href)\s*=\s*(["\'])(.*?)\1/is', $svg, $hrefs, PREG_SET_ORDER ) ) {
			foreach ( $hrefs as $href ) {
				$target = trim( (string) ( $href[2] ?? '' ) );
				if ( '' === $target || '#' !== $target[0] ) {
					return '';
				}
			}
		}

		if ( preg_match_all( '/url\s*\(\s*(["\']?)(.*?)\1\s*\)/is', $svg, $urls, PREG_SET_ORDER ) ) {
			foreach ( $urls as $url ) {
				$target = trim( (string) ( $url[2] ?? '' ) );
				if ( '' === $target || '#' !== $target[0] ) {
					return '';
				}
			}
		}

		return $svg;
	}

	/** Read a bounded local file and return it as a data URI. */
	public static function file_data_uri( string $path, string $mime, int $max_bytes = self::MAX_LOGO_BYTES ): string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > $max_bytes ) {
			return '';
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return '';
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	/** Resolve a validated attachment URL for the admin preview. */
	public static function attachment_url( int $attachment_id, string $size = 'medium' ): string {
		if ( ! self::is_supported_logo_attachment( $attachment_id ) ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( false !== $url && '' !== $url ) {
			return (string) $url;
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( false !== $url && '' !== $url ) {
			return (string) $url;
		}

		$url = wp_get_attachment_url( $attachment_id );
		return false === $url ? '' : (string) $url;
	}

	/** @param mixed $value @param string[] $allowed */
	private static function sanitize_choice( mixed $value, array $allowed, string $fallback ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function sanitize_bool( mixed $value, bool $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $parsed ? $fallback : (bool) $parsed;
	}

	private static function sanitize_hex( string $color, string $fallback_color ): string {
		$color = trim( $color );
		if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
			return strtolower( $color );
		}
		return $fallback_color;
	}
}
