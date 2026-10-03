<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical normalizer for Reports branding and bounded appearance input.
 *
 * Save and Designer preview must resolve the same logo, provider and
 * presentation values. Persistence remains owned by the AJAX save handler.
 */
final class ReportBrandingInput {

	private const SURFACE_STYLES = [ 'cards', 'flat' ];
	private const DENSITIES      = [ 'compact', 'comfortable' ];
	private const CORNER_STYLES  = [ 'square', 'soft', 'rounded' ];
	private const TEXT_SCALES    = [ 'compact', 'standard', 'large' ];

	/**
	 * @param array<string,mixed> $input
	 * @return array{
	 *   logo_attachment_id:int,
	 *   show_logo:bool,
	 *   provider_name:string,
	 *   provider_contact:string,
	 *   accent_color:string,
	 *   surface_style:string,
	 *   density:string,
	 *   corner_style:string,
	 *   text_scale:string
	 * }
	 */
	public static function normalize( array $input ): array {
		$logo_id = max( 0, (int) ( $input['logo_attachment_id'] ?? 0 ) );

		if ( $logo_id > 0 ) {
			$post = get_post( $logo_id );
			if (
				! $post
				|| 'attachment' !== $post->post_type
				|| ! ReportBranding::is_supported_logo_attachment( $logo_id )
			) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException(
					__( 'Logo must be a local JPEG, PNG or SVG image no larger than 2 MB. Raster logos may be at most 4096 x 4096 pixels.', 'core-blueprint' )
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		$provider_name    = self::truncate_text( sanitize_text_field( (string) ( $input['provider_name'] ?? '' ) ), 120 );
		$provider_contact = self::truncate_text( sanitize_text_field( (string) ( $input['provider_contact'] ?? '' ) ), 200 );

		$accent_color = sanitize_hex_color( (string) ( $input['accent_color'] ?? '' ) );
		if ( null === $accent_color || '' === $accent_color ) {
			$accent_color = ReportBranding::DEFAULT_ACCENT;
		}

		return [
			'logo_attachment_id' => $logo_id,
			'show_logo'          => self::normalize_bool( $input['show_logo'] ?? true, true ),
			'provider_name'      => $provider_name,
			'provider_contact'   => $provider_contact,
			'accent_color'       => $accent_color,
			'surface_style'      => self::normalize_choice( $input['surface_style'] ?? 'cards', self::SURFACE_STYLES, 'cards' ),
			'density'            => self::normalize_choice( $input['density'] ?? 'comfortable', self::DENSITIES, 'comfortable' ),
			'corner_style'       => self::normalize_choice( $input['corner_style'] ?? 'soft', self::CORNER_STYLES, 'soft' ),
			'text_scale'         => self::normalize_choice( $input['text_scale'] ?? 'standard', self::TEXT_SCALES, 'standard' ),
		];
	}

	/** @param mixed $value @param string[] $allowed */
	private static function normalize_choice( mixed $value, array $allowed, string $fallback ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function normalize_bool( mixed $value, bool $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $parsed ? $fallback : (bool) $parsed;
	}

	/**
	 * Unicode-aware truncation without making ext-mbstring a hidden runtime
	 * requirement. sanitize_text_field() has already removed invalid UTF-8.
	 */
	private static function truncate_text( string $value, int $max_chars ): string {
		if ( '' === $value || $max_chars <= 0 ) {
			return '';
		}

		$matched = preg_match_all( '/./us', $value, $characters );
		if ( false === $matched ) {
			return substr( $value, 0, $max_chars );
		}
		if ( $matched <= $max_chars ) {
			return $value;
		}

		return implode( '', array_slice( $characters[0], 0, $max_chars ) );
	}

	private function __construct() {}
}
