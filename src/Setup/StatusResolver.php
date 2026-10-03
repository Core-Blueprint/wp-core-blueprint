<?php
declare(strict_types=1);
/**
 * Deterministic Core Setup status resolver.
 *
 * Live evidence always wins over historical review intent. A matching review
 * fingerprint is required before an otherwise healthy check can be considered
 * Configured, Later, or Not applicable.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;
defined( 'ABSPATH' ) || exit;

final class StatusResolver {

	public const CONFIGURED     = 'configured';
	public const NEEDS_REVIEW   = 'needs_review';
	public const ATTENTION      = 'attention';
	public const LATER          = 'later';
	public const NOT_APPLICABLE = 'not_applicable';
	public const STATES = [ self::CONFIGURED, self::NEEDS_REVIEW, self::ATTENTION, self::LATER, self::NOT_APPLICABLE ];

	/**
	 * Resolve one check from current canonical evidence plus optional review metadata.
	 *
	 * @param array<string,mixed>|null $review
	 */
	public static function resolve( CheckInterface $check, ?Evidence $evidence = null, ?array $review = null ): string {
		try {
			$evidence ??= $check->evidence();
		} catch ( \Throwable $e ) {
			$evidence = Evidence::unavailable( 'setup.provider-unavailable' );
		}

		if ( Evidence::HEALTH_OK !== $evidence->health() ) {
			return self::ATTENTION;
		}

		$review ??= ReviewRepository::check( $check->id() );
		if ( ! is_array( $review ) ) {
			return self::NEEDS_REVIEW;
		}

		$fingerprint = isset( $review['fingerprint'] ) && is_string( $review['fingerprint'] )
			? strtolower( $review['fingerprint'] )
			: '';
		if ( ! hash_equals( $evidence->fingerprint(), $fingerprint ) ) {
			return self::NEEDS_REVIEW;
		}

		$disposition = isset( $review['disposition'] ) && is_string( $review['disposition'] )
			? $review['disposition']
			: '';

		if ( ReviewRepository::NOT_APPLICABLE === $disposition ) {
			return $check->allows_not_applicable( $evidence ) ? self::NOT_APPLICABLE : self::NEEDS_REVIEW;
		}
		if ( ReviewRepository::LATER === $disposition ) {
			return $check->allows_later() ? self::LATER : self::NEEDS_REVIEW;
		}
		if ( ReviewRepository::REVIEWED === $disposition ) {
			return self::CONFIGURED;
		}

		return self::NEEDS_REVIEW;
	}

	private function __construct() {}
}
