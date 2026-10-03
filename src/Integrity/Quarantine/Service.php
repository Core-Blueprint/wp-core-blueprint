<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Quarantine;

use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Support\Audit;
use CoreBlueprint\Core\Integrity\Support\FileHashProbe;
use CoreBlueprint\Core\Integrity\Support\PathGuard;
use CoreBlueprint\Core\Integrity\Support\ResultFormatter;
use RuntimeException;

use function basename;
use function bin2hex;
// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
use function chmod;
// phpcs:enable WordPress.WP.AlternativeFunctions
use function count;
use function current_time;
use function dirname;
use function file_exists;
use function file_get_contents;
use function fileperms;
use function filesize;
use function get_current_user_id;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function ltrim;
use function max;
use function pathinfo;
use function random_bytes;
use function realpath;
use function sanitize_key;
use function sanitize_text_field;
use function str_contains;
use function strlen;
use function strtolower;
use function substr;
// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
use function touch;
// phpcs:enable WordPress.WP.AlternativeFunctions
use function wp_get_upload_dir;
use function wp_normalize_path;

/**
 * Evidence-bound remediation service for Core Scanner findings.
 *
 * This is intentionally not a general file manager: quarantine may only start
 * from a current uploads finding whose exact scanned SHA-256 still matches the
 * current filesystem object.
 */
final class Service {
	private const REVIEW_STATES = [ 'awaiting_review', 'reviewed', 'keep_quarantined', 'marked_for_deletion' ];
	private const PREVIEW_BYTES = 120000;

	public static function can_quarantine_finding( array $finding ): bool {
		if ( 'uploads' !== (string) ( $finding['type'] ?? '' ) ) {
			return false;
		}
		$context = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$hash = strtolower( (string) ( $context['actual_sha256'] ?? '' ) );
		$path = (string) ( $context['filesystem_path'] ?? '' );
		return 64 === strlen( $hash ) && '' !== $path;
	}

	public static function directory_action_available( array $finding ): bool {
		if ( ! self::can_quarantine_finding( $finding ) ) {
			return false;
		}
		$target = is_array( $finding['target'] ?? null ) ? $finding['target'] : [];
		$file = str_replace( '\\', '/', (string) ( $target['file'] ?? '' ) );
		$dir = trim( dirname( $file ), './' );
		return '' !== $dir && ! str_contains( $dir, '/' ) && ! preg_match( '/^20\d{2}$/', $dir );
	}

	/** @return array<string,mixed> */
	public static function quarantine( string $finding_id, string $scope = 'file' ): array {
		$finding = self::current_finding( $finding_id );
		if ( null === $finding || ! self::can_quarantine_finding( $finding ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'This finding is no longer actionable. Run a fresh Core Scanner scan and try again.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$scope = 'directory' === $scope ? 'directory' : 'file';
		if ( 'directory' === $scope && ! self::directory_action_available( $finding ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'This directory is too broad or is not a safe top-level uploads folder. Quarantine the individual file instead.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$uploads = self::uploads_root();
		$context = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$expected_hash = strtolower( (string) $context['actual_sha256'] );
		$file_path = wp_normalize_path( (string) $context['filesystem_path'] );
		$resolved_file = realpath( $file_path );
		if ( ! is_string( $resolved_file ) || is_link( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The finding path no longer resolves to a normal file. Quarantine was refused.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$resolved_file = wp_normalize_path( $resolved_file );
		if ( ! PathGuard::is_inside( $resolved_file, $uploads ) || ! is_file( $resolved_file ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The finding path is outside the uploads root or is no longer a file.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$probe = FileHashProbe::probe( $resolved_file, [ 'sha256' ] );
		$probe_hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
		$actual_hash = strtolower( (string) ( $probe_hashes['sha256'] ?? '' ) );
		if ( empty( $probe['ok'] ) || '' === $actual_hash || ! hash_equals( $expected_hash, $actual_hash ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The file changed since the scan. Quarantine was refused; run Core Scanner again before taking action.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$source = $resolved_file;
		$kind = 'file';
		if ( 'directory' === $scope ) {
			$source = wp_normalize_path( dirname( $resolved_file ) );
			$kind = 'directory';
		}
		if ( is_link( $source ) || ! PathGuard::existing_path_is_inside( $source, $uploads ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine target did not pass canonical uploads containment checks.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$manifest = self::build_manifest( $source, $kind );
		if ( 'directory' === $kind ) {
			$relative_evidence = ltrim( substr( $resolved_file, strlen( $source ) ), '/' );
			$manifest_hash = strtolower( (string) ( $manifest['files'][ $relative_evidence ]['sha256'] ?? '' ) );
			if ( '' === $manifest_hash || ! hash_equals( $expected_hash, $manifest_hash ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'The directory evidence no longer matches the scanned finding. Quarantine was refused.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		$id = 'q_' . bin2hex( random_bytes( 8 ) );
		$relative_source = ltrim( substr( $source, strlen( rtrim( $uploads, '/' ) ) ), '/' );
		$payload = Vault::move_in( $source, $id, $kind, $uploads );

		$item = [
			'id'              => $id,
			'kind'            => $kind,
			'status'          => 'awaiting_review',
			'finding_id'      => (string) ( $finding['id'] ?? $finding_id ),
			'finding_type'    => (string) ( $finding['status'] ?? '' ),
			'finding_message' => (string) ( $finding['message'] ?? '' ),
			'original_path'   => $source,
			'relative_path'   => 'wp-content/uploads/' . $relative_source,
			'file_evidence_path' => $resolved_file,
			'evidence_sha256' => $expected_hash,
			'manifest'        => $manifest,
			'file_count'      => (int) ( $manifest['file_count'] ?? 0 ),
			'total_bytes'     => (int) ( $manifest['total_bytes'] ?? 0 ),
			'quarantined_at'  => current_time( 'mysql' ),
			'quarantined_by'  => get_current_user_id(),
			'updated_at'      => current_time( 'mysql' ),
			'notes'           => [],
			'events'          => [],
		];
		$item = Repository::append_event( $item, 'quarantined', [ 'scope' => $kind ] );

		if ( ! Repository::save( $item ) ) {
			// The source is already outside the active site. Roll it back if the
			// evidence record cannot be committed; do not leave an orphan payload.
			try {
				Vault::restore( $id, $kind, $source, $uploads );
				self::apply_manifest_metadata( $source, $kind, $manifest );
			} catch ( \Throwable $rollback_error ) {
				Audit::log( 'integrity_quarantine_rollback_failed', 'critical', [ 'quarantine_id' => $id, 'original_path' => $source, 'error' => $rollback_error->getMessage() ] );
			}
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine evidence could not be persisted safely. The filesystem action was rolled back where possible.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		Audit::log( 'integrity_quarantine_item_quarantined', 'warning', self::audit_context( $item ) );
		return $item;
	}

	/** @return array<string,mixed> */
	public static function restore( string $id ): array {
		return self::with_item_lock( $id, static function ( array $item, string $canonical_id ): array {
			if ( in_array( (string) $item['status'], [ 'restored', 'deleted', 'deleting', 'restoring' ], true ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'This quarantine item cannot be restored in its current state.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			self::validate_payload( $item );
			$uploads = self::uploads_root();
			$destination = self::safe_restore_destination( $item, $uploads );

			$transition = Repository::append_event( $item, 'restore_started' );
			$transition['status'] = 'restoring';
			if ( ! Repository::save( $transition ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Could not record the restore transition. No filesystem changes were made.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			try {
				Vault::restore( $canonical_id, (string) $item['kind'], $destination, $uploads );
				self::apply_manifest_metadata( $destination, (string) $item['kind'], (array) $item['manifest'] );
			} catch ( \Throwable $throwable ) {
				$transition['status'] = 'restore_failed';
				$transition = Repository::append_event( $transition, 'restore_failed', [ 'error' => $throwable->getMessage() ] );
				Repository::save( $transition );
				Audit::log( 'integrity_quarantine_restore_failed', 'critical', self::audit_context( $transition ) + [ 'error' => $throwable->getMessage() ] );
				throw $throwable;
			}

			$transition['status'] = 'restored';
			$transition['restored_at'] = current_time( 'mysql' );
			$transition['restored_by'] = get_current_user_id();
			$transition = Repository::append_event( $transition, 'restored' );
			if ( ! Repository::save( $transition ) ) {
				Audit::log( 'integrity_quarantine_restore_state_failed', 'critical', self::audit_context( $transition ) );
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'The file was restored, but Core Blueprint could not persist the final workspace state. Review the audit log before taking further action.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			Audit::log( 'integrity_quarantine_item_restored', 'warning', self::audit_context( $transition ) );
			return $transition;
		} );
	}

	/** @return array<string,mixed> */
	public static function delete_permanently( string $id ): array {
		return self::with_item_lock( $id, static function ( array $item, string $canonical_id ): array {
			if ( in_array( (string) $item['status'], [ 'restored', 'deleted', 'restoring' ], true ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'This quarantine item no longer has a deletable payload.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			self::validate_payload( $item );

			$item['status'] = 'deleting';
			$item = Repository::append_event( $item, 'delete_started' );
			if ( ! Repository::save( $item ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Could not record the permanent-delete transition. The payload was not deleted.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			try {
				Vault::delete_payload( $canonical_id );
			} catch ( \Throwable $throwable ) {
				$item['status'] = 'delete_failed';
				$item = Repository::append_event( $item, 'delete_failed', [ 'error' => $throwable->getMessage() ] );
				Repository::save( $item );
				Audit::log( 'integrity_quarantine_delete_failed', 'critical', self::audit_context( $item ) + [ 'error' => $throwable->getMessage() ] );
				throw $throwable;
			}

			$item['status'] = 'deleted';
			$item['deleted_at'] = current_time( 'mysql' );
			$item['deleted_by'] = get_current_user_id();
			$item = Repository::append_event( $item, 'deleted' );
			if ( ! Repository::save( $item ) ) {
				// The preceding persisted state remains `deleting`; importantly it does
				// not claim the payload is restorable after irreversible deletion.
				Audit::log( 'integrity_quarantine_delete_state_failed', 'critical', self::audit_context( $item ) );
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'The payload was permanently deleted, but Core Blueprint could not persist the final workspace state. The previous deleting state remains as an attention item.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			Audit::log( 'integrity_quarantine_item_deleted', 'warning', self::audit_context( $item ) + [ 'permanent' => true ] );
			return $item;
		} );
	}

	/** @return array<string,mixed> */
	public static function add_note( string $id, string $note ): array {
		return self::with_item_lock( $id, static function ( array $item ) use ( $note ): array {
			$clean_note = trim( sanitize_textarea_field( $note ) );
			if ( '' === $clean_note ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Note cannot be empty.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$notes = is_array( $item['notes'] ?? null ) ? $item['notes'] : [];
			$notes[] = [ 'text' => $clean_note, 'at' => current_time( 'mysql' ), 'by_user_id' => get_current_user_id() ];
			$item['notes'] = $notes;
			$item = Repository::append_event( $item, 'note_added' );
			if ( ! Repository::save( $item ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Could not save the quarantine note.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			Audit::log( 'integrity_quarantine_note_added', 'notice', self::audit_context( $item ) );
			return $item;
		} );
	}

	/** @return array<string,mixed> */
	public static function set_review_state( string $id, string $state ): array {
		return self::with_item_lock( $id, static function ( array $item ) use ( $state ): array {
			$clean_state = sanitize_key( $state );
			if ( ! in_array( $clean_state, self::REVIEW_STATES, true ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Unsupported quarantine review state.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( in_array( (string) ( $item['status'] ?? '' ), [ 'restored', 'deleted', 'deleting', 'restoring' ], true ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'This quarantine item can no longer change review state.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$previous = (string) ( $item['status'] ?? '' );
			$item['status'] = $clean_state;
			$item = Repository::append_event( $item, 'review_state_changed', [ 'from' => $previous, 'to' => $clean_state ] );
			if ( ! Repository::save( $item ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Could not update the quarantine review state.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			Audit::log( 'integrity_quarantine_review_state_changed', 'notice', self::audit_context( $item ) + [ 'from' => $previous, 'to' => $clean_state ] );
			return $item;
		} );
	}

	/** @return array<string,mixed> */
	public static function inspect( string $id, string $file = '' ): array {
		$item = self::require_item( $id );
		$response = [
			'item' => self::public_item( $item ),
			'preview' => '',
			'preview_file' => '',
			'preview_truncated' => false,
		];
		if ( in_array( (string) ( $item['status'] ?? '' ), [ 'restored', 'deleted', 'restoring', 'deleting' ], true ) ) {
			return $response;
		}
		self::validate_payload( $item );
		$canonical_id = (string) ( $item['id'] ?? '' );
		$payload = Vault::payload_path( $canonical_id, (string) $item['kind'] );
		$target = $payload;
		if ( 'directory' === (string) $item['kind'] ) {
			$file = self::normalise_manifest_file( $file );
			if ( '' === $file ) {
				$file = (string) array_key_first( (array) ( $item['manifest']['files'] ?? [] ) );
			}
			if ( '' === $file || ! isset( $item['manifest']['files'][ $file ] ) ) {
				return $response;
			}
			$target = wp_normalize_path( $payload . '/' . $file );
			if ( ! PathGuard::existing_path_is_inside( $target, $payload ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Preview path failed quarantine containment checks.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$response['preview_file'] = $file;
		} else {
			$response['preview_file'] = basename( (string) $item['original_path'] );
		}
		if ( ! is_file( $target ) || ! is_readable( $target ) || ! self::is_text_preview_candidate( $response['preview_file'] ) ) {
			return $response;
		}
		$content = file_get_contents( $target, false, null, 0, self::PREVIEW_BYTES + 1 );
		if ( ! is_string( $content ) ) {
			return $response;
		}
		$response['preview_truncated'] = strlen( $content ) > self::PREVIEW_BYTES;
		$response['preview'] = substr( $content, 0, self::PREVIEW_BYTES );
		return $response;
	}

	public static function public_item( array $item ): array {
		$manifest = is_array( $item['manifest'] ?? null ) ? $item['manifest'] : [];
		return [
			'id' => (string) ( $item['id'] ?? '' ),
			'kind' => (string) ( $item['kind'] ?? '' ),
			'status' => (string) ( $item['status'] ?? '' ),
			'finding_id' => (string) ( $item['finding_id'] ?? '' ),
			'finding_type' => (string) ( $item['finding_type'] ?? '' ),
			'finding_message' => (string) ( $item['finding_message'] ?? '' ),
			'relative_path' => (string) ( $item['relative_path'] ?? '' ),
			'evidence_sha256' => (string) ( $item['evidence_sha256'] ?? '' ),
			'file_count' => (int) ( $item['file_count'] ?? 0 ),
			'total_bytes' => (int) ( $item['total_bytes'] ?? 0 ),
			'quarantined_at' => (string) ( $item['quarantined_at'] ?? '' ),
			'quarantined_by' => (int) ( $item['quarantined_by'] ?? 0 ),
			'updated_at' => (string) ( $item['updated_at'] ?? '' ),
			'restored_at' => (string) ( $item['restored_at'] ?? '' ),
			'deleted_at' => (string) ( $item['deleted_at'] ?? '' ),
			'notes' => is_array( $item['notes'] ?? null ) ? $item['notes'] : [],
			'events' => is_array( $item['events'] ?? null ) ? $item['events'] : [],
			'files' => array_keys( is_array( $manifest['files'] ?? null ) ? $manifest['files'] : [] ),
		];
	}

	/** @return array<string,mixed>|null */
	private static function current_finding( string $id ): ?array {
		$latest = ResultRepository::getLatest();
		foreach ( ResultFormatter::checks( $latest ) as $finding ) {
			if ( is_array( $finding ) && hash_equals( (string) ( $finding['id'] ?? '' ), $id ) ) {
				return $finding;
			}
		}
		return null;
	}

	private static function uploads_root(): string {
		$uploads = wp_get_upload_dir();
		$root = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		$resolved = realpath( $root );
		if ( ! is_string( $resolved ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Uploads root could not be resolved.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return rtrim( wp_normalize_path( $resolved ), '/' );
	}

	/** @return array<string,mixed> */
	private static function build_manifest( string $path, string $kind ): array {
		if ( is_link( $path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Symlinks cannot be quarantined.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$files = [];
		$dirs = [];
		$total = 0;

		if ( 'file' === $kind ) {
			$probe = FileHashProbe::probe( $path, [ 'sha256' ] );
			if ( empty( $probe['ok'] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'The file could not be hashed reliably for quarantine.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$probe_hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
			$hash = (string) ( $probe_hashes['sha256'] ?? '' );
			$size = (int) ( $probe['size'] ?? filesize( $path ) ?: 0 );
			$files[''] = [ 'sha256' => $hash, 'size' => $size, 'mode' => fileperms( $path ) & 0777, 'mtime' => filemtime( $path ) ?: 0 ];
			return [ 'files' => $files, 'directories' => [], 'file_count' => 1, 'total_bytes' => $size ];
		}

		$root = wp_normalize_path( $path );
		$dirs[''] = [ 'mode' => fileperms( $root ) & 0777, 'mtime' => filemtime( $root ) ?: 0 ];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $entry ) {
			$absolute = wp_normalize_path( $entry->getPathname() );
			$relative = ltrim( substr( $absolute, strlen( $root ) ), '/' );
			if ( $entry->isLink() ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Directory quarantine was refused because the directory contains a symlink:', 'core-blueprint' ) . ' ' . $relative );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( ! PathGuard::existing_path_is_inside( $absolute, $root ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Directory quarantine encountered a path outside the selected directory.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( $entry->isDir() ) {
				$dirs[ $relative ] = [ 'mode' => $entry->getPerms() & 0777, 'mtime' => $entry->getMTime() ];
				continue;
			}
			if ( ! $entry->isFile() ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'Directory quarantine encountered an unsupported filesystem entry:', 'core-blueprint' ) . ' ' . $relative );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$probe = FileHashProbe::probe( $absolute, [ 'sha256' ] );
			if ( empty( $probe['ok'] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new RuntimeException( __( 'A directory file could not be hashed reliably:', 'core-blueprint' ) . ' ' . $relative );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$size = (int) ( $probe['size'] ?? $entry->getSize() );
			$files[ $relative ] = [
				'sha256' => (string) ( ( is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [] )['sha256'] ?? '' ),
				'size' => $size,
				'mode' => $entry->getPerms() & 0777,
				'mtime' => $entry->getMTime(),
			];
			$total += $size;
		}
		ksort( $files );
		ksort( $dirs );
		return [ 'files' => $files, 'directories' => $dirs, 'file_count' => count( $files ), 'total_bytes' => $total ];
	}

	private static function validate_payload( array $item ): void {
		$kind = (string) ( $item['kind'] ?? '' );
		$payload = Vault::payload_path( (string) $item['id'], $kind );
		if ( ! file_exists( $payload ) || is_link( $payload ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine payload is missing or no longer trustworthy.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$current = self::build_manifest( $payload, $kind );
		$expected = is_array( $item['manifest'] ?? null ) ? $item['manifest'] : [];
		if ( ! self::manifest_content_matches( $expected, $current ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine payload changed after it was isolated. Restore/delete was refused pending manual review.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private static function manifest_content_matches( array $expected, array $current ): bool {
		$ef = is_array( $expected['files'] ?? null ) ? $expected['files'] : [];
		$cf = is_array( $current['files'] ?? null ) ? $current['files'] : [];
		if ( array_keys( $ef ) !== array_keys( $cf ) ) {
			return false;
		}
		foreach ( $ef as $path => $meta ) {
			if ( ! isset( $cf[ $path ] ) || (string) ( $meta['sha256'] ?? '' ) !== (string) ( $cf[ $path ]['sha256'] ?? '' ) || (int) ( $meta['size'] ?? -1 ) !== (int) ( $cf[ $path ]['size'] ?? -2 ) ) {
				return false;
			}
		}
		$ed = array_keys( is_array( $expected['directories'] ?? null ) ? $expected['directories'] : [] );
		$cd = array_keys( is_array( $current['directories'] ?? null ) ? $current['directories'] : [] );
		return $ed === $cd;
	}

	private static function apply_manifest_metadata( string $path, string $kind, array $manifest ): void {
		if ( 'file' === $kind ) {
			$meta = (array) ( $manifest['files'][''] ?? [] );
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
			@chmod( $path, (int) ( $meta['mode'] ?? 0644 ) );
			// phpcs:enable WordPress.WP.AlternativeFunctions
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
			if ( ! empty( $meta['mtime'] ) ) { @touch( $path, (int) $meta['mtime'] ); }
			// phpcs:enable WordPress.WP.AlternativeFunctions
			return;
		}
		$files = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : [];
		foreach ( $files as $relative => $meta ) {
			$target = wp_normalize_path( rtrim( $path, '/' ) . '/' . $relative );
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
			@chmod( $target, (int) ( $meta['mode'] ?? 0644 ) );
			// phpcs:enable WordPress.WP.AlternativeFunctions
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
			if ( ! empty( $meta['mtime'] ) ) { @touch( $target, (int) $meta['mtime'] ); }
			// phpcs:enable WordPress.WP.AlternativeFunctions
		}
		$dirs = is_array( $manifest['directories'] ?? null ) ? $manifest['directories'] : [];
		// Deepest first so child mtimes are not disturbed after parent metadata.
		uksort( $dirs, static fn( string $a, string $b ): int => substr_count( $b, '/' ) <=> substr_count( $a, '/' ) );
		foreach ( $dirs as $relative => $meta ) {
			$target = '' === $relative ? $path : wp_normalize_path( rtrim( $path, '/' ) . '/' . $relative );
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
			@chmod( $target, (int) ( $meta['mode'] ?? 0755 ) );
			// phpcs:enable WordPress.WP.AlternativeFunctions
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine metadata restoration requires explicit timestamps and permission modes.
			if ( ! empty( $meta['mtime'] ) ) { @touch( $target, (int) $meta['mtime'] ); }
			// phpcs:enable WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Serialize mutations for one quarantine item and re-read state after the
	 * lease is acquired so the callback never operates on a stale pre-lock copy.
	 *
	 * @param callable(array<string,mixed>,string):array<string,mixed> $callback
	 * @return array<string,mixed>
	 */
	private static function with_item_lock( string $id, callable $callback ): array {
		$item = self::require_item( $id );
		$canonical_id = (string) ( $item['id'] ?? '' );
		$token = MutationLock::acquire( $canonical_id );
		try {
			$item = self::require_item( $canonical_id );
			return $callback( $item, $canonical_id );
		} finally {
			MutationLock::release( $canonical_id, $token );
		}
	}

	private static function safe_restore_destination( array $item, string $uploads ): string {
		$stored = wp_normalize_path( (string) ( $item['original_path'] ?? '' ) );
		$uploads = rtrim( wp_normalize_path( $uploads ), '/' );
		if ( '' === $stored || ! PathGuard::is_inside( $stored, $uploads ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The stored restore destination no longer passes uploads containment checks.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$relative = ltrim( substr( $stored, strlen( $uploads ) ), '/' );
		$relative = PathGuard::normalise_relative( $relative );
		if ( null === $relative ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The stored restore destination contains an unsafe relative path.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$destination = PathGuard::join( $uploads, $relative );
		if ( null === $destination ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The stored restore destination could not be reconstructed safely.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$ancestor = dirname( $destination );
		while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) && $ancestor !== $uploads ) {
			$parent = dirname( $ancestor );
			if ( $parent === $ancestor ) {
				break;
			}
			$ancestor = $parent;
		}
		if ( is_link( $ancestor ) || ! PathGuard::existing_path_is_inside( $ancestor, $uploads ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The restore parent path no longer passes canonical uploads containment checks.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $destination;
	}

	private static function require_item( string $id ): array {
		$item = Repository::get( $id );
		if ( null === $item ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Quarantine item not found.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $item;
	}

	private static function audit_context( array $item ): array {
		return [
			'quarantine_id' => (string) ( $item['id'] ?? '' ),
			'finding_id' => (string) ( $item['finding_id'] ?? '' ),
			'kind' => (string) ( $item['kind'] ?? '' ),
			'status' => (string) ( $item['status'] ?? '' ),
			'original_path' => (string) ( $item['relative_path'] ?? '' ),
			'evidence_sha256' => (string) ( $item['evidence_sha256'] ?? '' ),
			'file_count' => (int) ( $item['file_count'] ?? 0 ),
			'total_bytes' => (int) ( $item['total_bytes'] ?? 0 ),
		];
	}

	private static function normalise_manifest_file( string $file ): string {
		$file = str_replace( '\\', '/', trim( $file ) );
		$file = ltrim( $file, '/' );
		if ( '' === $file || str_contains( $file, "\0" ) || str_contains( $file, '..' ) || str_contains( $file, '://' ) ) {
			return '';
		}
		return $file;
	}

	private static function is_text_preview_candidate( string $file ): bool {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		return in_array( $ext, [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'txt', 'log', 'json', 'xml', 'html', 'htm', 'css', 'js', 'md', 'yml', 'yaml', 'ini', 'conf', 'sh', 'py', 'pl', 'cgi' ], true );
	}
}
