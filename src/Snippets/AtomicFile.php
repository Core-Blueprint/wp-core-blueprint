<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

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
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: managed snippets require durable stream writes and atomic replacement semantics.
		$handle = @fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// phpcs:enable WordPress.WP.AlternativeFunctions
		if ( false !== $handle ) {
			try {
				$length  = strlen( $contents );
				$written = 0;
				while ( $written < $length ) {
					// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: managed snippets require durable stream writes and atomic replacement semantics.
					$chunk = fwrite( $handle, substr( $contents, $written ) );
					// phpcs:enable WordPress.WP.AlternativeFunctions
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
				// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: managed snippets require durable stream writes and atomic replacement semantics.
				fclose( $handle );
				// phpcs:enable WordPress.WP.AlternativeFunctions
			}
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: managed snippets require durable stream writes and atomic replacement semantics.
		if ( ! $ok || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// phpcs:enable WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: managed snippets require durable stream writes and atomic replacement semantics.
		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// phpcs:enable WordPress.WP.AlternativeFunctions
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		clearstatcache( true, $path );
		return true;
	}
}
