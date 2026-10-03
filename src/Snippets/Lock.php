<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class Lock {
	public static function run( callable $callback ) {
		if ( ! Paths::ensure() ) {
			throw new \RuntimeException( 'Core Blueprint Snippets storage is not writable.' );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: managed snippets require a native flock-compatible file handle.
		$handle = @fopen( Paths::lock_file(), 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// phpcs:enable WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open Core Blueprint Snippets storage lock.' );
		}

		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new \RuntimeException( 'Could not acquire Core Blueprint Snippets storage lock.' );
			}
			return $callback();
		} finally {
			flock( $handle, LOCK_UN );
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: managed snippets require a native flock-compatible file handle.
			fclose( $handle );
			// phpcs:enable WordPress.WP.AlternativeFunctions
		}
	}
}
