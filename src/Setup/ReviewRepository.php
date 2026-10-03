<?php
declare(strict_types=1);
/**
 * Persistence for Core Setup lifecycle and human review intent.
 *
 * This repository stores no canonical module configuration. Only bounded Setup
 * metadata that cannot be derived from the owning modules belongs here:
 * lifecycle origin, review fingerprints/dispositions, and section annotations.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;
defined( 'ABSPATH' ) || exit;

final class ReviewRepository {

	public const OPTION         = 'cb_core_setup_state';
	public const SCHEMA_VERSION = 1;

	public const ORIGIN_FIRST_INSTALL    = 'first_install';
	public const ORIGIN_EXISTING_INSTALL = 'existing_install';
	public const ORIGINS = [ self::ORIGIN_FIRST_INSTALL, self::ORIGIN_EXISTING_INSTALL ];

	public const REVIEWED       = 'reviewed';
	public const LATER          = 'later';
	public const NOT_APPLICABLE = 'not_applicable';
	public const DISPOSITIONS   = [ self::REVIEWED, self::LATER, self::NOT_APPLICABLE ];

	private const MAX_REASON_LENGTH = 1000;
	private const MAX_NOTE_LENGTH   = 4000;

	/**
	 * @return array{
	 *   schema_version:int,
	 *   lifecycle:array{origin:string,initialized_at:int,started_at:int,started_by:int},
	 *   checks:array<string,array<string,mixed>>,
	 *   sections:array<string,array<string,mixed>>
	 * }
	 */
	public static function state(): array {
		return self::normalize_state( get_option( self::OPTION, [] ) );
	}

	/** @return array{origin:string,initialized_at:int,started_at:int,started_by:int} */
	public static function lifecycle(): array {
		return self::state()['lifecycle'];
	}

	public static function initialize_lifecycle( string $origin, int $initialized_at = 0 ): bool {
		if ( ! in_array( $origin, self::ORIGINS, true ) ) {
			return false;
		}

		$state = self::state();
		if ( in_array( $state['lifecycle']['origin'], self::ORIGINS, true ) ) {
			return true;
		}

		$state['lifecycle'] = [
			'origin'         => $origin,
			'initialized_at' => $initialized_at > 0 ? $initialized_at : time(),
			'started_at'     => 0,
			'started_by'     => 0,
		];

		return self::persist( $state );
	}

	public static function mark_started( int $user_id = 0, int $started_at = 0 ): bool {
		$state = self::state();
		if ( $state['lifecycle']['started_at'] > 0 ) {
			return true;
		}
		if ( ! in_array( $state['lifecycle']['origin'], self::ORIGINS, true ) ) {
			return false;
		}

		$state['lifecycle']['started_at'] = $started_at > 0 ? $started_at : time();
		$state['lifecycle']['started_by'] = max( 0, $user_id );

		return self::persist( $state );
	}

	/** @return array<string,mixed>|null */
	public static function check( string $check_id ): ?array {
		if ( ! self::valid_id( $check_id ) ) {
			return null;
		}
		$state = self::state();
		$row = $state['checks'][ $check_id ] ?? null;
		return is_array( $row ) ? $row : null;
	}

	public static function mark_reviewed( string $check_id, Evidence $evidence, int $user_id = 0 ): bool {
		return self::record( $check_id, self::REVIEWED, $evidence, '', $user_id );
	}

	public static function mark_later( string $check_id, Evidence $evidence, string $reason = '', int $user_id = 0 ): bool {
		return self::record( $check_id, self::LATER, $evidence, $reason, $user_id );
	}

	public static function mark_not_applicable( string $check_id, Evidence $evidence, string $reason = '', int $user_id = 0 ): bool {
		return self::record( $check_id, self::NOT_APPLICABLE, $evidence, $reason, $user_id );
	}

	public static function clear_check( string $check_id ): bool {
		if ( ! self::valid_id( $check_id ) ) {
			return false;
		}
		$state = self::state();
		if ( ! isset( $state['checks'][ $check_id ] ) ) {
			return true;
		}
		unset( $state['checks'][ $check_id ] );
		return self::persist( $state );
	}

	/** @return array{note:string,updated_at:int,updated_by:int}|null */
	public static function section_note( string $section_id ): ?array {
		if ( ! self::valid_id( $section_id ) ) {
			return null;
		}
		$state = self::state();
		$row = $state['sections'][ $section_id ] ?? null;
		return is_array( $row ) ? $row : null;
	}

	public static function save_section_note( string $section_id, string $note, int $user_id = 0 ): bool {
		if ( ! self::valid_id( $section_id ) ) {
			return false;
		}

		$state = self::state();
		$note  = self::sanitize_bounded_text( $note, self::MAX_NOTE_LENGTH );
		$current = is_array( $state['sections'][ $section_id ] ?? null )
			? (string) ( $state['sections'][ $section_id ]['note'] ?? '' )
			: '';

		if ( $current === $note ) {
			return true;
		}

		if ( '' === $note ) {
			unset( $state['sections'][ $section_id ] );
		} else {
			$state['sections'][ $section_id ] = [
				'note'       => $note,
				'updated_at' => time(),
				'updated_by' => max( 0, $user_id ),
			];
		}

		return self::persist( $state );
	}

	private static function record( string $check_id, string $disposition, Evidence $evidence, string $reason, int $user_id ): bool {
		if ( ! self::valid_id( $check_id ) || ! in_array( $disposition, self::DISPOSITIONS, true ) ) {
			return false;
		}

		$state  = self::state();
		$reason = self::sanitize_bounded_text( $reason, self::MAX_REASON_LENGTH );
		$next   = [
			'disposition' => $disposition,
			'fingerprint' => $evidence->fingerprint(),
			'reason'      => $reason,
			'updated_by'  => max( 0, $user_id ),
		];
		$current = is_array( $state['checks'][ $check_id ] ?? null ) ? $state['checks'][ $check_id ] : null;

		if (
			is_array( $current )
			&& (string) ( $current['disposition'] ?? '' ) === $next['disposition']
			&& (string) ( $current['fingerprint'] ?? '' ) === $next['fingerprint']
			&& (string) ( $current['reason'] ?? '' ) === $next['reason']
			&& (int) ( $current['updated_by'] ?? 0 ) === $next['updated_by']
		) {
			return true;
		}

		$state['checks'][ $check_id ] = $next + [ 'updated_at' => time() ];
		return self::persist( $state );
	}

	/**
	 * @param mixed $raw
	 * @return array{
	 *   schema_version:int,
	 *   lifecycle:array{origin:string,initialized_at:int,started_at:int,started_by:int},
	 *   checks:array<string,array<string,mixed>>,
	 *   sections:array<string,array<string,mixed>>
	 * }
	 */
	private static function normalize_state( mixed $raw ): array {
		$default = [
			'schema_version' => self::SCHEMA_VERSION,
			'lifecycle'      => [
				'origin'         => '',
				'initialized_at' => 0,
				'started_at'     => 0,
				'started_by'     => 0,
			],
			'checks'         => [],
			'sections'       => [],
		];
		if ( ! is_array( $raw ) ) {
			return $default;
		}

		$version = isset( $raw['schema_version'] ) ? (int) $raw['schema_version'] : 0;
		if ( self::SCHEMA_VERSION !== $version ) {
			return $default;
		}

		$lifecycle_raw = is_array( $raw['lifecycle'] ?? null ) ? $raw['lifecycle'] : [];
		$origin = isset( $lifecycle_raw['origin'] ) && is_string( $lifecycle_raw['origin'] )
			? $lifecycle_raw['origin']
			: '';
		if ( ! in_array( $origin, self::ORIGINS, true ) ) {
			$origin = '';
		}
		$lifecycle = [
			'origin'         => $origin,
			'initialized_at' => max( 0, (int) ( $lifecycle_raw['initialized_at'] ?? 0 ) ),
			'started_at'     => max( 0, (int) ( $lifecycle_raw['started_at'] ?? 0 ) ),
			'started_by'     => max( 0, (int) ( $lifecycle_raw['started_by'] ?? 0 ) ),
		];
		if ( '' === $origin ) {
			$lifecycle = $default['lifecycle'];
		} elseif ( 0 === $lifecycle['initialized_at'] ) {
			$lifecycle['initialized_at'] = $lifecycle['started_at'];
		}
		if ( 0 === $lifecycle['started_at'] ) {
			$lifecycle['started_by'] = 0;
		}

		$checks = [];
		foreach ( is_array( $raw['checks'] ?? null ) ? $raw['checks'] : [] as $id => $row ) {
			$id = (string) $id;
			if ( ! self::valid_id( $id ) || ! is_array( $row ) ) {
				continue;
			}
			$disposition = isset( $row['disposition'] ) && is_string( $row['disposition'] ) ? $row['disposition'] : '';
			$fingerprint = isset( $row['fingerprint'] ) && is_string( $row['fingerprint'] ) ? strtolower( $row['fingerprint'] ) : '';
			if ( ! in_array( $disposition, self::DISPOSITIONS, true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
				continue;
			}
			$checks[ $id ] = [
				'disposition' => $disposition,
				'fingerprint' => $fingerprint,
				'reason'      => self::sanitize_bounded_text( (string) ( $row['reason'] ?? '' ), self::MAX_REASON_LENGTH ),
				'updated_at'  => max( 0, (int) ( $row['updated_at'] ?? 0 ) ),
				'updated_by'  => max( 0, (int) ( $row['updated_by'] ?? 0 ) ),
			];
		}

		$sections = [];
		foreach ( is_array( $raw['sections'] ?? null ) ? $raw['sections'] : [] as $id => $row ) {
			$id = (string) $id;
			if ( ! self::valid_id( $id ) || ! is_array( $row ) ) {
				continue;
			}
			$note = self::sanitize_bounded_text( (string) ( $row['note'] ?? '' ), self::MAX_NOTE_LENGTH );
			if ( '' === $note ) {
				continue;
			}
			$sections[ $id ] = [
				'note'       => $note,
				'updated_at' => max( 0, (int) ( $row['updated_at'] ?? 0 ) ),
				'updated_by' => max( 0, (int) ( $row['updated_by'] ?? 0 ) ),
			];
		}

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'lifecycle'      => $lifecycle,
			'checks'         => $checks,
			'sections'       => $sections,
		];
	}

	private static function persist( array $state ): bool {
		$normalized = self::normalize_state( $state );
		update_option( self::OPTION, $normalized, false );
		return $normalized === self::normalize_state( get_option( self::OPTION, [] ) );
	}

	private static function sanitize_bounded_text( string $value, int $max_length ): string {
		$value = sanitize_textarea_field( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length );
		}
		return substr( $value, 0, $max_length );
	}

	private static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
	}

	private function __construct() {}
}
