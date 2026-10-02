<?php
declare(strict_types=1);
/**
 * Core Scanner - Result Repository
 *
 * Persistence layer for Core Scanner. Three concerns:
 *
 *   1. Latest scan result   - single option, autoload off (large payload).
 *   2. Scan history         - capped list, autoload off.
 *   3. Approved baseline    - operator-approved hash snapshot, autoload off.
 *
 * Configuration (schedule, scan toggles, max_visible_findings) lives in
 * the central `cb_core_settings['integrity']` subkey via {@see \CB\Core\Settings},
 * NOT in a separate option. Read via {@see settings()}, write via
 * {@see saveSettings()} which delegates to Settings::set_key().
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Integrity\Storage;

use CB\Core\Integrity\Support\DirectoryHasher;
use CB\Core\Integrity\Support\PathGuard;
use CB\Core\Integrity\Support\ResultFormatter;
use CB\Core\Settings;

use function array_filter;
use function array_merge;
use function array_slice;
use function array_unshift;
use function array_values;
use function count;
use function current_time;
use function delete_option;
use function get_option;
use function get_current_user_id;
use function in_array;
use function is_array;
use function max;
use function min;
use function sanitize_key;
use function update_option;

use const CB_CORE_VERSION;

defined( 'ABSPATH' ) || exit;

final class ResultRepository {

	// ─── Option keys (autoload=off; payloads are large) ───────────────────
	private const OPTION_LATEST       = 'cb_core_integrity_latest';
	private const OPTION_BASELINE     = 'cb_core_integrity_baseline';
	private const OPTION_SCAN_HISTORY = 'cb_core_integrity_history';

	private const HISTORY_LIMIT = 10;

	// ─── Latest scan result ───────────────────────────────────────────────

	public static function saveLatest( array $result ): void {
		$result['storage_schema'] = StorageSchema::VERSION;
		$result['plugin_version'] = CB_CORE_VERSION;
		if ( ! StorageSchema::is_scan_result( $result ) ) {
			throw new \InvalidArgumentException( 'Core Scanner result does not match Scanner Storage Schema 1.' );
		}
		if ( ! ChunkedOptionStore::set( self::OPTION_LATEST, $result ) ) {
			throw new \RuntimeException( 'Core Scanner could not persist the completed scan result safely.' );
		}
		self::saveToHistory( $result );
	}

	public static function getLatest(): ?array {
		$result = ChunkedOptionStore::get( self::OPTION_LATEST, null );
		return StorageSchema::is_scan_result( $result ) ? $result : null;
	}

	public static function clear(): void {
		ChunkedOptionStore::delete( self::OPTION_LATEST );
	}

	public static function hasResult(): bool {
		return is_array( self::getLatest() );
	}

	public static function getSummary(): array {
		return ResultFormatter::summary( self::getLatest() );
	}

	// ─── Scan history (capped) ────────────────────────────────────────────

	/** Persist one compact Schema 1 scan summary to the bounded history. */
	public static function saveToHistory( array $result ): void {
		$history = self::getHistory();
		$entry   = [
			'storage_schema' => StorageSchema::VERSION,
			'id'             => (string) ( $result['completed_at'] ?? $result['timestamp'] ?? current_time( 'mysql' ) ),
			'plugin_version' => CB_CORE_VERSION,
			'timestamp'      => (string) ( $result['completed_at'] ?? $result['timestamp'] ?? current_time( 'mysql' ) ),
			'source'         => (string) ( $result['source'] ?? '' ),
			'status'         => (string) ( $result['status'] ?? 'idle' ),
			'summary'        => is_array( $result['summary'] ?? null ) ? $result['summary'] : [],
			'components'     => is_array( $result['components'] ?? null ) ? $result['components'] : [],
			'completion'     => (string) ( $result['completion'] ?? 'unknown' ),
			'coverage'       => self::historyCoverage( $result ),
		];

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::HISTORY_LIMIT );

		update_option( self::OPTION_SCAN_HISTORY, $history, false );
	}

	/** Read current Scanner Storage Schema 1 history entries only. */
	public static function getHistory(): array {
		$history = get_option( self::OPTION_SCAN_HISTORY, [] );
		if ( ! is_array( $history ) ) {
			return [];
		}

		return array_values( array_filter( $history, [ StorageSchema::class, 'is_history_entry' ] ) );
	}

	public static function getLatestFromHistory(): ?array {
		$history = self::getHistory();
		$latest  = $history[0] ?? null;
		return is_array( $latest ) ? $latest : null;
	}

	/**
	 * Keep only compact coverage counters in scan history. Full per-component
	 * diagnostics remain on the latest result so the bounded history option
	 * cannot grow with filesystem issue-path samples.
	 */
	private static function historyCoverage( array $result ): array {
		$coverage = is_array( $result['coverage'] ?? null ) ? $result['coverage'] : [];
		$out = [
			'state' => (string) ( $coverage['state'] ?? $result['completion'] ?? 'unknown' ),
			'incomplete_components' => is_array( $coverage['incomplete_components'] ?? null ) ? $coverage['incomplete_components'] : [],
		];

		foreach ( [ 'core', 'plugins', 'themes', 'uploads' ] as $component ) {
			if ( ! is_array( $coverage[ $component ] ?? null ) ) {
				continue;
			}

			$component_coverage = $coverage[ $component ];
			$out[ $component ] = [
				'state'             => (string) ( $component_coverage['state'] ?? 'unknown' ),
				'files_observed'    => (int) ( $component_coverage['files_observed'] ?? $component_coverage['files_inspected'] ?? 0 ),
				'verified_files'    => (int) ( $component_coverage['verified_files'] ?? 0 ),
				'snapshot_files_inspected' => (int) ( $component_coverage['snapshot_files_inspected'] ?? 0 ),
				'modified_files'    => (int) ( $component_coverage['modified_files'] ?? 0 ),
				'missing_files'     => (int) ( $component_coverage['missing_files'] ?? 0 ),
				'unexpected_files'  => (int) ( $component_coverage['unexpected_files'] ?? 0 ),
				'unreadable_files'  => (int) ( $component_coverage['unreadable_files'] ?? $component_coverage['unreadable'] ?? 0 ),
				'symlinks_skipped'  => (int) ( $component_coverage['symlinks_skipped'] ?? 0 ),
				'filesystem_errors' => (int) ( $component_coverage['filesystem_errors'] ?? $component_coverage['errors'] ?? 0 ),
			];
		}

		return $out;
	}

	// ─── Approved baseline ────────────────────────────────────────────────

	public static function saveBaseline( array $result ): array {
		$existing_baseline = self::getBaseline();
		$existing_entries  = is_array( $existing_baseline['entries'] ?? null ) ? $existing_baseline['entries'] : [];
		$candidate_entries = [];
		$rejected          = [];
		$seen              = [];

		// Validate every selected candidate first. No baseline state is mutated
		// until the complete replacement set has been proven approval-safe.
		foreach ( ResultFormatter::checks( $result ) as $check ) {
			if ( ! is_array( $check ) || ! self::isBaselineCandidateCheck( $check ) ) {
				continue;
			}

			$identity = self::baselineEntryIdentity( $check );
			if ( '' === $identity ) {
				$rejected[] = self::baselineEntryIdFromCheck( $check );
				continue;
			}
			if ( isset( $seen[ $identity ] ) ) {
				$rejected[] = $identity;
				continue;
			}
			$seen[ $identity ] = true;

			$entry = self::baselineEntryFromCheck( $check );
			if ( null === $entry ) {
				$rejected[] = $identity;
				continue;
			}

			$candidate_entries[ $identity ] = $entry;
		}

		if ( [] !== $rejected || [] === $candidate_entries ) {
			return [
				'_baseline_saved' => false,
				'rejected'        => array_values( array_unique( array_filter( $rejected ) ) ),
				'entry_count'     => count( $existing_entries ),
			];
		}

		// Existing-baseline updates are an atomic merge: preserve every
		// unselected entry byte-semantically and replace only matching
		// component identities. Initial approval simply starts from empty.
		$next_entries = $existing_entries;
		foreach ( $candidate_entries as $identity => $entry ) {
			foreach ( $next_entries as $id => $existing ) {
				if ( is_array( $existing ) && $identity === self::baselineEntryIdentity( $existing ) ) {
					unset( $next_entries[ $id ] );
				}
			}
			$next_entries[ $entry['id'] ] = $entry;
		}

		$now     = current_time( 'mysql' );
		$user_id = get_current_user_id();
		if ( is_array( $existing_baseline ) ) {
			$baseline = $existing_baseline;
			$baseline['updated_at']  = $now;
			$baseline['updated_by']  = $user_id;
			$baseline['plugin_version'] = CB_CORE_VERSION;
			$baseline['summary']     = ResultFormatter::summary( $result );
		} else {
			$baseline = [
				'storage_schema' => StorageSchema::VERSION,
				'plugin_version' => CB_CORE_VERSION,
				'created_at'     => $now,
				'approved_at'    => $now,
				'approved_by'    => $user_id,
				'summary'        => ResultFormatter::summary( $result ),
			];
		}
		$baseline['entries']     = $next_entries;
		$baseline['entry_count'] = count( $next_entries );

		if ( ! ChunkedOptionStore::set( self::OPTION_BASELINE, $baseline ) ) {
			throw new \RuntimeException( 'Core Scanner could not persist the approved baseline safely.' );
		}
		$baseline['_baseline_saved'] = true;

		return $baseline;
	}

	public static function saveBaselineComponent( array $result, string $type, string $slug ): array {
		$type     = sanitize_key( $type );
		$slug     = sanitize_key( $slug );
		$baseline = self::getBaseline();

		if ( ! is_array( $baseline ) ) {
			$baseline = [
				'storage_schema' => StorageSchema::VERSION,
				'plugin_version' => CB_CORE_VERSION,
				'created_at'     => current_time( 'mysql' ),
				'approved_at'    => current_time( 'mysql' ),
				'approved_by'    => get_current_user_id(),
				'summary'        => ResultFormatter::summary( $result ),
				'entries'        => [],
				'entry_count'    => 0,
			];
		}

		$entries = is_array( $baseline['entries'] ?? null ) ? $baseline['entries'] : [];
		$entry   = null;

		foreach ( ResultFormatter::checks( $result ) as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}

			$check_type = sanitize_key( (string) ( $check['type'] ?? '' ) );
			$target     = is_array( $check['target'] ?? null ) ? $check['target'] : [];
			$check_slug = sanitize_key( (string) ( $target['slug'] ?? '' ) );

			if ( $check_type !== $type || $check_slug !== $slug ) {
				continue;
			}

			$entry = self::baselineEntryFromCheck( $check );
			if ( null !== $entry ) {
				break;
			}
		}

		if ( null === $entry ) {
			$baseline['_component_saved'] = false;
			return $baseline;
		}

		// A component has exactly one approved local baseline. Use the same
		// canonical identity as bulk update and explicit removal.
		$identity = $type . ':' . $slug;
		foreach ( $entries as $id => $existing ) {
			if ( is_array( $existing ) && $identity === self::baselineEntryIdentity( $existing ) ) {
				unset( $entries[ $id ] );
			}
		}

		$entries[ $entry['id'] ] = $entry;
		$baseline['updated_at']  = current_time( 'mysql' );
		$baseline['updated_by']  = get_current_user_id();
		$baseline['entries']     = $entries;
		$baseline['entry_count'] = count( $entries );

		if ( ! ChunkedOptionStore::set( self::OPTION_BASELINE, $baseline ) ) {
			throw new \RuntimeException( 'Core Scanner could not persist the approved baseline safely.' );
		}
		$baseline['_component_saved'] = true;
		return $baseline;
	}

	/**
	 * Remove every approved-baseline entry whose type+slug match the
	 * supplied identifiers.
	 *
	 * Returned shape mirrors saveBaselineComponent: the updated baseline
	 * array, or null when no baseline option exists. When the baseline
	 * exists but no entry matches, returns the baseline unchanged so
	 * callers don't need to special-case "nothing to remove".
	 *
	 * Use case: a plugin or theme that was previously approved into the
	 * baseline has been intentionally removed from the site (e.g. merged
	 * into another plugin, retired, replaced). Without this method the
	 * baseline keeps reporting `missing` at critical severity for every
	 * subsequent scan, with no in-product way to clear the entry short
	 * of approving the entire baseline anew.
	 */
	public static function removeBaselineComponent( string $type, string $slug ): ?array {
		$type     = sanitize_key( $type );
		$slug     = sanitize_key( $slug );
		$baseline = self::getBaseline();

		if ( ! is_array( $baseline ) ) {
			return null;
		}

		$entries = is_array( $baseline['entries'] ?? null ) ? $baseline['entries'] : [];
		$removed = 0;

		$identity = $type . ':' . $slug;
		foreach ( $entries as $id => $entry ) {
			if ( is_array( $entry ) && $identity === self::baselineEntryIdentity( $entry ) ) {
				unset( $entries[ $id ] );
				$removed++;
			}
		}

		if ( 0 === $removed ) {
			return $baseline;
		}

		$baseline['updated_at']  = current_time( 'mysql' );
		$baseline['updated_by']  = get_current_user_id();
		$baseline['entries']     = $entries;
		$baseline['entry_count'] = count( $entries );

		if ( ! ChunkedOptionStore::set( self::OPTION_BASELINE, $baseline ) ) {
			throw new \RuntimeException( 'Core Scanner could not persist the approved baseline safely.' );
		}

		return $baseline;
	}

	/**
	 * Preflight a full baseline approval. Baselines are only trustworthy when
	 * every candidate component can be snapshotted completely.
	 */
	public static function baselineApprovalEligibility( array $result ): array {
		$candidates = 0;
		$eligible   = 0;
		$rejected   = [];
		$seen       = [];

		foreach ( ResultFormatter::checks( $result ) as $check ) {
			if ( ! is_array( $check ) || ! self::isBaselineCandidateCheck( $check ) ) {
				continue;
			}

			$id = self::baselineEntryIdFromCheck( $check );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$candidates++;

			if ( null !== self::baselineEntryFromCheck( $check ) ) {
				$eligible++;
				continue;
			}

			$target = is_array( $check['target'] ?? null ) ? $check['target'] : [];
			$rejected[] = [
				'id'   => $id,
				'type' => sanitize_key( (string) ( $check['type'] ?? '' ) ),
				'slug' => sanitize_key( (string) ( $target['slug'] ?? '' ) ),
			];
		}

		return [
			'candidates' => $candidates,
			'eligible'   => $eligible,
			'rejected'   => $rejected,
			'complete'   => $candidates > 0 && $candidates === $eligible,
		];
	}

	public static function baselineComponentEligibility( array $result, string $type, string $slug ): array {
		$type = sanitize_key( $type );
		$slug = sanitize_key( $slug );

		foreach ( ResultFormatter::checks( $result ) as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$target = is_array( $check['target'] ?? null ) ? $check['target'] : [];
			$check_type = sanitize_key( (string) ( $check['type'] ?? '' ) );
			$check_slug = sanitize_key( (string) ( $target['slug'] ?? '' ) );

			if ( $type !== $check_type || $slug !== $check_slug ) {
				continue;
			}

			return [
				'found'        => true,
				'eligible'     => null !== self::baselineEntryFromCheck( $check ),
				'candidate_id' => self::baselineCandidateId( $check ),
			];
		}

		return [ 'found' => false, 'eligible' => false, 'candidate_id' => '' ];
	}

	public static function getBaseline(): ?array {
		$baseline = ChunkedOptionStore::get( self::OPTION_BASELINE, null );
		return StorageSchema::is_baseline( $baseline ) ? $baseline : null;
	}

	public static function hasBaseline(): bool {
		$baseline = self::getBaseline();
		$entries  = is_array( $baseline['entries'] ?? null ) ? $baseline['entries'] : [];
		return [] !== $entries;
	}

	public static function componentBaselineMeta( string $type, string $slug ): array {
		$type     = sanitize_key( $type );
		$slug     = sanitize_key( $slug );
		$baseline = self::getBaseline();

		if ( ! is_array( $baseline ) ) {
			return [ 'exists' => false ];
		}

		$entries = is_array( $baseline['entries'] ?? null ) ? $baseline['entries'] : [];

		$identity = $type . ':' . $slug;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || $identity !== self::baselineEntryIdentity( $entry ) ) {
				continue;
			}

			return [
				'exists'      => true,
				'approved_at' => (string) ( $entry['approved_at'] ?? $baseline['updated_at'] ?? $baseline['approved_at'] ?? $baseline['created_at'] ?? '' ),
				'approved_by' => (int) ( $entry['approved_by'] ?? $baseline['updated_by'] ?? $baseline['approved_by'] ?? 0 ),
				'version'     => (string) ( $entry['plugin_version'] ?? $baseline['plugin_version'] ?? CB_CORE_VERSION ),
			];
		}

		return [ 'exists' => false ];
	}

	public static function hasBaselineComponent( string $type, string $slug ): bool {
		$meta = self::componentBaselineMeta( $type, $slug );
		return ! empty( $meta['exists'] );
	}

	public static function clearBaseline(): void {
		ChunkedOptionStore::delete( self::OPTION_BASELINE );
	}

	// ─── Settings - delegate to central CB Base settings ──────────────────

	/**
	 * Read Core Scanner configuration from the central CB Base settings
	 * array. Returns the merged shape (defaults overlaid by stored values)
	 * so callers can rely on every key being present - including `enabled`,
	 * so callers can rely on every canonical setting key being present.
	 *
	 * @return array{enabled:bool,schedule:string,plugin_checksums:bool,theme_checksums:bool,uploads_scan:bool,max_visible_findings:int}
	 */
	public static function settings(): array {
		$cb_settings = Settings::get();
		$stored      = is_array( $cb_settings['integrity'] ?? null ) ? $cb_settings['integrity'] : [];

		return array_merge( self::settingsDefaults(), $stored );
	}

	public static function saveSettings( array $settings ): void {
		$current = self::settings();
		$next    = array_merge( $current, $settings );

		$allowed_schedules = [ 'disabled', 'daily', 'weekly' ];
		if ( ! in_array( $next['schedule'], $allowed_schedules, true ) ) {
			$next['schedule'] = 'disabled';
		}

		$next['enabled']              = (bool) ( $next['enabled'] ?? false );
		$next['plugin_checksums']     = (bool) $next['plugin_checksums'];
		$next['theme_checksums']      = (bool) $next['theme_checksums'];
		$next['uploads_scan']         = (bool) $next['uploads_scan'];
		$next['max_visible_findings'] = max( 10, min( 200, (int) $next['max_visible_findings'] ) );

		Settings::set_key( 'integrity', $next, 'integrity' );
	}

	/**
	 * Default Core Scanner configuration. Used as the merge base in
	 * settings() and as the seed value when CB Base bootstraps the
	 * `integrity` subkey on first activation.
	 *
	 * @return array{enabled:bool,schedule:string,plugin_checksums:bool,theme_checksums:bool,uploads_scan:bool,max_visible_findings:int}
	 */
	public static function settingsDefaults(): array {
		return [
			'enabled'              => false,
			'schedule'             => 'disabled',
			'plugin_checksums'     => true,
			'theme_checksums'      => true,
			'uploads_scan'         => true,
			'max_visible_findings' => 50,
		];
	}

	// ─── Internals ────────────────────────────────────────────────────────

	/**
	 * Return the stable baseline candidate identifier used by approval and review flows.
	 */
	public static function baselineCandidateId( array $check ): string {
		if ( ! self::isBaselineCandidateCheck( $check ) ) {
			return '';
		}

		return self::baselineEntryIdFromCheck( $check );
	}

	/**
	 * Return the deduplicated candidate identifiers for one completed scan.
	 * Review progress intentionally uses the exact same identity boundary as
	 * baseline approval.
	 *
	 * @return list<string>
	 */
	public static function baselineCandidateIds( array $result ): array {
		$ids = [];
		foreach ( ResultFormatter::checks( $result ) as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$id = self::baselineCandidateId( $check );
			if ( '' !== $id ) {
				$ids[ $id ] = true;
			}
		}

		return array_keys( $ids );
	}

	public static function isBaselineCandidateCheck( array $check ): bool {
		$type         = sanitize_key( (string) ( $check['type'] ?? '' ) );
		$status       = sanitize_key( (string) ( $check['status'] ?? '' ) );
		$verification = is_array( $check['verification'] ?? null ) ? $check['verification'] : [];
		$method       = sanitize_key( (string) ( $verification['method'] ?? '' ) );
		$scope        = sanitize_key( (string) ( $verification['scope'] ?? '' ) );

		return in_array( $type, [ 'plugin', 'theme' ], true )
			&& 'local_baseline' === $method
			&& 'component' === $scope
			&& in_array( $status, [ 'baseline_required', 'verification_failed', 'new', 'changed' ], true );
	}

	private static function isBaselineEligibleCheck( array $check ): bool {
		if ( ! self::isBaselineCandidateCheck( $check ) ) {
			return false;
		}

		$meta = is_array( $check['meta'] ?? null ) ? $check['meta'] : [];
		if ( array_key_exists( 'fingerprint_complete', $meta ) && empty( $meta['fingerprint_complete'] ) ) {
			return false;
		}

		return true;
	}

	/** Return the canonical component identity shared by baseline update/remove flows. */
	private static function baselineEntryIdentity( array $record ): string {
		$type   = sanitize_key( (string) ( $record['type'] ?? '' ) );
		$target = is_array( $record['target'] ?? null ) ? $record['target'] : [];
		$slug   = sanitize_key( (string) ( $target['slug'] ?? '' ) );

		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || '' === $slug ) {
			return '';
		}

		return $type . ':' . $slug;
	}

	private static function baselineEntryIdFromCheck( array $check ): string {
		$meta = is_array( $check['meta'] ?? null ) ? $check['meta'] : [];
		$id   = (string) ( $meta['baseline_entry_id'] ?? $check['id'] ?? '' );
		return sanitize_key( $id );
	}

	/** Build an immutable local-baseline entry with a per-file SHA-256 manifest. */
	private static function baselineEntryFromCheck( array $check ): ?array {
		if ( ! self::isBaselineEligibleCheck( $check ) ) {
			return null;
		}

		$id   = self::baselineEntryIdFromCheck( $check );
		$meta = is_array( $check['meta'] ?? null ) ? $check['meta'] : [];
		$root = (string) ( $meta['filesystem_root'] ?? '' );
		if ( '' === $id || '' === $root || ! PathGuard::existing_path_is_inside( $root, ABSPATH ) ) {
			return null;
		}

		$manifest = is_array( $meta['baseline_manifest'] ?? null ) ? $meta['baseline_manifest'] : [];
		if ( empty( $meta['fingerprint_complete'] ) || [] === $manifest ) {
			return null;
		}

		// Approve the exact complete filesystem evidence captured by the latest
		// resumable scan. Do not synchronously re-hash a potentially huge component
		// inside the REST approval request. A verification scan is queued immediately
		// after approval and will surface any drift that happened after this snapshot.
		$snapshot = DirectoryHasher::snapshot_from_manifest( $manifest, true );
		$captured_hash = (string) ( $meta['fingerprint_hash'] ?? '' );
		if ( '' === $captured_hash || $captured_hash !== (string) ( $snapshot['hash'] ?? '' ) ) {
			return null;
		}

		$target = is_array( $check['target'] ?? null ) ? $check['target'] : [];
		unset( $meta['filesystem_root'], $meta['baseline_manifest'] );

		return [
			'id'           => $id,
			'hash'         => $captured_hash,
			'algorithm'    => 'sha256',
			'manifest'     => $manifest,
			'file_count'   => count( $manifest ),
			'type'         => sanitize_key( (string) ( $check['type'] ?? '' ) ),
			'status'       => (string) ( $check['status'] ?? '' ),
			'target'       => $target,
			'verification' => is_array( $check['verification'] ?? null ) ? $check['verification'] : [],
			'meta'         => $meta,
			'approved_at'  => current_time( 'mysql' ),
			'approved_by'  => get_current_user_id(),
		];
	}

}
