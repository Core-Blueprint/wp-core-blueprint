<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Mail\ProjectRenderer;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreview;

defined( 'ABSPATH' ) || exit;

/**
 * Mail-owned, read-only TL-1C projection of the canonical ProjectRenderer.
 *
 * This is NOT a second template renderer: the Mail project is rendered by the
 * production renderer once, using exclusively literal synthetic bindings.
 * The DOM projection removes all navigation/media/active content, preserving
 * bounded inert layout where possible. Only TemplatePreview validates what
 * may cross into the shared Base preview transport.
 */
final class LibraryPreview {
	private const MAX_RENDERED_BYTES = 262144;

	/** Never read actor/site data, option values, users, email addresses or tokens. */
	private const SAMPLE_BINDINGS = [
		'site.name'        => 'Example Site',
		'site.url'         => 'https://example.invalid',
		'site.admin_email' => 'admin@example.invalid',
		'user.display_name'=> 'Example User',
		'user.login'       => 'example-user',
		'user.email'       => 'user@example.invalid',
		'contact.first_name'=> 'Example',
		'action.url'       => 'https://example.invalid/action',
	];

	/** @param array{project:array<string,mixed>,fields:array<string,mixed>} $document */
	public static function render( array $document, string $version ): TemplatePreview {
		if ( ! isset( $document['project'], $document['fields'] )
			|| ! is_array( $document['project'] ) || ! is_array( $document['fields'] ) ) {
			throw new \InvalidArgumentException( 'Invalid Mail preview document.' );
		}
		$result = ( new ProjectRenderer() )->render(
			$document['project'], $document['fields'], self::SAMPLE_BINDINGS
		);
		$html = $result['html'];
		if ( '' === $html || strlen( $html ) > self::MAX_RENDERED_BYTES ) {
			throw new \InvalidArgumentException( 'Rendered Mail preview exceeds its budget.' );
		}

		// Only parse the already-rendered native Mail HTML. Never load external
		// DTDs/URLs and never use the original HTML as srcdoc or in the DOM.
		$previous = libxml_use_internal_errors( true );
		try {
			$dom = new \DOMDocument( '1.0', 'UTF-8' );
			if ( ! $dom->loadHTML( '<?xml encoding="UTF-8"?>' . $html,
				LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
				throw new \InvalidArgumentException( 'Cannot parse native Mail preview.' );
			}
			$bodies = $dom->getElementsByTagName( 'body' );
			$body = $bodies->item( 0 );
			if ( ! $body instanceof \DOMElement ) {
				throw new \InvalidArgumentException( 'Native Mail preview body is missing.' );
			}
			$fragment = '<div style="' . esc_attr( $body->getAttribute( 'style' ) ) . '">';
			foreach ( $body->childNodes as $child ) {
				$fragment .= $dom->saveHTML( $child );
			}
			$fragment .= '</div>';
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		// Remove URL-bearing media, anchors, forms, scripts, comments and all
		// disallowed attributes in the Mail-owning boundary. Base's immutable
		// TemplatePreview DTO independently rejects any residual active HTML.
		$tags = [];
		foreach ( [
			'div', 'span', 'p', 'br', 'strong', 'b', 'em', 'i',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'small', 'blockquote',
			'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot',
			'tr', 'td', 'th', 'hr', 'pre', 'code',
		] as $tag ) {
			$tags[ $tag ] = [ 'class' => true, 'style' => true, 'dir' => true, 'lang' => true ];
		}
		$fragment = wp_kses( $fragment, $tags, [] );
		return new TemplatePreview( $version, $fragment );
	}

	private function __construct() {}
}
