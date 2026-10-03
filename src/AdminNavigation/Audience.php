<?php
declare(strict_types=1);
/**
 * Role/capability audience matcher for Admin Navigation presentation policy.
 *
 * Audience matching never grants or revokes WordPress capabilities. It only
 * decides whether a presentation rule applies to the current user.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\AdminNavigation;

defined( 'ABSPATH' ) || exit;

final class Audience {

	private const MAX_REFERENCES = 32;
	private const MAX_REFERENCE_BYTES = 191;

	/**
	 * Normalize one bounded audience declaration.
	 *
	 * Match semantics are deliberately exact:
	 * (roles empty OR any listed role matches)
	 * AND
	 * (capabilities empty OR every listed capability passes current_user_can()).
	 *
	 * Unknown role/capability references are preserved. They naturally fail the
	 * relevant match clause at runtime, keeping hide/rename rules fail-open.
	 *
	 * @return array{roles:list<string>,capabilities:list<string>}
	 */
	public static function normalize( array $audience ): array {
		self::assert_exact_keys( $audience, [ 'roles', 'capabilities' ] );

		return [
			'roles'        => self::normalize_references( $audience['roles'] ?? null, 'role' ),
			'capabilities' => self::normalize_references( $audience['capabilities'] ?? null, 'capability' ),
		];
	}

	/** Whether the current user matches one normalized audience. */
	public static function matches( array $audience ): bool {
		$audience = self::normalize( $audience );

		if ( [] !== $audience['roles'] ) {
			$user = wp_get_current_user();
			$roles = $user instanceof \WP_User ? array_values( array_map( 'strval', (array) $user->roles ) ) : [];
			if ( [] === array_intersect( $audience['roles'], $roles ) ) {
				return false;
			}
		}

		foreach ( $audience['capabilities'] as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return list<string> */
	private static function normalize_references( mixed $references, string $type ): array {
		if ( ! is_array( $references ) || ! array_is_list( $references ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( sprintf( 'Admin Navigation audience %s references must be a list.', $type ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( count( $references ) > self::MAX_REFERENCES ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( sprintf( 'Admin Navigation audience contains too many %s references.', $type ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$normalized = [];
		foreach ( $references as $reference ) {
			if ( ! is_string( $reference ) || '' === $reference || strlen( $reference ) > self::MAX_REFERENCE_BYTES || sanitize_key( $reference ) !== $reference ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( sprintf( 'Admin Navigation audience contains an invalid %s reference.', $type ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$normalized[ $reference ] = $reference;
		}

		return array_values( $normalized );
	}

	private static function assert_exact_keys( array $value, array $allowed ): void {
		$actual = array_map( 'strval', array_keys( $value ) );
		$expected = array_values( array_map( 'strval', $allowed ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new \InvalidArgumentException( 'Admin Navigation audience has an invalid schema.' );
		}
	}

	private function __construct() {}
}
