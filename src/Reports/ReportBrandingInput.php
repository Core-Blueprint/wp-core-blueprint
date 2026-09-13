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

		$provider_name    = sanitize_text_field( (string) ( $input['provider_name'] ?? '' ) );
		$provider_contact = sanitize_text_field( (string) ( $input['provider_contact'] ?? '' ) );

		if ( mb_strlen( $provider_name ) > 120 ) {
			$provider_name = mb_substr( $provider_name, 0, 120 );
		}
		if ( mb_strlen( $provider_contact ) > 200 ) {
			$provider_contact = mb_substr( $provider_contact, 0, 200 );
		}

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

	private function __construct() {}
}
