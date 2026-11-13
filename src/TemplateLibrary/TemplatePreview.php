<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Version-bound, inert HTML FRAGMENT only. No HTML document, CSS stylesheet,
 * script, link, image, form, URL-bearing attribute or active markup.
 * The TL-1C-C client MUST additionally use an opaque sandboxed iframe with
 * an embedded deny-all CSP before presenting this untrusted fragment.
 *
 * TL-1C v1 intentionally ships with no assets/remote media support. Any
 * future inline media capability requires its own security qualification.
 */
final class TemplatePreview {
	public const MAX_HTML_BYTES = 65536;
	private const MAX_VERSION_BYTES = 48;

	public readonly string $version;
	public readonly string $html;

	public function __construct( string $version, string $html ) {
		if ( '' === $html || strlen( $html ) > self::MAX_HTML_BYTES
			|| strlen( $version ) > self::MAX_VERSION_BYTES
			|| 1 !== preg_match( '/^[1-9][0-9]*(?:\.[0-9]+){0,2}$/D', $version )
			|| 1 !== preg_match( '//u', $html )
			|| preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $html ) ) {
			throw new \InvalidArgumentException( 'Invalid template preview boundary.' );
		}
		$allowed = [
			'div', 'span', 'p', 'br', 'strong', 'b', 'em', 'i',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'small', 'blockquote',
			'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot',
			'tr', 'td', 'th', 'hr', 'pre', 'code',
		];
		$tags = [];
		foreach ( $allowed as $tag ) {
			$tags[$tag] = [ 'class' => true, 'style' => true, 'dir' => true, 'lang' => true ];
		}
		// Strict equality is intentional: invalid active markup is refused,
		// not silently stripped into an apparently valid preview.
		if ( wp_kses( $html, $tags, [] ) !== $html ) {
			throw new \InvalidArgumentException( 'Unsafe template preview markup.' );
		}
		$this->version = $version;
		$this->html = $html;
	}
}
