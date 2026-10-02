<?php
declare(strict_types=1);
/**
 * Package archive builder and streamer.
 *
 * Creates installable ZIP archives without writing into plugin/theme source
 * directories. Symlinks are intentionally skipped so an archive can never
 * escape the validated package root through a linked path.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\PackageDownload;

defined( 'ABSPATH' ) || exit;

final class ArchiveService {

	/**
	 * Build an installable archive from a package directory.
	 *
	 * @throws \RuntimeException When the source is invalid or archive creation fails.
	 */
	public function create_from_directory( string $source_dir, string $allowed_root, string $package_slug ): string {
		$source_real = $this->validated_real_path( $source_dir, $allowed_root, true );
		$slug        = $this->validated_archive_segment( $package_slug );
		$archive     = $this->temporary_archive_path( $slug );

		try {
			if ( class_exists( '\\ZipArchive' ) ) {
				$this->create_directory_with_ziparchive( $archive, $source_real, $slug );
				return $archive;
			}

			$this->create_directory_with_pclzip( $archive, $source_real );
			return $archive;
		} catch ( \Throwable $e ) {
			$this->delete_if_exists( $archive );
			if ( $e instanceof \RuntimeException ) {
				throw $e;
			}
			throw new \RuntimeException( __( 'Core Blueprint could not create the package archive.', 'core-blueprint' ), 0, $e );
		}
	}

	/**
	 * Build an installable archive for a single-file plugin.
	 *
	 * @throws \RuntimeException When the source is invalid or archive creation fails.
	 */
	public function create_from_file( string $source_file, string $allowed_root, string $package_slug ): string {
		$source_real = $this->validated_real_path( $source_file, $allowed_root, false );
		$slug        = $this->validated_archive_segment( $package_slug );
		$filename    = wp_basename( $source_real );
		$archive     = $this->temporary_archive_path( $slug );

		try {
			if ( class_exists( '\\ZipArchive' ) ) {
				$zip    = new \ZipArchive();
				$result = $zip->open( $archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
				if ( true !== $result ) {
					throw new \RuntimeException( __( 'Core Blueprint could not open the temporary ZIP archive.', 'core-blueprint' ) );
				}

				if ( ! $zip->addFile( $source_real, $slug . '/' . $filename ) ) {
					$zip->close();
					throw new \RuntimeException( __( 'Core Blueprint could not add the plugin file to the ZIP archive.', 'core-blueprint' ) );
				}

				if ( ! $zip->close() ) {
					throw new \RuntimeException( __( 'Core Blueprint could not finalize the ZIP archive.', 'core-blueprint' ) );
				}
				return $archive;
			}

			$this->create_file_with_pclzip( $archive, $source_real, $slug );
			return $archive;
		} catch ( \Throwable $e ) {
			$this->delete_if_exists( $archive );
			if ( $e instanceof \RuntimeException ) {
				throw $e;
			}
			throw new \RuntimeException( __( 'Core Blueprint could not create the package archive.', 'core-blueprint' ), 0, $e );
		}
	}

	/**
	 * Stream an archive to the current browser request, then remove it.
	 *
	 * @return int Number of bytes streamed.
	 * @throws \RuntimeException When headers or file streaming fail.
	 */
	public function stream_and_delete( string $archive, string $download_filename ): int {
		$archive_real = realpath( $archive );
		if ( false === $archive_real || ! is_file( $archive_real ) || ! is_readable( $archive_real ) ) {
			throw new \RuntimeException( __( 'The generated package archive is not readable.', 'core-blueprint' ) );
		}
		if ( ! $this->is_valid_temp_archive( $archive_real ) ) {
			throw new \RuntimeException( __( 'The generated package archive is outside WordPress temporary storage.', 'core-blueprint' ) );
		}

		$size = filesize( $archive_real );
		if ( false === $size ) {
			$this->delete_if_exists( $archive_real );
			throw new \RuntimeException( __( 'Core Blueprint could not determine the package archive size.', 'core-blueprint' ) );
		}

		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) {
				break;
			}
		}

		if ( headers_sent( $sent_file, $sent_line ) ) {
			$this->delete_if_exists( $archive_real );
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: PHP filename, 2: line number */
					__( 'The download cannot start because output was already sent by %1$s on line %2$d.', 'core-blueprint' ),
					wp_basename( (string) $sent_file ),
					(int) $sent_line
				)
			);
		}

		$filename = sanitize_file_name( $download_filename );
		if ( '' === $filename ) {
			$filename = 'package.zip';
		}

		try {
			nocache_headers();
			send_nosniff_header();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
			header( 'Content-Length: ' . (string) $size );

			$streamed = readfile( $archive_real );
			if ( false === $streamed ) {
				throw new \RuntimeException( __( 'Core Blueprint could not stream the package archive.', 'core-blueprint' ) );
			}
			return (int) $streamed;
		} finally {
			$this->delete_if_exists( $archive_real );
		}
	}

	/**
	 * @throws \RuntimeException
	 */
	private function create_directory_with_ziparchive( string $archive, string $source_real, string $slug ): void {
		$zip    = new \ZipArchive();
		$result = $zip->open( $archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		if ( true !== $result ) {
			throw new \RuntimeException( __( 'Core Blueprint could not open the temporary ZIP archive.', 'core-blueprint' ) );
		}

		$zip->addEmptyDir( $slug );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				continue;
			}

			$item_real = $item->getRealPath();
			if ( false === $item_real || ! $this->path_is_within( $item_real, $source_real ) ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( wp_normalize_path( $item_real ), strlen( wp_normalize_path( $source_real ) ) ) ), '/' );
			if ( '' === $relative ) {
				continue;
			}

			$zip_path = $slug . '/' . $relative;
			if ( $item->isDir() ) {
				$zip->addEmptyDir( $zip_path );
				continue;
			}
			if ( $item->isFile() && ! $zip->addFile( $item_real, $zip_path ) ) {
				$zip->close();
				throw new \RuntimeException( __( 'Core Blueprint could not add a package file to the ZIP archive.', 'core-blueprint' ) );
			}
		}

		if ( ! $zip->close() ) {
			throw new \RuntimeException( __( 'Core Blueprint could not finalize the ZIP archive.', 'core-blueprint' ) );
		}
	}

	/** @throws \RuntimeException */
	private function create_directory_with_pclzip( string $archive, string $source_real ): void {
		$this->load_pclzip();

		$files = $this->safe_file_list( $source_real );
		if ( empty( $files ) ) {
			throw new \RuntimeException( __( 'The requested package directory does not contain any readable files.', 'core-blueprint' ) );
		}

		$zip    = new \PclZip( $archive );
		$result = $zip->create(
			$files,
			PCLZIP_OPT_REMOVE_PATH,
			$source_real,
			PCLZIP_OPT_ADD_PATH,
			wp_basename( $source_real )
		);
		if ( 0 === $result ) {
			throw new \RuntimeException( __( 'Core Blueprint could not create the package ZIP with the WordPress fallback archiver.', 'core-blueprint' ) );
		}
	}

	/** @throws \RuntimeException */
	private function create_file_with_pclzip( string $archive, string $source_real, string $slug ): void {
		$this->load_pclzip();
		$zip    = new \PclZip( $archive );
		$result = $zip->create(
			$source_real,
			PCLZIP_OPT_REMOVE_PATH,
			dirname( $source_real ),
			PCLZIP_OPT_ADD_PATH,
			$slug
		);
		if ( 0 === $result ) {
			throw new \RuntimeException( __( 'Core Blueprint could not create the plugin ZIP with the WordPress fallback archiver.', 'core-blueprint' ) );
		}
	}

	/** @throws \RuntimeException */
	private function load_pclzip(): void {
		if ( class_exists( '\\PclZip' ) ) {
			return;
		}
		$file = ABSPATH . 'wp-admin/includes/class-pclzip.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
		if ( ! class_exists( '\\PclZip' ) ) {
			throw new \RuntimeException( __( 'ZIP support is unavailable. Enable PHP ZipArchive or the WordPress PclZip fallback.', 'core-blueprint' ) );
		}
	}

	/** @throws \RuntimeException */
	private function temporary_archive_path( string $slug ): string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = wp_tempnam( 'cb-' . $slug . '.zip' );
		if ( ! is_string( $path ) || '' === $path ) {
			throw new \RuntimeException( __( 'WordPress could not allocate a temporary file for the package archive.', 'core-blueprint' ) );
		}
		return $path;
	}

	/**
	 * Return regular files that resolve inside the validated package root.
	 * Symlinks are skipped in both archive engines.
	 *
	 * @return string[]
	 */
	private function safe_file_list( string $source_real ): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() || ! $item->isFile() ) {
				continue;
			}

			$item_real = $item->getRealPath();
			if ( false === $item_real || ! $this->path_is_within( $item_real, $source_real ) || ! is_readable( $item_real ) ) {
				continue;
			}
			$files[] = wp_normalize_path( $item_real );
		}

		return $files;
	}

	/** @throws \RuntimeException */
	private function validated_real_path( string $path, string $allowed_root, bool $directory ): string {
		$root_real = realpath( $allowed_root );
		$path_real = realpath( $path );

		if ( false === $root_real || false === $path_real || ! $this->path_is_within( $path_real, $root_real ) ) {
			throw new \RuntimeException( __( 'The requested package path is outside its allowed WordPress directory.', 'core-blueprint' ) );
		}
		if ( $directory && ! is_dir( $path_real ) ) {
			throw new \RuntimeException( __( 'The requested package directory does not exist.', 'core-blueprint' ) );
		}
		if ( ! $directory && ! is_file( $path_real ) ) {
			throw new \RuntimeException( __( 'The requested plugin file does not exist.', 'core-blueprint' ) );
		}
		if ( ! is_readable( $path_real ) ) {
			throw new \RuntimeException( __( 'The requested package is not readable.', 'core-blueprint' ) );
		}

		return wp_normalize_path( $path_real );
	}

	private function path_is_within( string $path, string $root ): bool {
		$path = untrailingslashit( wp_normalize_path( $path ) );
		$root = untrailingslashit( wp_normalize_path( $root ) );
		return $path === $root || str_starts_with( $path . '/', $root . '/' );
	}

	/** @throws \RuntimeException */
	private function validated_archive_segment( string $segment ): string {
		$segment = trim( str_replace( '\\', '/', $segment ), '/' );
		if ( '' === $segment || '.' === $segment || '..' === $segment || str_contains( $segment, '/' ) ) {
			throw new \RuntimeException( __( 'The requested package has an invalid archive name.', 'core-blueprint' ) );
		}
		return $segment;
	}


	private function is_valid_temp_archive( string $archive ): bool {
		$temp = realpath( get_temp_dir() );
		$real = realpath( $archive );
		return is_string( $temp ) && is_string( $real ) && $this->path_is_within( $real, $temp );
	}

	private function delete_if_exists( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
