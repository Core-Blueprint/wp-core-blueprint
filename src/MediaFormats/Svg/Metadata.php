<?php
declare(strict_types=1);
/**
 * SVG Media Library metadata and sizing helpers.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats\Svg;

defined( 'ABSPATH' ) || exit;

final class Metadata {
	public static function boot(): void {
		add_action( 'add_attachment', [ __CLASS__, 'store' ] );
		add_filter( 'image_downsize', [ __CLASS__, 'downsize' ], 10, 3 );
		add_filter( 'wp_prepare_attachment_for_js', [ __CLASS__, 'prepare_for_js' ], 10, 3 );
	}

	public static function store( int $attachment_id ): void {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || '' === $file ) {
			return;
		}
		$dimensions = self::dimensions( $file );
		if ( null === $dimensions ) {
			return;
		}
		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$metadata = [
			'width'  => $dimensions['width'],
			'height' => $dimensions['height'],
			'file'   => $relative,
		];
		$size = filesize( $file );
		if ( false !== $size ) {
			$metadata['filesize'] = (int) $size;
		}
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	/** @return array{0:string,1:int,2:int,3:bool}|false */
	public static function downsize( $downsize, int $attachment_id, $size ) {
		unset( $size );
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return $downsize;
		}
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		$width  = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$height = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
		return [ $url, $width, $height, false ];
	}

	/** @param array<string,mixed> $response @param array<string,mixed>|false $meta @return array<string,mixed> */
	public static function prepare_for_js( array $response, \WP_Post $attachment, $meta ): array {
		if ( 'image/svg+xml' !== $attachment->post_mime_type ) {
			return $response;
		}
		$width  = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$height = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
		$url    = (string) ( $response['url'] ?? '' );
		$response['width']       = $width;
		$response['height']      = $height;
		$response['orientation'] = $height > $width ? 'portrait' : 'landscape';
		$response['sizes']['full'] = [
			'url'         => $url,
			'width'       => $width,
			'height'      => $height,
			'orientation' => $response['orientation'],
		];
		return $response;
	}

	/** @return array{width:int,height:int}|null */
	public static function dimensions( string $file ): ?array {
		if ( ! is_file( $file ) || ! is_readable( $file ) || ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$loaded = $doc->load( $file, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement || 'svg' !== strtolower( (string) $doc->documentElement->localName ) ) {
			return null;
		}

		$root = $doc->documentElement;
		$width  = self::numeric_length( $root->getAttribute( 'width' ) );
		$height = self::numeric_length( $root->getAttribute( 'height' ) );
		$view   = preg_split( '/[\s,]+/', trim( $root->getAttribute( 'viewBox' ) ) );
		$view_w = is_array( $view ) && 4 === count( $view ) && is_numeric( $view[2] ) ? (float) $view[2] : 0.0;
		$view_h = is_array( $view ) && 4 === count( $view ) && is_numeric( $view[3] ) ? (float) $view[3] : 0.0;

		if ( $width <= 0 && $height <= 0 && $view_w > 0 && $view_h > 0 ) {
			$width  = $view_w;
			$height = $view_h;
		} elseif ( $width > 0 && $height <= 0 && $view_w > 0 && $view_h > 0 ) {
			$height = $width * ( $view_h / $view_w );
		} elseif ( $height > 0 && $width <= 0 && $view_w > 0 && $view_h > 0 ) {
			$width = $height * ( $view_w / $view_h );
		}

		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}
		return [ 'width' => max( 1, (int) round( $width ) ), 'height' => max( 1, (int) round( $height ) ) ];
	}

	private static function numeric_length( string $value ): float {
		$value = trim( $value );
		if ( '' === $value || str_contains( $value, '%' ) ) {
			return 0.0;
		}
		if ( ! preg_match( '/^([0-9]+(?:\.[0-9]+)?)(?:px|pt|pc|mm|cm|in)?$/i', $value, $matches ) ) {
			return 0.0;
		}
		return (float) $matches[1];
	}
}
