<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Storage\ChunkedOptionStore;
use CoreBlueprint\Core\Integrity\Storage\StorageSchema;

use function is_array;

/**
 * Persistence for the single active resumable integrity scan job.
 *
 * ScannerLock guarantees that only one job may exist at a time, so a fixed
 * non-autoloaded option avoids an unbounded collection of dynamic options.
 */
final class ScanJobRepository {
	private const OPTION = 'cb_core_integrity_scan_job';

	public static function save( array $job ): void {
		if ( ! StorageSchema::is_scan_job( $job ) ) {
			throw new \InvalidArgumentException( 'Core Scanner job does not match Scanner Storage Schema 1.' );
		}
		if ( ! ChunkedOptionStore::set( self::OPTION, $job ) ) {
			throw new \RuntimeException( 'Core Scanner could not persist its resumable job state safely.' );
		}
	}

	public static function get(): ?array {
		$job = ChunkedOptionStore::get( self::OPTION, null );
		return StorageSchema::is_scan_job( $job ) ? $job : null;
	}

	public static function get_by_id( string $job_id ): ?array {
		$job = self::get();
		if ( ! is_array( $job ) || $job_id !== (string) ( $job['job_id'] ?? '' ) ) {
			return null;
		}
		return $job;
	}

	public static function clear( string $job_id = '' ): void {
		if ( '' !== $job_id ) {
			$job = self::get();
			if ( ! is_array( $job ) || $job_id !== (string) ( $job['job_id'] ?? '' ) ) {
				return;
			}
		}
		ChunkedOptionStore::delete( self::OPTION );
	}
}
