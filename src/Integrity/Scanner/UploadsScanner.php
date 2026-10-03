<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Support\BatchedFilesystemWalker;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Integrity\Support\FileHashProbe;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_readable;
use function is_string;
use function pathinfo;
use function preg_match;
use function sprintf;
use function strtolower;
use function wp_get_upload_dir;
use function wp_normalize_path;

/**
 * Uploads anomaly scanner.
 *
 * This is intentionally not a malware verdict engine. It reports filesystem
 * characteristics that are unusual for an uploads tree and gives operators the
 * exact location needed for manual investigation.
 *
 * The scanner supports resumable batches. A batch budget bounds one PHP request;
 * it never limits how many files a complete scan may inspect.
 */
final class UploadsScanner {
	private const EXECUTABLE_EXTENSIONS = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'cgi', 'pl', 'py', 'sh' ];
	private const SUSPICIOUS_PATTERNS   = [
		'/eval\s*\(/i',
		'/base64_decode\s*\(/i',
		'/shell_exec\s*\(/i',
		'/passthru\s*\(/i',
		'/assert\s*\(/i',
		'/gzinflate\s*\(/i',
	];

	/**
	 * Process one bounded uploads batch.
	 *
	 * @param array<string,mixed> $state State returned by the previous batch.
	 * @return array{done:bool,state:array<string,mixed>,status:string,checks:array,coverage:array}
	 */
	public function scan_batch( array $state = [], int $file_budget = 1000, float $seconds_budget = 5.0 ): array {
		$uploads  = wp_get_upload_dir();
		$base_dir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$findings = [];

		if ( '' === $base_dir || ! is_dir( $base_dir ) || ! is_readable( $base_dir ) ) {
			$findings[] = $this->finding( 'warning', 'unreadable', '', __( 'Uploads directory could not be read. The uploads scan is incomplete.', 'core-blueprint' ) );
			return [
				'done'     => true,
				'state'    => [ 'root' => $base_dir, 'done' => true, 'files_inspected' => 0 ],
				'status'   => 'warning',
				'checks'   => $findings,
				'coverage' => [
					'state'             => 'incomplete',
					'root'              => $base_dir,
					'files_encountered' => 0,
					'files_inspected'   => 0,
					'unreadable'        => 1,
					'symlinks_skipped'  => 0,
					'path_escapes'      => 0,
					'errors'            => 1,
				],
			];
		}

		if ( (string) ( $state['root'] ?? '' ) !== $base_dir ) {
			$state = [
				'root'            => $base_dir,
				'walker'          => [],
				'files_inspected' => 0,
			];
		}

		$inspected = max( 0, (int) ( $state['files_inspected'] ?? 0 ) );
		$walker_state = is_array( $state['walker'] ?? null ) ? $state['walker'] : [];

		$batch = BatchedFilesystemWalker::walk_batch(
			$base_dir,
			function ( string $relative, array $meta ) use ( &$findings, &$inspected ): void {
				$path      = (string) ( $meta['absolute_path'] ?? '' );
				$readable  = ! empty( $meta['readable'] );
				$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

				if ( ! $readable ) {
					$findings[] = $this->finding(
						'warning',
						'unreadable',
						$relative,
						__( 'Upload file exists but could not be read. This file was not inspected.', 'core-blueprint' ),
						$this->file_context( $path, $meta, false )
					);
					return;
				}

				$inspected++;
				if ( ! in_array( $extension, self::EXECUTABLE_EXTENSIONS, true ) ) {
					return;
				}

				$context = $this->file_context( $path, $meta, true );
				$findings[] = $this->finding(
					'warning',
					'executable_upload',
					$relative,
					__( 'Executable file detected inside uploads. This is unusual and should be reviewed.', 'core-blueprint' ),
					$context
				);

				if ( in_array( $extension, [ 'php', 'phtml', 'phar' ], true ) && $this->contains_suspicious_pattern( $path ) ) {
					$findings[] = $this->finding(
						'critical',
						'suspicious_pattern',
						$relative,
						__( 'Executable upload contains a suspicious PHP pattern. This is an anomaly, not a malware verdict; inspect the file manually before taking action.', 'core-blueprint' ),
						$context
					);
				}
			},
			$walker_state,
			$file_budget,
			$seconds_budget
		);

		foreach ( (array) ( $batch['issues'] ?? [] ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$type = (string) ( $issue['type'] ?? '' );
			$path = (string) ( $issue['path'] ?? '' );

			if ( 'unreadable_file' === $type ) {
				// The file callback already created a richer finding with metadata.
				continue;
			}
			if ( 'symlink' === $type ) {
				$findings[] = $this->finding( 'warning', 'symlink_skipped', $path, __( 'Symlink detected inside uploads. Core Scanner does not follow symlinks, so this path was not inspected.', 'core-blueprint' ) );
				continue;
			}
			if ( 'path_escape' === $type || 'invalid_path' === $type ) {
				$findings[] = $this->finding( 'critical', 'path_escape', $path, __( 'A filesystem entry resolved outside the uploads root and was skipped.', 'core-blueprint' ) );
				continue;
			}
			if ( 'unreadable_directory' === $type || 'root_unreadable' === $type ) {
				$findings[] = $this->finding( 'warning', 'unreadable', $path, __( 'A directory inside uploads could not be read. Coverage for this path is incomplete.', 'core-blueprint' ) );
				continue;
			}
			if ( 'filesystem_error' === $type ) {
				$findings[] = $this->finding( 'warning', 'scan_incomplete', $path, __( 'A filesystem error prevented Core Scanner from traversing this uploads path completely.', 'core-blueprint' ) );
			}
		}

		$walker_state = is_array( $batch['state'] ?? null ) ? $batch['state'] : [];
		$state['walker']          = $walker_state;
		$state['files_inspected'] = $inspected;

		$coverage = [
			'state'             => ! empty( $walker_state['done'] ) && ! empty( $walker_state['complete'] ) ? 'complete' : 'incomplete',
			'root'              => $base_dir,
			'files_encountered' => (int) ( $walker_state['files_encountered'] ?? 0 ),
			'files_inspected'   => $inspected,
			'unreadable'        => (int) ( $walker_state['unreadable_count'] ?? 0 ),
			'symlinks_skipped'  => (int) ( $walker_state['symlink_count'] ?? 0 ),
			'path_escapes'      => (int) ( $walker_state['outside_count'] ?? 0 ),
			'errors'            => (int) ( $walker_state['error_count'] ?? 0 ),
		];

		return [
			'done'     => ! empty( $batch['done'] ),
			'state'    => $state,
			'status'   => $this->status_from_findings( $findings ),
			'checks'   => $findings,
			'coverage' => $coverage,
		];
	}

	private function status_from_findings( array $findings ): string {
		$has_warning = false;
		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'ok' );
			if ( 'critical' === $severity ) {
				return 'critical';
			}
			$has_warning = $has_warning || 'warning' === $severity;
		}
		return $has_warning ? 'warning' : 'ok';
	}

	private function finding( string $severity, string $status, string $file, string $message, array $meta = [] ): array {
		$meta['identity'] = $status;

		return Finding::make( [
			'type'     => 'uploads',
			'status'   => $status,
			'severity' => $severity,
			'target'   => [
				'slug'  => 'uploads',
				'label' => __( 'Uploads', 'core-blueprint' ),
				'path'  => 'wp-content/uploads/',
				'file'  => $file,
			],
			'message'  => $message,
			'meta'     => $meta,
		] );
	}

	private function contains_suspicious_pattern( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$content = file_get_contents( $path, false, null, 0, 200000 );
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		foreach ( self::SUSPICIOUS_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $content ) ) {
				return true;
			}
		}

		return false;
	}

	private function file_context( string $path, array $meta, bool $with_hash = true ): array {
		$context = [
			'filesystem_path' => wp_normalize_path( $path ),
			'size_bytes'      => (int) ( $meta['size'] ?? 0 ),
			'modified_at'     => (int) ( $meta['mtime'] ?? 0 ),
			'actual_sha256'   => '',
		];

		if ( ! $with_hash || ! is_readable( $path ) ) {
			return $context;
		}

		$probe = FileHashProbe::probe( $path, [ 'sha256' ] );
		$context['size_bytes']  = (int) ( $probe['size'] ?? $context['size_bytes'] );
		$context['modified_at'] = (int) ( $probe['mtime'] ?? $context['modified_at'] );
		if ( empty( $probe['ok'] ) ) {
			$context['hash_unavailable_reason'] = (string) ( $probe['reason'] ?? 'hash_failed' );
			return $context;
		}

		$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
		$context['actual_sha256'] = (string) ( $hashes['sha256'] ?? '' );
		return $context;
	}
}
