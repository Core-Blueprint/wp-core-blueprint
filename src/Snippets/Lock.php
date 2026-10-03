<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class Lock {
	public static function run( callable $callback ) {
		if ( ! Paths::ensure() ) {
			throw new \RuntimeException( 'Core Blueprint Snippets storage is not writable.' );
		}

		$handle = @fopen( Paths::lock_file(), 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
			fclose( $handle );
		}
	}
}
