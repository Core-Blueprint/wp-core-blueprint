<?php
declare(strict_types=1);

namespace CB\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class AtomicFile {
	public static function write( string $path, string $contents ): bool {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$tmp = tempnam( $dir, '.cb-snippet-' );
		if ( false === $tmp ) {
			return false;
		}

		$ok     = false;
		$handle = @fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $handle ) {
			try {
				$length  = strlen( $contents );
				$written = 0;
				while ( $written < $length ) {
					$chunk = fwrite( $handle, substr( $contents, $written ) );
					if ( false === $chunk || 0 === $chunk ) {
						break;
					}
					$written += $chunk;
				}
				if ( $written === $length && fflush( $handle ) ) {
					if ( function_exists( 'fsync' ) ) {
						@fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					$ok = true;
				}
			} finally {
				fclose( $handle );
			}
		}

		if ( ! $ok || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		clearstatcache( true, $path );
		return true;
	}
}
