<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Shared OUTPUT SECURITY projection for already-rendered provider HTML.
 * This does not interpret a template document or render any domain nodes.
 * Only providers may call it after doing their own synthetic-data rendering.
 *
 * The Browser must still use an opaque iframe with a deny-all CSP (TL-1C-C).
 */
final class PreviewHtmlProjection {
	private const MAX_RENDERED_BYTES = 262144;

	/** Remove active document wrapper and all non-inert elements and attributes. */
	public static function from_rendered_html( string $version, string $html ): TemplatePreview {
		if ( '' === $html || strlen( $html ) > self::MAX_RENDERED_BYTES ) {
			throw new \InvalidArgumentException( 'Rendered preview exceeds its budget.' );
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$dom = new \DOMDocument( '1.0', 'UTF-8' );
			if ( ! $dom->loadHTML( '<?xml encoding="UTF-8"?>' . $html,
				LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
				throw new \InvalidArgumentException( 'Unable to parse rendered preview.' );
			}
			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body instanceof \DOMElement ) {
				throw new \InvalidArgumentException( 'Rendered preview body is missing.' );
			}
			// Body CSS is inert once filtered. No raw document element may be
			// inserted into the parent Browser or the eventual iframe.
			$fragment = '<div style="' . esc_attr( $body->getAttribute( 'style' ) ) . '">';
			foreach ( $body->childNodes as $child ) {
				$fragment .= $dom->saveHTML( $child );
			}
			$fragment .= '</div>';
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$allowed = [];
		foreach ( [
			'div', 'span', 'p', 'br', 'strong', 'b', 'em', 'i',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'small', 'blockquote',
			'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot',
			'tr', 'td', 'th', 'hr', 'pre', 'code',
		] as $tag ) {
			$allowed[ $tag ] = [ 'class' => true, 'style' => true, 'dir' => true, 'lang' => true ];
		}
		// This step is a strict, narrowly allowlisted output projection, not
		// authorization or a substitute for TemplatePreview's independent check.
		return new TemplatePreview( $version, wp_kses( $fragment, $allowed, [] ) );
	}

	private function __construct() {}
}
