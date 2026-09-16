<?php
declare(strict_types=1);
/**
 * Transactional Media Library file replacement.
 *
 * Replaces the physical file behind an existing attachment while preserving
 * attachment identity. v1.9 ships the preserve-filename strategy only; the
 * strategy contract keeps filename selection separate so rename + reference
 * updating can be added later without coupling it to rollback/file handling.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\MediaReplace;

use CB\Core\MediaFormats\Svg\Sanitizer as SvgSanitizer;
use CB\Core\MediaReplace\Strategy\ReplaceStrategyInterface;

defined( 'ABSPATH' ) || exit;

final class ReplaceService {

	private const REPLACEMENT_META_KEYS = [
		'_cb_media_replaced_at',
		'_cb_media_replaced_by',
		'_cb_media_replace_revision',
	];

	private ReplaceStrategyInterface $strategy;

	public function __construct( ReplaceStrategyInterface $strategy ) {
		$this->strategy = $strategy;
	}

	/**
	 * Replace an attachment with a browser-uploaded file.
	 *
	 * @param int                 $attachment_id Attachment post ID.
	 * @param array<string,mixed> $upload        One $_FILES entry.
	 * @return array{attachment_id:int,filename:string,mime:string,bytes:int,strategy:string}
	 * @throws ReplaceException
	 */
	public function replace( int $attachment_id, array $upload ): array {
		$stage_file      = '';
		$backup_dir      = '';
		$backups         = [];
		$mutated         = false;
		$preserve_backup = false;
		$lock_handle     = null;
		$current_file    = '';
		$target_file     = '';
		$old_state       = [];
		$old_metadata    = [];
		$new_metadata    = [];

		try {
			// Lock before reading attachment/file state. A second Core Blueprint
			// replace request can therefore never continue from a stale snapshot.
			$lock_handle = $this->acquire_lock();

			$attachment = get_post( $attachment_id );
			if ( ! ( $attachment instanceof \WP_Post ) || 'attachment' !== $attachment->post_type ) {
				throw new ReplaceException( 'invalid_attachment', __( 'The selected media item no longer exists.', 'core-blueprint' ) );
			}

			$current_file = get_attached_file( $attachment_id, true );
			if ( ! is_string( $current_file ) || '' === $current_file || ! is_file( $current_file ) ) {
				throw new ReplaceException( 'missing_source', __( 'The current attachment file could not be found on disk.', 'core-blueprint' ) );
			}

			$current_file = wp_normalize_path( $current_file );
			$this->assert_local_upload_path( $current_file );

			$validated   = $this->validate_upload( $upload, $attachment, $current_file );
			$target_file = wp_normalize_path( $this->strategy->target_path(
				$attachment,
				$current_file,
				$validated['name']
			) );
			$this->assert_local_upload_path( $target_file );

			// Future rename support is intentionally limited to the same uploads
			// directory. Moving an attachment is a separate concern from renaming it.
			if ( dirname( $target_file ) !== dirname( $current_file ) ) {
				throw new ReplaceException(
					'target_directory_change_unavailable',
					__( 'Media replacement can change the filename later, but moving an attachment to another upload directory is not supported.', 'core-blueprint' )
				);
			}

			// A rename strategy may select a different target already, but it must
			// not commit until a reference updater is part of the transaction.
			if ( $this->strategy->requires_reference_update() ) {
				throw new ReplaceException(
					'reference_update_unavailable',
					__( 'This replacement method requires reference updating, which is not enabled yet.', 'core-blueprint' )
				);
			}

			$target_dir = dirname( $target_file );
			if ( ! is_dir( $target_dir ) || ! is_writable( $target_dir ) ) {
				throw new ReplaceException( 'target_not_writable', __( 'The attachment directory is not writable.', 'core-blueprint' ) );
			}

			$old_state    = $this->snapshot_persistent_state( $attachment_id );
			$old_metadata = is_array( $old_state['metadata'] ?? null ) ? $old_state['metadata'] : [];
			$old_files    = $this->managed_files( $current_file, $old_metadata );

			$stage_file = $this->stage_upload( $validated['tmp_name'], $target_file, $current_file );
			$backup_dir = $this->create_backup_dir();
			$backups    = $this->backup_files( $old_files, $backup_dir );

			$mutated = true;
			$this->swap_staged_file( $stage_file, $target_file, $current_file );
			$stage_file = '';

			// Remove only files WordPress explicitly recorded for this attachment.
			// wp_generate_attachment_metadata() does not remove obsolete sub-sizes.
			foreach ( $old_files as $old_file ) {
				if ( $old_file === $target_file || ! is_file( $old_file ) ) {
					continue;
				}
				if ( ! @unlink( $old_file ) ) {
					throw new ReplaceException( 'old_derivative_cleanup_failed', __( 'An old generated media file could not be removed safely.', 'core-blueprint' ) );
				}
			}

			$new_metadata = $this->regenerate_metadata( $attachment_id, $target_file, $attachment );

			// Reassert the strategy's path after metadata generation. WordPress image
			// processing must never silently promote a -scaled/-rotated sibling.
			update_attached_file( $attachment_id, $target_file );
			if ( ! empty( $new_metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $new_metadata );
			} else {
				delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
			}

			$replaced_at = gmdate( 'Y-m-d H:i:s' );
			$replaced_by = (string) get_current_user_id();
			$revision    = wp_generate_uuid4();
			update_post_meta( $attachment_id, '_cb_media_replaced_at', $replaced_at );
			update_post_meta( $attachment_id, '_cb_media_replaced_by', $replaced_by );
			// Same-URL replacements need a stable admin-only cache revision. A UUID
			// avoids collisions when the same attachment is replaced more than once
			// within the same second without changing its stored/public URL.
			update_post_meta( $attachment_id, '_cb_media_replace_revision', $revision );
			clean_post_cache( $attachment_id );

			$expected_state = [
				'attached_file'   => $target_file,
				'metadata_exists' => ! empty( $new_metadata ),
				'metadata'        => $new_metadata,
				'replacement_meta' => [
					'_cb_media_replaced_at'       => [ 'exists' => true, 'value' => $replaced_at ],
					'_cb_media_replaced_by'       => [ 'exists' => true, 'value' => $replaced_by ],
					'_cb_media_replace_revision'  => [ 'exists' => true, 'value' => $revision ],
				],
			];
			if ( ! $this->persistent_state_matches( $attachment_id, $expected_state ) ) {
				throw new ReplaceException(
					'persistence_verify_failed',
					__( 'WordPress could not persist the complete replacement state, so the original attachment was restored.', 'core-blueprint' )
				);
			}

			$this->remove_tree( $backup_dir );
			$backup_dir = '';

			return [
				'attachment_id' => $attachment_id,
				'filename'      => wp_basename( $target_file ),
				'mime'          => $validated['mime'],
				'bytes'         => (int) filesize( $target_file ),
				'strategy'      => $this->strategy->key(),
			];
		} catch ( ReplaceException $e ) {
			if ( $mutated && ! empty( $backups ) ) {
				try {
					$this->rollback( $attachment_id, $current_file, $target_file, $old_state, $backups, $new_metadata );
				} catch ( ReplaceException $rollback_error ) {
					$preserve_backup = true;
					throw $rollback_error;
				}
			}
			throw $e;
		} catch ( \Throwable $e ) {
			if ( $mutated && ! empty( $backups ) ) {
				try {
					$this->rollback( $attachment_id, $current_file, $target_file, $old_state, $backups, $new_metadata );
				} catch ( ReplaceException $rollback_error ) {
					$preserve_backup = true;
					throw $rollback_error;
				}
			}
			throw new ReplaceException(
				'unexpected_error',
				__( 'The media replacement failed and the original attachment was restored.', 'core-blueprint' ),
				$e
			);
		} finally {
			if ( '' !== $stage_file && is_file( $stage_file ) ) {
				@unlink( $stage_file );
			}
			// If rollback itself failed, retain the private verified backup for
			// emergency recovery instead of destroying the last known-good copy.
			if ( ! $preserve_backup && '' !== $backup_dir && is_dir( $backup_dir ) ) {
				$this->remove_tree( $backup_dir );
			}
			$this->release_lock( $lock_handle );
		}
	}

	/**
	 * Validate the incoming PHP upload and require the same MIME type as the
	 * existing attachment. v1 preserves the public filename/extension, so a
	 * type change would make the URL lie about its content.
	 *
	 * @return array{name:string,tmp_name:string,mime:string,size:int}
	 */
	private function validate_upload( array $upload, \WP_Post $attachment, string $current_file ): array {
		$error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			throw new ReplaceException( 'upload_failed', $this->upload_error_message( $error ) );
		}

		$tmp_name = isset( $upload['tmp_name'] ) ? (string) $upload['tmp_name'] : '';
		$name     = isset( $upload['name'] ) ? sanitize_file_name( (string) $upload['name'] ) : '';

		if ( '' === $tmp_name || '' === $name || ! is_uploaded_file( $tmp_name ) ) {
			throw new ReplaceException( 'invalid_upload', __( 'WordPress could not verify the uploaded replacement file.', 'core-blueprint' ) );
		}
		if ( ! is_file( $tmp_name ) ) {
			throw new ReplaceException( 'empty_upload', __( 'The uploaded replacement file is empty.', 'core-blueprint' ) );
		}

		$actual_size = filesize( $tmp_name );
		if ( false === $actual_size || $actual_size <= 0 ) {
			throw new ReplaceException( 'empty_upload', __( 'The uploaded replacement file is empty.', 'core-blueprint' ) );
		}
		$size = (int) $actual_size;
		if ( $size > wp_max_upload_size() ) {
			throw new ReplaceException( 'upload_too_large', __( 'The uploaded replacement exceeds the site upload limit.', 'core-blueprint' ) );
		}

		$allowed = get_allowed_mime_types();
		$checked = wp_check_filetype_and_ext( $tmp_name, $name, $allowed );
		$mime    = isset( $checked['type'] ) && is_string( $checked['type'] ) ? $checked['type'] : '';
		$ext     = isset( $checked['ext'] ) && is_string( $checked['ext'] ) ? $checked['ext'] : '';

		if ( '' === $mime || '' === $ext ) {
			throw new ReplaceException( 'file_type_not_allowed', __( 'WordPress rejected the replacement file type.', 'core-blueprint' ) );
		}

		$current_mime = (string) $attachment->post_mime_type;
		if ( '' === $current_mime ) {
			$current_type = wp_check_filetype( wp_basename( $current_file ), $allowed );
			$current_mime = isset( $current_type['type'] ) ? (string) $current_type['type'] : '';
		}

		if ( '' === $current_mime || strtolower( $current_mime ) !== strtolower( $mime ) ) {
			throw new ReplaceException(
				'mime_mismatch',
				__( 'For now, the replacement must use the same file type as the existing media item.', 'core-blueprint' )
			);
		}

		if ( 'image/svg+xml' === strtolower( $mime ) ) {
			$sanitized = SvgSanitizer::sanitize_file( $tmp_name );
			if ( is_wp_error( $sanitized ) ) {
				throw new ReplaceException(
					'svg_sanitize_failed',
					__( 'The SVG replacement could not be sanitized safely.', 'core-blueprint' )
				);
			}

			// The sanitizer rewrites the upload in place. Re-run WordPress' type
			// validation on the exact bytes that will be staged so a sanitizer or
			// filter can never silently change the replacement contract.
			$checked = wp_check_filetype_and_ext( $tmp_name, $name, $allowed );
			$mime    = isset( $checked['type'] ) && is_string( $checked['type'] ) ? $checked['type'] : '';
			$ext     = isset( $checked['ext'] ) && is_string( $checked['ext'] ) ? $checked['ext'] : '';
			if ( 'image/svg+xml' !== strtolower( $mime ) || 'svg' !== strtolower( $ext ) ) {
				throw new ReplaceException(
					'svg_type_changed',
					__( 'The sanitized SVG replacement no longer matches the expected file type.', 'core-blueprint' )
				);
			}

			$sanitized_size = filesize( $tmp_name );
			if ( false === $sanitized_size || $sanitized_size <= 0 ) {
				throw new ReplaceException( 'svg_sanitize_failed', __( 'The SVG replacement could not be sanitized safely.', 'core-blueprint' ) );
			}
			$size = (int) $sanitized_size;
		}

		return [
			'name'     => $name,
			'tmp_name' => $tmp_name,
			'mime'     => $mime,
			'size'     => $size,
		];
	}

	private function assert_local_upload_path( string $path ): void {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base ) {
			throw new ReplaceException( 'uploads_unavailable', __( 'WordPress could not resolve the uploads directory.', 'core-blueprint' ) );
		}

		$base_real = realpath( $base );
		$path_real = realpath( $path );

		// A future rename strategy may resolve a not-yet-existing target. In that
		// case canonicalize its existing parent and append only the basename.
		if ( false === $path_real ) {
			$parent_real = realpath( dirname( $path ) );
			if ( false !== $parent_real ) {
				$path_real = trailingslashit( $parent_real ) . wp_basename( $path );
			}
		}

		if ( false === $base_real || false === $path_real ) {
			throw new ReplaceException( 'uploads_unavailable', __( 'WordPress could not resolve the local media path safely.', 'core-blueprint' ) );
		}

		$base_check  = untrailingslashit( wp_normalize_path( $base_real ) );
		$path_check  = wp_normalize_path( $path_real );
		$base_prefix = trailingslashit( $base_check );
		if ( $path_check !== $base_check && ! str_starts_with( $path_check, $base_prefix ) ) {
			throw new ReplaceException( 'outside_uploads', __( 'Core Blueprint only replaces files stored in the local WordPress uploads directory.', 'core-blueprint' ) );
		}
	}

	/**
	 * Serialize Media Replace mutations across concurrent PHP requests.
	 *
	 * A persistent zero-byte lock at the uploads root avoids the unlink/reopen
	 * inode race that can occur when flock() lock files are deleted per request.
	 *
	 * @return resource
	 */
	private function acquire_lock() {
		$uploads   = wp_get_upload_dir();
		$base      = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$lock_file = '' !== $base ? wp_normalize_path( trailingslashit( $base ) . '.core-blueprint-media-replace.lock' ) : '';

		if ( '' === $lock_file ) {
			throw new ReplaceException( 'lock_create_failed', __( 'Core Blueprint could not resolve the media replacement lock path.', 'core-blueprint' ) );
		}

		$handle = @fopen( $lock_file, 'c' );
		if ( false === $handle ) {
			throw new ReplaceException( 'lock_create_failed', __( 'Core Blueprint could not create the media replacement lock.', 'core-blueprint' ) );
		}
		@chmod( $lock_file, 0600 );

		if ( ! @flock( $handle, LOCK_EX | LOCK_NB ) ) {
			@fclose( $handle );
			throw new ReplaceException( 'replacement_in_progress', __( 'Another media replacement is already in progress. Try again after that operation finishes.', 'core-blueprint' ) );
		}

		return $handle;
	}

	/** @param resource|null $handle */
	private function release_lock( $handle ): void {
		if ( is_resource( $handle ) ) {
			@flock( $handle, LOCK_UN );
			@fclose( $handle );
		}

		// Keep the zero-byte lock file. Removing it after unlock can allow two
		// requests to lock different inodes under the same pathname.
	}

	private function stage_upload( string $uploaded_tmp, string $target_file, string $current_file ): string {
		$directory = dirname( $target_file );
		$stage     = tempnam( $directory, '.cb-media-replace-' );
		if ( false === $stage ) {
			throw new ReplaceException( 'stage_create_failed', __( 'Could not create a staging file for the replacement.', 'core-blueprint' ) );
		}

		// move_uploaded_file() verifies that the source came through PHP's upload
		// mechanism and safely overwrites the unique tempnam placeholder.
		if ( ! move_uploaded_file( $uploaded_tmp, $stage ) ) {
			@unlink( $stage );
			throw new ReplaceException( 'stage_move_failed', __( 'Could not move the uploaded file into the attachment directory.', 'core-blueprint' ) );
		}

		$mode_source = is_file( $target_file ) ? $target_file : $current_file;
		$mode        = @fileperms( $mode_source );
		if ( false !== $mode ) {
			@chmod( $stage, $mode & 0777 );
		}

		if ( ! is_file( $stage ) || filesize( $stage ) <= 0 ) {
			@unlink( $stage );
			throw new ReplaceException( 'stage_verify_failed', __( 'The staged replacement file could not be verified.', 'core-blueprint' ) );
		}

		return wp_normalize_path( $stage );
	}

	private function create_backup_dir(): string {
		$base = trailingslashit( get_temp_dir() );
		$dir  = $base . 'cb-media-replace-' . wp_generate_uuid4();
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new ReplaceException( 'backup_dir_failed', __( 'Could not create a temporary backup directory.', 'core-blueprint' ) );
		}

		// Backups can contain private media. Keep them owner-only regardless of
		// a permissive server umask.
		@chmod( $dir, 0700 );
		return wp_normalize_path( $dir );
	}

	/**
	 * @param string[] $files
	 * @return array<string,string> Original path => backup path.
	 */
	private function backup_files( array $files, string $backup_dir ): array {
		$manifest = [];
		$index    = 0;

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			$backup = trailingslashit( $backup_dir ) . sprintf( '%03d-%s', ++$index, wp_basename( $file ) );
			if ( ! @copy( $file, $backup ) || ! $this->files_match( $file, $backup ) ) {
				throw new ReplaceException( 'backup_failed', __( 'The original media files could not be backed up safely.', 'core-blueprint' ) );
			}
			@chmod( $backup, 0600 );
			$manifest[ $file ] = wp_normalize_path( $backup );
		}

		if ( ! isset( $manifest[ $files[0] ?? '' ] ) ) {
			throw new ReplaceException( 'backup_source_missing', __( 'The original attachment could not be backed up.', 'core-blueprint' ) );
		}

		return $manifest;
	}

	private function swap_staged_file( string $stage_file, string $target_file, string $current_file ): void {
		// A future rename strategy must resolve a free target. Never overwrite an
		// unrelated sibling merely because the uploaded filename collides.
		if ( $target_file !== $current_file && file_exists( $target_file ) ) {
			throw new ReplaceException( 'target_exists', __( 'The replacement target filename is already in use.', 'core-blueprint' ) );
		}

		// POSIX rename replaces the current destination atomically. Filesystems
		// that refuse replacement get an unlink+rename fallback only for the
		// preserve-filename case, and only after verified backups exist.
		if ( @rename( $stage_file, $target_file ) ) {
			return;
		}

		if ( $target_file !== $current_file || ! @unlink( $target_file ) || ! @rename( $stage_file, $target_file ) ) {
			throw new ReplaceException( 'swap_failed', __( 'Could not swap the replacement file into place.', 'core-blueprint' ) );
		}
	}

	private function regenerate_metadata( int $attachment_id, string $target_file, \WP_Post $attachment ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$is_image = str_starts_with( (string) $attachment->post_mime_type, 'image/' )
			&& file_is_displayable_image( $target_file );

		if ( $is_image && 'image/jpeg' === strtolower( (string) $attachment->post_mime_type ) ) {
			$this->normalize_jpeg_orientation( $target_file );
		}

		/*
		 * Preserve-filename is a hard strategy invariant:
		 * - big-image scaling may promote a -scaled sibling to full image;
		 * - EXIF handling may promote a -rotated sibling;
		 * - output-format filters may change the full image extension.
		 *
		 * Keep normal sub-size format filters intact by suppressing conversion
		 * only while WordPress is evaluating the full target itself.
		 */
		$disable_scaling = static fn() => false;
		$disable_second_rotation = static function ( $orientation, $file ) use ( $target_file ) {
			if ( ! is_string( $file ) || '' === $file ) {
				return $orientation;
			}
			return wp_normalize_path( $file ) === $target_file ? 1 : $orientation;
		};
		$preserve_full_format = static function ( $formats, $filename, $mime_type ) use ( $target_file ): array {
			$formats = is_array( $formats ) ? $formats : [];

			// WP_Image_Editor creates sub-sizes through _save() without an
			// explicit destination filename. Core therefore legitimately passes
			// null as the second filter argument for those derivative writes. Only
			// constrain output conversion when Core is evaluating the actual full
			// attachment target; sub-sizes must keep the site's normal mappings.
			if ( is_string( $filename ) && '' !== $filename && is_string( $mime_type ) && '' !== $mime_type ) {
				if ( wp_normalize_path( $filename ) === $target_file ) {
					unset( $formats[ $mime_type ] );
				}
			}

			return $formats;
		};

		add_filter( 'big_image_size_threshold', $disable_scaling, PHP_INT_MAX );
		add_filter( 'wp_image_maybe_exif_rotate', $disable_second_rotation, PHP_INT_MAX, 2 );
		add_filter( 'image_editor_output_format', $preserve_full_format, PHP_INT_MAX, 3 );
		try {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $target_file );
		} finally {
			remove_filter( 'big_image_size_threshold', $disable_scaling, PHP_INT_MAX );
			remove_filter( 'wp_image_maybe_exif_rotate', $disable_second_rotation, PHP_INT_MAX );
			remove_filter( 'image_editor_output_format', $preserve_full_format, PHP_INT_MAX );
		}

		$metadata = is_array( $metadata ) ? $metadata : [];
		if ( $is_image ) {
			if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
				throw new ReplaceException( 'metadata_failed', __( 'WordPress could not regenerate image metadata for the replacement.', 'core-blueprint' ) );
			}

			$expected_relative = wp_normalize_path( _wp_relative_upload_path( $target_file ) );
			$metadata_file     = isset( $metadata['file'] ) && is_string( $metadata['file'] )
				? wp_normalize_path( $metadata['file'] )
				: '';
			if ( $expected_relative !== $metadata_file ) {
				throw new ReplaceException(
					'filename_invariant_failed',
					__( 'WordPress image processing tried to change the attachment filename, so the replacement was rolled back.', 'core-blueprint' )
				);
			}
		}

		return $metadata;
	}

	/**
	 * Apply WordPress' normal JPEG EXIF orientation correction in-place. Core
	 * normally writes a -rotated sibling and promotes that path; preserve mode
	 * writes the corrected pixels back to the existing attachment filename.
	 */
	private function normalize_jpeg_orientation( string $target_file ): void {
		$exif = wp_read_image_metadata( $target_file );
		if ( ! is_array( $exif ) || empty( $exif['orientation'] ) || 1 === (int) $exif['orientation'] ) {
			return;
		}

		$editor = wp_get_image_editor( $target_file );
		if ( is_wp_error( $editor ) ) {
			throw new ReplaceException( 'orientation_editor_failed', __( 'WordPress could not open the replacement image for orientation correction.', 'core-blueprint' ) );
		}

		$rotated = $editor->maybe_exif_rotate();
		if ( is_wp_error( $rotated ) ) {
			throw new ReplaceException( 'orientation_failed', __( 'WordPress could not correct the replacement image orientation safely.', 'core-blueprint' ) );
		}
		if ( true !== $rotated ) {
			// Respect sites that intentionally disable WordPress EXIF rotation.
			return;
		}

		$target_mode = @fileperms( $target_file );
		$extension   = strtolower( (string) pathinfo( $target_file, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			$extension = 'jpg';
		}
		$temp_file = dirname( $target_file ) . '/.cb-media-orient-' . wp_generate_uuid4() . '.' . $extension;

		// A site-wide JPEG -> WebP/AVIF mapping must not change the bytes behind
		// a preserved .jpg/.jpeg URL during this in-place correction.
		$preserve_jpeg = static function ( $formats, $filename, $mime_type ) use ( $temp_file ): array {
			$formats = is_array( $formats ) ? $formats : [];
			if ( is_string( $filename ) && '' !== $filename && is_string( $mime_type ) && '' !== $mime_type ) {
				if ( wp_normalize_path( $filename ) === wp_normalize_path( $temp_file ) ) {
					unset( $formats[ $mime_type ] );
				}
			}
			return $formats;
		};
		add_filter( 'image_editor_output_format', $preserve_jpeg, PHP_INT_MAX, 3 );
		try {
			$saved = $editor->save( $temp_file, 'image/jpeg' );
		} finally {
			remove_filter( 'image_editor_output_format', $preserve_jpeg, PHP_INT_MAX );
		}

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_string( $saved['path'] ) ) {
			@unlink( $temp_file );
			throw new ReplaceException( 'orientation_save_failed', __( 'WordPress could not save the corrected replacement image safely.', 'core-blueprint' ) );
		}

		$saved_file = wp_normalize_path( $saved['path'] );
		if ( ! is_file( $saved_file ) || filesize( $saved_file ) <= 0 ) {
			@unlink( $saved_file );
			throw new ReplaceException( 'orientation_verify_failed', __( 'The corrected replacement image could not be verified.', 'core-blueprint' ) );
		}

		if ( ! @rename( $saved_file, $target_file ) ) {
			if ( ! @unlink( $target_file ) || ! @rename( $saved_file, $target_file ) ) {
				@unlink( $saved_file );
				throw new ReplaceException( 'orientation_swap_failed', __( 'The corrected replacement image could not be committed safely.', 'core-blueprint' ) );
			}
		}
		if ( false !== $target_mode ) {
			@chmod( $target_file, $target_mode & 0777 );
		}

		$checked = wp_check_filetype_and_ext( $target_file, wp_basename( $target_file ), get_allowed_mime_types() );
		if ( empty( $checked['type'] ) || 'image/jpeg' !== strtolower( (string) $checked['type'] ) ) {
			throw new ReplaceException( 'orientation_mime_changed', __( 'Image processing changed the replacement file type, so the replacement was rolled back.', 'core-blueprint' ) );
		}
	}

	/**
	 * Files explicitly owned by the current attachment metadata.
	 *
	 * @param array<string,mixed> $metadata
	 * @return string[]
	 */
	private function managed_files( string $attached_file, array $metadata ): array {
		$directory = wp_normalize_path( dirname( $attached_file ) );
		$files     = [ wp_normalize_path( $attached_file ) ];

		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$files[] = $directory . '/' . wp_basename( $metadata['original_image'] );
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}
				if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$files[] = $directory . '/' . wp_basename( $size['file'] );
				}
				// WordPress 7+ metadata may describe alternate generated sources.
				if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
					foreach ( $size['sources'] as $source ) {
						if ( is_array( $source ) && ! empty( $source['file'] ) && is_string( $source['file'] ) ) {
							$files[] = $directory . '/' . wp_basename( $source['file'] );
						}
					}
				}
			}
		}

		$files = array_values( array_unique( array_map( 'wp_normalize_path', $files ) ) );
		return array_values( array_filter( $files, static fn( string $file ): bool => dirname( $file ) === $directory ) );
	}

	/**
	 * Snapshot the unfiltered durable attachment state that must move atomically
	 * with the verified filesystem replacement.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot_persistent_state( int $attachment_id ): array {
		$attached_file = get_attached_file( $attachment_id, true );
		$metadata_exists = metadata_exists( 'post', $attachment_id, '_wp_attachment_metadata' );
		$replacement_meta = [];
		foreach ( self::REPLACEMENT_META_KEYS as $key ) {
			$exists = metadata_exists( 'post', $attachment_id, $key );
			$replacement_meta[ $key ] = [
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( $attachment_id, $key, true ) : null,
			];
		}

		return [
			'attached_file'   => is_string( $attached_file ) ? wp_normalize_path( $attached_file ) : '',
			'metadata_exists' => $metadata_exists,
			'metadata'        => $metadata_exists ? get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) : null,
			'replacement_meta' => $replacement_meta,
		];
	}

	/** @param array<string,mixed> $expected */
	private function persistent_state_matches( int $attachment_id, array $expected ): bool {
		$actual = $this->snapshot_persistent_state( $attachment_id );
		if ( (string) ( $actual['attached_file'] ?? '' ) !== (string) ( $expected['attached_file'] ?? '' ) ) {
			return false;
		}
		if ( (bool) ( $actual['metadata_exists'] ?? false ) !== (bool) ( $expected['metadata_exists'] ?? false ) ) {
			return false;
		}
		if ( ! empty( $expected['metadata_exists'] ) && ( $actual['metadata'] ?? null ) !== ( $expected['metadata'] ?? null ) ) {
			return false;
		}

		$actual_meta   = is_array( $actual['replacement_meta'] ?? null ) ? $actual['replacement_meta'] : [];
		$expected_meta = is_array( $expected['replacement_meta'] ?? null ) ? $expected['replacement_meta'] : [];
		foreach ( self::REPLACEMENT_META_KEYS as $key ) {
			$actual_item   = is_array( $actual_meta[ $key ] ?? null ) ? $actual_meta[ $key ] : [];
			$expected_item = is_array( $expected_meta[ $key ] ?? null ) ? $expected_meta[ $key ] : [];
			if ( (bool) ( $actual_item['exists'] ?? false ) !== (bool) ( $expected_item['exists'] ?? false ) ) {
				return false;
			}
			if ( ! empty( $expected_item['exists'] ) && ( $actual_item['value'] ?? null ) !== ( $expected_item['value'] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $state */
	private function restore_persistent_state( int $attachment_id, array $state ): void {
		$attached_file = (string) ( $state['attached_file'] ?? '' );
		if ( '' !== $attached_file ) {
			update_attached_file( $attachment_id, $attached_file );
		}

		if ( ! empty( $state['metadata_exists'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $state['metadata'] ?? null );
		} else {
			delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
		}

		$replacement_meta = is_array( $state['replacement_meta'] ?? null ) ? $state['replacement_meta'] : [];
		foreach ( self::REPLACEMENT_META_KEYS as $key ) {
			$item = is_array( $replacement_meta[ $key ] ?? null ) ? $replacement_meta[ $key ] : [];
			if ( ! empty( $item['exists'] ) ) {
				update_post_meta( $attachment_id, $key, $item['value'] ?? null );
			} else {
				delete_post_meta( $attachment_id, $key );
			}
		}
		clean_post_cache( $attachment_id );
	}

	/**
	 * Restore every backed-up file and the complete old durable attachment state.
	 *
	 * @param array<string,mixed>  $old_state
	 * @param array<string,string> $backups
	 * @param array<string,mixed>  $new_metadata
	 */
	private function rollback( int $attachment_id, string $current_file, string $target_file, array $old_state, array $backups, array $new_metadata ): void {
		$rollback_ok     = true;
		$rollback_target = '' !== $target_file ? $target_file : $current_file;

		$current_metadata = wp_get_attachment_metadata( $attachment_id, true );
		$current_metadata = is_array( $current_metadata ) ? $current_metadata : [];
		$generated_files  = array_merge(
			$this->managed_files( $rollback_target, $current_metadata ),
			$this->managed_files( $rollback_target, $new_metadata )
		);
		foreach ( array_values( array_unique( $generated_files ) ) as $generated ) {
			if ( isset( $backups[ $generated ] ) || ! is_file( $generated ) ) {
				continue;
			}
			@unlink( $generated );
		}

		foreach ( $backups as $original => $backup ) {
			if ( ! is_file( $backup ) || ! @copy( $backup, $original ) || ! $this->files_match( $backup, $original ) ) {
				$rollback_ok = false;
			}
		}

		$this->restore_persistent_state( $attachment_id, $old_state );
		if ( ! $this->persistent_state_matches( $attachment_id, $old_state ) ) {
			$rollback_ok = false;
		}

		if ( ! $rollback_ok ) {
			throw new ReplaceException(
				'rollback_failed',
				__( 'Media replacement failed and Core Blueprint could not fully restore the original files and attachment state. A private recovery backup was retained on the server.', 'core-blueprint' )
			);
		}
	}

	private function files_match( string $first, string $second ): bool {
		if ( ! is_file( $first ) || ! is_file( $second ) ) {
			return false;
		}
		if ( filesize( $first ) !== filesize( $second ) ) {
			return false;
		}
		$first_hash  = @hash_file( 'sha256', $first );
		$second_hash = @hash_file( 'sha256', $second );
		return is_string( $first_hash ) && '' !== $first_hash && hash_equals( $first_hash, (string) $second_hash );
	}

	private function remove_tree( string $directory ): void {
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return;
		}
		$items = scandir( $directory );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $directory . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $directory );
	}

	private function upload_error_message( int $error ): string {
		switch ( $error ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded replacement exceeds the allowed upload size.', 'core-blueprint' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The replacement upload was interrupted before it completed.', 'core-blueprint' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Choose a replacement file before continuing.', 'core-blueprint' );
			default:
				return __( 'The replacement file could not be uploaded.', 'core-blueprint' );
		}
	}
}
