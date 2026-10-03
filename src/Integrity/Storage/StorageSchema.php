<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Storage;

use CoreBlueprint\Core\Integrity\Support\Finding;

use function array_key_exists;
use function is_array;

/** Canonical persisted Core Scanner storage contract for public v1. */
final class StorageSchema {
	public const VERSION = 1;

	public static function is_current( mixed $payload ): bool {
		return is_array( $payload ) && self::VERSION === (int) ( $payload['storage_schema'] ?? 0 );
	}

	public static function is_scan_result( mixed $payload ): bool {
		return self::is_current( $payload )
			&& is_array( $payload['checks'] ?? null )
			&& ! array_key_exists( 'findings', $payload )
			&& self::has_canonical_checks( $payload['checks'] );
	}

	public static function is_scan_job( mixed $payload ): bool {
		return self::is_current( $payload )
			&& '' !== (string) ( $payload['job_id'] ?? '' )
			&& is_array( $payload['checks'] ?? null )
			&& ! array_key_exists( 'findings', $payload )
			&& self::has_canonical_checks( $payload['checks'] );
	}

	public static function is_baseline( mixed $payload ): bool {
		return self::is_current( $payload )
			&& is_array( $payload['entries'] ?? null )
			&& ! array_key_exists( 'checks', $payload )
			&& ! array_key_exists( 'findings', $payload )
			&& self::has_canonical_baseline_entries( $payload['entries'] );
	}

	public static function is_history_entry( mixed $payload ): bool {
		return self::is_current( $payload )
			&& '' !== (string) ( $payload['id'] ?? '' )
			&& is_array( $payload['summary'] ?? null )
			&& is_array( $payload['components'] ?? null );
	}

	private static function has_canonical_baseline_entries( array $entries ): bool {
		foreach ( $entries as $entry ) {
			if (
				! is_array( $entry )
				|| '' === (string) ( $entry['id'] ?? '' )
				|| '' === (string) ( $entry['type'] ?? '' )
				|| ! is_array( $entry['target'] ?? null )
				|| ! is_array( $entry['meta'] ?? null )
				|| ! is_array( $entry['manifest'] ?? null )
			) {
				return false;
			}

			foreach ( [ 'component', 'slug', 'label', 'file', 'path', 'relative_path', 'component_root', 'context' ] as $forbidden ) {
				if ( array_key_exists( $forbidden, $entry ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function has_canonical_checks( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( ! self::is_finding( $check ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_finding( mixed $finding ): bool {
		if (
			! is_array( $finding )
			|| Finding::SCHEMA_VERSION !== (int) ( $finding['finding_schema'] ?? 0 )
			|| '' === (string) ( $finding['type'] ?? '' )
			|| '' === (string) ( $finding['status'] ?? '' )
			|| ! is_array( $finding['target'] ?? null )
			|| ! is_array( $finding['meta'] ?? null )
		) {
			return false;
		}

		foreach ( [ 'component', 'slug', 'label', 'file', 'path', 'relative_path', 'component_root', 'context', 'identity', 'details' ] as $forbidden ) {
			if ( array_key_exists( $forbidden, $finding ) ) {
				return false;
			}
		}

		$children = is_array( $finding['children'] ?? null ) ? $finding['children'] : [];
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) || ! is_array( $child['target'] ?? null ) ) {
				return false;
			}
			foreach ( [ 'file', 'path', 'relative_path', 'context' ] as $forbidden ) {
				if ( array_key_exists( $forbidden, $child ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
