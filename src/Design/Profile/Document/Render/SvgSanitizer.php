<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Strict sanitizer for static SVG assets accepted by document render profiles.
 *
 * Only a bounded static-vector subset survives. Active content, animation,
 * embedded raster resources and all non-fragment resource references are
 * removed or rejected before the SVG reaches the PDF renderer.
 */
final class SvgSanitizer {
	private const MAX_BYTES = 8388608;
	private const SVG_NS = 'http://www.w3.org/2000/svg';
	private const XLINK_NS = 'http://www.w3.org/1999/xlink';
	private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

	private const BLOCKED_ELEMENTS = [
		'script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video',
		'image', 'animate', 'animatecolor', 'animatemotion', 'animatetransform', 'set',
	];

	private const TEXT_ELEMENTS = [ 'text', 'tspan', 'title', 'desc' ];

	public static function sanitize( string $markup ): string {
		if ( '' === trim( $markup ) || strlen( $markup ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Document SVG data is empty or too large.' );
		}

		$lower = strtolower( $markup );
		if (
			str_contains( $lower, '<!doctype' )
			|| str_contains( $lower, '<!entity' )
			|| str_contains( $lower, '<?xml-stylesheet' )
		) {
			throw new \InvalidArgumentException( 'Document SVG declarations and external stylesheets are not supported.' );
		}
		if ( ! class_exists( \DOMDocument::class ) ) {
			throw new \InvalidArgumentException( 'Document SVG sanitization requires the DOM extension.' );
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		try {
			$loaded = $document->loadXML( $markup, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded || ! $document->documentElement instanceof \DOMElement ) {
			throw new \InvalidArgumentException( 'Document SVG markup is invalid.' );
		}

		$root = $document->documentElement;
		if ( 'svg' !== self::element_key( $root ) ) {
			throw new \InvalidArgumentException( 'Document SVG requires an svg root element.' );
		}
		if ( '' !== (string) $root->namespaceURI && self::SVG_NS !== $root->namespaceURI ) {
			throw new \InvalidArgumentException( 'Document SVG uses an unsupported root namespace.' );
		}

		self::sanitize_element( $root, true );
		if ( '' === (string) $root->namespaceURI && ! $root->hasAttribute( 'xmlns' ) ) {
			$root->setAttribute( 'xmlns', self::SVG_NS );
		}

		$output = $document->saveXML( $root );
		if ( ! is_string( $output ) || '' === trim( $output ) || strlen( $output ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Document SVG could not be sanitized safely.' );
		}

		return trim( $output );
	}

	private static function sanitize_element( \DOMElement $element, bool $root = false ): void {
		$schema = self::element_schema();
		$key = self::element_key( $element );
		if ( ! isset( $schema[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Document SVG contains an unsupported root element.' );
		}

		self::sanitize_attributes( $element, $schema[ $key ]['attributes'], $root );

		$children = [];
		foreach ( $element->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( $child instanceof \DOMElement ) {
				$child_key = self::element_key( $child );
				if ( in_array( $child_key, self::BLOCKED_ELEMENTS, true ) ) {
					$element->removeChild( $child );
					continue;
				}
				if ( ! isset( $schema[ $child_key ] ) ) {
					self::sanitize_unknown_wrapper( $element, $child );
					continue;
				}
				self::sanitize_element( $child );
				continue;
			}

			if ( $child instanceof \DOMCdataSection ) {
				if ( in_array( $key, self::TEXT_ELEMENTS, true ) ) {
					$element->replaceChild( $element->ownerDocument->createTextNode( $child->data ), $child );
				} else {
					$element->removeChild( $child );
				}
				continue;
			}

			if ( $child instanceof \DOMText ) {
				if ( ! in_array( $key, self::TEXT_ELEMENTS, true ) && '' !== trim( $child->nodeValue ?? '' ) ) {
					$element->removeChild( $child );
				}
				continue;
			}

			$element->removeChild( $child );
		}
	}

	private static function sanitize_unknown_wrapper( \DOMElement $parent, \DOMElement $wrapper ): void {
		$schema = self::element_schema();
		$children = [];
		foreach ( $wrapper->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof \DOMElement ) {
				$key = self::element_key( $child );
				if ( in_array( $key, self::BLOCKED_ELEMENTS, true ) ) {
					$wrapper->removeChild( $child );
					continue;
				}
				if ( isset( $schema[ $key ] ) ) {
					self::sanitize_element( $child );
					continue;
				}
				self::sanitize_unknown_wrapper( $wrapper, $child );
				continue;
			}

			if ( $child instanceof \DOMText && '' === trim( $child->nodeValue ?? '' ) ) {
				continue;
			}
			$wrapper->removeChild( $child );
		}

		while ( $wrapper->firstChild ) {
			$parent->insertBefore( $wrapper->firstChild, $wrapper );
		}
		$parent->removeChild( $wrapper );
	}

	/** @param array<string,string> $allowed */
	private static function sanitize_attributes( \DOMElement $element, array $allowed, bool $root ): void {
		$attributes = [];
		foreach ( $element->attributes as $attribute ) {
			$attributes[] = $attribute;
		}

		foreach ( $attributes as $attribute ) {
			if ( ! $attribute instanceof \DOMAttr ) {
				continue;
			}

			if ( self::XMLNS_NS === $attribute->namespaceURI ) {
				$valid_namespace = ( 'xmlns' === $attribute->nodeName && self::SVG_NS === $attribute->value )
					|| ( 'xmlns:xlink' === $attribute->nodeName && self::XLINK_NS === $attribute->value );
				if ( ! $valid_namespace ) {
					$element->removeAttributeNode( $attribute );
				}
				continue;
			}

			$key = strtolower( $attribute->nodeName );
			if ( ! isset( $allowed[ $key ] ) ) {
				$element->removeAttributeNode( $attribute );
				continue;
			}

			$value = self::sanitize_attribute_value( $key, $attribute->value );
			if ( null === $value ) {
				$element->removeAttributeNode( $attribute );
				continue;
			}
			$attribute->value = $value;
		}

		if ( $root && '' === (string) $element->namespaceURI && ! $element->hasAttribute( 'xmlns' ) ) {
			$element->setAttribute( 'xmlns', self::SVG_NS );
		}
	}

	private static function sanitize_attribute_value( string $name, string $value ): ?string {
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		$value = is_string( $value ) ? trim( $value ) : '';
		$lower = strtolower( $value );
		if (
			str_contains( $lower, 'javascript:' )
			|| str_contains( $lower, 'vbscript:' )
			|| str_contains( $lower, 'data:' )
			|| str_contains( $lower, 'expression(' )
			|| str_contains( $lower, '@import' )
			|| str_contains( $lower, 'behavior:' )
		) {
			return null;
		}

		if ( 'id' === $name && ! preg_match( '/^[-A-Za-z0-9_:.]+$/', $value ) ) {
			return null;
		}
		if ( 'class' === $name && ! preg_match( '/^[-A-Za-z0-9_:.\s]*$/', $value ) ) {
			return null;
		}
		if ( in_array( $name, [ 'href', 'xlink:href' ], true ) ) {
			return self::safe_fragment( $value ) ? $value : null;
		}
		if ( 'style' === $name ) {
			$style = self::sanitize_style( $value );
			return '' !== $style ? $style : null;
		}

		if ( preg_match_all( '/url\(\s*(["\']?)(.*?)\1\s*\)/i', $value, $urls, PREG_SET_ORDER ) ) {
			foreach ( $urls as $url ) {
				if ( ! self::safe_fragment( trim( (string) $url[2] ) ) ) {
					return null;
				}
			}
		}

		return $value;
	}

	private static function sanitize_style( string $style ): string {
		$allowed = [
			'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
			'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'opacity', 'clip-path',
			'mask', 'vector-effect', 'stop-color', 'stop-opacity', 'color', 'font-family',
			'font-size', 'font-weight', 'letter-spacing', 'text-anchor', 'display', 'visibility',
		];
		$clean = [];
		foreach ( explode( ';', $style ) as $declaration ) {
			if ( ! str_contains( $declaration, ':' ) ) {
				continue;
			}
			[ $property, $value ] = array_map( 'trim', explode( ':', $declaration, 2 ) );
			$property = strtolower( $property );
			if ( ! in_array( $property, $allowed, true ) ) {
				continue;
			}
			$value = self::sanitize_attribute_value( $property, $value );
			if ( null !== $value && '' !== $value ) {
				$clean[] = $property . ':' . $value;
			}
		}
		return implode( ';', $clean );
	}

	private static function safe_fragment( string $value ): bool {
		return 1 === preg_match( '/^#[-A-Za-z0-9_:.]+$/', trim( $value ) );
	}

	private static function element_key( \DOMElement $element ): string {
		return strtolower( $element->localName ?: $element->tagName );
	}

	/** @return array<string,array{name:string,attributes:array<string,string>}> */
	private static function element_schema(): array {
		$common = [
			'id' => 'id', 'class' => 'class', 'style' => 'style', 'transform' => 'transform',
			'fill' => 'fill', 'fill-opacity' => 'fill-opacity', 'fill-rule' => 'fill-rule',
			'stroke' => 'stroke', 'stroke-width' => 'stroke-width', 'stroke-opacity' => 'stroke-opacity',
			'stroke-linecap' => 'stroke-linecap', 'stroke-linejoin' => 'stroke-linejoin', 'stroke-miterlimit' => 'stroke-miterlimit',
			'opacity' => 'opacity', 'clip-path' => 'clip-path', 'mask' => 'mask', 'vector-effect' => 'vector-effect',
			'display' => 'display', 'visibility' => 'visibility',
		];
		$element = static fn ( string $name, array $attributes = [] ): array => [
			'name' => $name,
			'attributes' => array_merge( $common, $attributes ),
		];

		return [
			'svg' => $element( 'svg', [
				'xmlns' => 'xmlns', 'xmlns:xlink' => 'xmlns:xlink', 'viewbox' => 'viewBox',
				'width' => 'width', 'height' => 'height', 'preserveaspectratio' => 'preserveAspectRatio',
				'role' => 'role', 'aria-hidden' => 'aria-hidden',
			] ),
			'g' => $element( 'g' ),
			'defs' => $element( 'defs' ),
			'path' => $element( 'path', [ 'd' => 'd', 'pathlength' => 'pathLength' ] ),
			'rect' => $element( 'rect', [ 'x' => 'x', 'y' => 'y', 'width' => 'width', 'height' => 'height', 'rx' => 'rx', 'ry' => 'ry' ] ),
			'circle' => $element( 'circle', [ 'cx' => 'cx', 'cy' => 'cy', 'r' => 'r' ] ),
			'ellipse' => $element( 'ellipse', [ 'cx' => 'cx', 'cy' => 'cy', 'rx' => 'rx', 'ry' => 'ry' ] ),
			'line' => $element( 'line', [ 'x1' => 'x1', 'y1' => 'y1', 'x2' => 'x2', 'y2' => 'y2' ] ),
			'polyline' => $element( 'polyline', [ 'points' => 'points' ] ),
			'polygon' => $element( 'polygon', [ 'points' => 'points' ] ),
			'lineargradient' => $element( 'linearGradient', [
				'x1' => 'x1', 'y1' => 'y1', 'x2' => 'x2', 'y2' => 'y2', 'gradientunits' => 'gradientUnits',
				'gradienttransform' => 'gradientTransform', 'spreadmethod' => 'spreadMethod', 'href' => 'href', 'xlink:href' => 'xlink:href',
			] ),
			'radialgradient' => $element( 'radialGradient', [
				'cx' => 'cx', 'cy' => 'cy', 'r' => 'r', 'fx' => 'fx', 'fy' => 'fy', 'fr' => 'fr',
				'gradientunits' => 'gradientUnits', 'gradienttransform' => 'gradientTransform', 'spreadmethod' => 'spreadMethod',
				'href' => 'href', 'xlink:href' => 'xlink:href',
			] ),
			'stop' => $element( 'stop', [ 'offset' => 'offset', 'stop-color' => 'stop-color', 'stop-opacity' => 'stop-opacity' ] ),
			'clippath' => $element( 'clipPath', [ 'clippathunits' => 'clipPathUnits' ] ),
			'mask' => $element( 'mask', [
				'x' => 'x', 'y' => 'y', 'width' => 'width', 'height' => 'height',
				'maskunits' => 'maskUnits', 'maskcontentunits' => 'maskContentUnits',
			] ),
			'symbol' => $element( 'symbol', [ 'viewbox' => 'viewBox', 'preserveaspectratio' => 'preserveAspectRatio' ] ),
			'use' => $element( 'use', [ 'href' => 'href', 'xlink:href' => 'xlink:href', 'x' => 'x', 'y' => 'y', 'width' => 'width', 'height' => 'height' ] ),
			'text' => $element( 'text', [
				'x' => 'x', 'y' => 'y', 'dx' => 'dx', 'dy' => 'dy', 'text-anchor' => 'text-anchor',
				'font-family' => 'font-family', 'font-size' => 'font-size', 'font-weight' => 'font-weight', 'letter-spacing' => 'letter-spacing',
			] ),
			'tspan' => $element( 'tspan', [ 'x' => 'x', 'y' => 'y', 'dx' => 'dx', 'dy' => 'dy' ] ),
			'title' => $element( 'title' ),
			'desc' => $element( 'desc' ),
		];
	}

	private function __construct() {}
}
