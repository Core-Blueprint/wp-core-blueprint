<?php
declare(strict_types=1);

/**
 * Allowlisted Mail rich-text fragment. Never accept arbitrary editor HTML.
 *
 * @package Core_Blueprint
 */
namespace CoreBlueprint\Core\Design\Profile\Mail;

defined( 'ABSPATH' ) || exit;

final class RichText {
	private const MAX_SOURCE_BYTES = 100000;

	/**
	 * Escapes each dynamic scalar before replacing a text binding, then validates
	 * the complete fragment against a tiny email-safe, attribute-safe allowlist.
	 * In particular there is no style, image, script, data URL or event handler.
	 *
	 * @param array<string,scalar|null> $bindings
	 */
	public static function render( string $markup, array $bindings = [] ): string {
		if ( preg_match( '~<a\b[^>]*href\s*=\s*["\x27]\{\{action\.url\}\}["\x27]~i', $markup ) ) {
			$url = $bindings['action.url'] ?? null;
			if ( ! is_string( $url ) || ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true )
				|| false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
				throw new \InvalidArgumentException( 'Mail action binding is missing or unsafe.' );
			}
		}
		if ( strlen( $markup ) > self::MAX_SOURCE_BYTES ) {
			throw new \InvalidArgumentException( 'Mail rich text exceeds the maximum allowed size.' );
		}

		$escaped = [];
		foreach ( $bindings as $name => $value ) {
			$escaped[ $name ] = is_scalar( $value ) ? esc_html( (string) $value ) : '';
		}

		$resolved = BindingInterpolator::interpolate( $markup, $escaped );
		return wp_kses(
			$resolved,
			[
				'p'          => [],
				'br'         => [],
				'strong'     => [],
				'b'          => [],
				'em'         => [],
				'i'          => [],
				'u'          => [],
				'blockquote' => [],
				'ul'         => [],
				'ol'         => [],
				'li'         => [],
				'a'          => [ 'href' => true, 'title' => true ],
			],
			[ 'http', 'https', 'mailto' ]
		);
	}

	private function __construct() {}
}
