<?php
declare(strict_types=1);
/**
 * Front-end shortcode access to configured compliance resources.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Compliance;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG = 'cb_compliance_resource';

	public static function register(): void {
		add_shortcode( self::TAG, [ self::class, 'render' ] );
	}

	/** @param array<string,mixed>|string $atts */
	public static function render( array|string $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'id'     => '',
				'format' => 'link',
				'label'  => '',
				'locale' => '',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);

		$key    = is_string( $atts['id'] ) ? trim( $atts['id'] ) : '';
		$format = is_string( $atts['format'] ) ? sanitize_key( $atts['format'] ) : 'link';
		$locale = is_string( $atts['locale'] ) ? Resolver::normalize_locale( $atts['locale'] ) : '';
		if ( '' === $key ) {
			return '';
		}

		$definition = ResourceRegistry::get( $key );
		$resolved   = Resolver::resolve( $key, '' === $locale ? null : $locale );
		if ( null === $definition || null === $resolved ) {
			return '';
		}

		$url = $resolved['url'];
		if ( 'url' === $format ) {
			return esc_url( $url );
		}
		if ( 'link' !== $format ) {
			return '';
		}

		$label = is_string( $atts['label'] ) ? trim( wp_strip_all_tags( $atts['label'] ) ) : '';
		if ( '' === $label ) {
			$label = (string) $definition['label'];
		}
		return sprintf(
			'<a class="cb-compliance-resource-link" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
