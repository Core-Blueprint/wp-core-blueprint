<?php
declare(strict_types=1);
/**
 * BackupDiscovery - report-facing discovery for full-site backup sources.
 *
 * Reports intentionally owns this narrow read-only boundary so maintenance
 * reporting does not depend on the optional Hub/Beacon extension. Core
 * Blueprint ships a native All-in-One WP Migration reader because it is a
 * common full-site recovery source; extensions may contribute additional
 * read-only sources through `cb_core_reports_backup_sources`.
 *
 * Source shape:
 *   [
 *     'slug'    => string,
 *     'label'   => string,
 *     'backups' => [ [ 'created' => ISO-8601 string ], ... ],
 *   ]
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Reports;

defined( 'ABSPATH' ) || exit;

final class BackupDiscovery {

	/**
	 * Return configured full-site backup sources.
	 *
	 * @return array<int,array{slug:string,label:string,backups:array<int,array<string,mixed>>}>
	 */
	public static function sources(): array {
		$sources = [];

		$ai1wm = self::ai1wm_source();
		if ( null !== $ai1wm ) {
			$sources[] = $ai1wm;
		}

		/**
		 * Filter read-only backup sources visible to Maintenance Reports.
		 *
		 * This is deliberately separate from any remote-backup transport API.
		 * A provider can participate in reporting without exposing start/delete/
		 * download actions to another system.
		 *
		 * @param array<int,array<string,mixed>> $sources Current sources.
		 */
		$filtered = apply_filters( 'cb_core_reports_backup_sources', $sources );
		if ( ! is_array( $filtered ) ) {
			return $sources;
		}

		return self::normalize_sources( $filtered );
	}

	/**
	 * Native All-in-One WP Migration source.
	 */
	private static function ai1wm_source(): ?array {
		if ( ! class_exists( 'Ai1wm_Export_Controller' ) ) {
			return null;
		}

		$backups = [];
		if ( defined( 'AI1WM_BACKUPS_PATH' ) && is_dir( AI1WM_BACKUPS_PATH ) ) {
			$files = glob( AI1WM_BACKUPS_PATH . DIRECTORY_SEPARATOR . '*.wpress' ) ?: [];
			foreach ( $files as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}
				$mtime = filemtime( $file );
				if ( false === $mtime ) {
					continue;
				}
				$backups[] = [
					'created'  => gmdate( 'c', (int) $mtime ),
					'filename' => basename( $file ),
					'size'     => (int) filesize( $file ),
				];
			}
		}

		return [
			'slug'    => 'ai1wm',
			'label'   => 'Full Backup (All-in-One WP Migration)',
			'backups' => $backups,
		];
	}

	/**
	 * Defensively normalize extension-provided sources.
	 */
	private static function normalize_sources( array $sources ): array {
		$out = [];
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$slug  = sanitize_key( (string) ( $source['slug'] ?? '' ) );
			$label = trim( (string) ( $source['label'] ?? '' ) );
			if ( '' === $slug || '' === $label ) {
				continue;
			}

			$backups = is_array( $source['backups'] ?? null ) ? $source['backups'] : [];
			$out[] = [
				'slug'    => $slug,
				'label'   => $label,
				'backups' => array_values( array_filter( $backups, 'is_array' ) ),
			];
		}

		return $out;
	}
}
