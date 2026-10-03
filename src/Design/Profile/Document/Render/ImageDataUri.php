<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Render;

defined( 'ABSPATH' ) || exit;

final class ImageDataUri {
	private const MAX_IMAGE_BYTES = 8388608;
	private const MAX_DIMENSION_PX = 10000;
	private const MAX_PIXELS = 50000000;
	private const MAX_SVG_ELEMENTS = 10000;
	private const SVG_NS = 'http://www.w3.org/2000/svg';

	public static function assert_valid( string $data_uri ): string {
		if ( 1 !== preg_match( '#^data:image/(png|jpeg|webp|svg\+xml);base64,([A-Za-z0-9+/]+={0,2})$#', $data_uri, $matches ) ) {
			throw new \InvalidArgumentException( 'Document image rendering requires a local supported image data URI.' );
		}
		$decoded = base64_decode( $matches[2], true );
		if ( false === $decoded || '' === $decoded || strlen( $decoded ) > self::MAX_IMAGE_BYTES ) {
			throw new \InvalidArgumentException( 'Document image render data is invalid or too large.' );
		}

		$type = $matches[1];
		if ( 'svg+xml' === $type ) {
			$sanitized = SvgSanitizer::sanitize( $decoded );
			self::assert_safe_svg( $sanitized );
			return 'data:image/svg+xml;base64,' . base64_encode( $sanitized );
		}

		self::assert_raster( $decoded, $type );
		return $data_uri;
	}

	private static function assert_raster( string $bytes, string $type ): void {
		$expected_mime = match ( $type ) {
			'png' => 'image/png',
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			default => '',
		};
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || ! isset( $info[0], $info[1], $info['mime'] ) || $expected_mime !== strtolower( (string) $info['mime'] ) ) {
			throw new \InvalidArgumentException( 'Document image MIME type does not match a readable raster image.' );
		}
		self::assert_dimensions( (int) $info[0], (int) $info[1] );
	}

	private static function assert_safe_svg( string $markup ): void {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		try {
			$loaded = $document->loadXML( $markup, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded || ! $document->documentElement instanceof \DOMElement ) {
			throw new \InvalidArgumentException( 'Sanitized document SVG is invalid.' );
		}

		$root = $document->documentElement;
		if ( 'svg' !== strtolower( $root->localName ?: $root->tagName ) ) {
			throw new \InvalidArgumentException( 'Sanitized document SVG requires an svg root element.' );
		}

		$elements = $document->getElementsByTagName( '*' );
		if ( $elements->length > self::MAX_SVG_ELEMENTS ) {
			throw new \InvalidArgumentException( 'Document SVG contains too many elements.' );
		}
		foreach ( $elements as $element ) {
			if ( $element instanceof \DOMElement && '' !== (string) $element->namespaceURI && self::SVG_NS !== $element->namespaceURI ) {
				throw new \InvalidArgumentException( 'Document SVG contains an unsupported element namespace.' );
			}
		}

		[ $width, $height ] = self::svg_dimensions( $root );
		self::assert_dimensions( $width, $height );
	}

	/** @return array{0:int,1:int} */
	private static function svg_dimensions( \DOMElement $root ): array {
		$view_box = trim( $root->getAttribute( 'viewBox' ) );
		if ( '' !== $view_box ) {
			$parts = preg_split( '/[\s,]+/', $view_box );
			if ( is_array( $parts ) && 4 === count( $parts ) && is_numeric( $parts[2] ) && is_numeric( $parts[3] ) ) {
				$width = (float) $parts[2];
				$height = (float) $parts[3];
				if ( is_finite( $width ) && is_finite( $height ) && $width > 0.0 && $height > 0.0 ) {
					return [ (int) max( 1, round( $width ) ), (int) max( 1, round( $height ) ) ];
				}
			}
		}

		$width = self::svg_length( $root->getAttribute( 'width' ) );
		$height = self::svg_length( $root->getAttribute( 'height' ) );
		if ( $width <= 0.0 || $height <= 0.0 ) {
			throw new \InvalidArgumentException( 'Document SVG needs a valid viewBox or explicit width and height.' );
		}
		return [ (int) max( 1, round( $width ) ), (int) max( 1, round( $height ) ) ];
	}

	private static function svg_length( string $value ): float {
		if ( ! preg_match( '/^\s*([0-9]*\.?[0-9]+)\s*(px|pt|pc|mm|cm|in)?\s*$/i', $value, $matches ) ) {
			return 0.0;
		}
		$number = (float) $matches[1];
		$unit = strtolower( (string) ( $matches[2] ?? 'px' ) );
		return match ( $unit ) {
			'pt' => $number * 96.0 / 72.0,
			'pc' => $number * 16.0,
			'mm' => $number * 96.0 / 25.4,
			'cm' => $number * 96.0 / 2.54,
			'in' => $number * 96.0,
			default => $number,
		};
	}

	private static function assert_dimensions( int $width, int $height ): void {
		if (
			$width <= 0
			|| $height <= 0
			|| $width > self::MAX_DIMENSION_PX
			|| $height > self::MAX_DIMENSION_PX
			|| ( $width * $height ) > self::MAX_PIXELS
		) {
			throw new \InvalidArgumentException( 'Document image dimensions exceed the supported rendering bounds.' );
		}
	}

	private function __construct() {}
}
