<?php
declare(strict_types=1);

namespace CB\Core\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical normalizer for Reports branding input.
 *
 * Save and Designer preview must resolve the same bounded logo, provider and
 * accent values. Persistence remains owned by the AJAX save handler.
 */
final class ReportBrandingInput {

	/**
	 * @param array<string,mixed> $input
	 * @return array{logo_attachment_id:int,provider_name:string,provider_contact:string,accent_color:string}
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
				throw new \InvalidArgumentException(
					__( 'Logo must be a local JPEG, PNG or SVG image no larger than 2 MB. Raster logos may be at most 4096 x 4096 pixels.', 'core-blueprint' )
				);
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
			'provider_name'       => $provider_name,
			'provider_contact'    => $provider_contact,
			'accent_color'        => $accent_color,
		];
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
