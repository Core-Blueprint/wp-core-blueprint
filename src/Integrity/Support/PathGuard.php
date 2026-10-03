<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function explode;
use function is_string;
use function ltrim;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function wp_normalize_path;

/**
 * Small, explicit path-safety helper for integrity scanners.
 *
 * Checksum manifests are external data. Even trusted WordPress.org payloads
 * should never be allowed to steer file reads outside the component root.
 */
final class PathGuard {
	public static function normalise_relative( string $path ): ?string {
		$path = str_replace( '\\', '/', $path );
		$path = ltrim( $path, '/' );

		if ( '' === $path || str_contains( $path, "\0" ) ) {
			return null;
		}

		// Reject URL-ish / drive-letter inputs before segment processing.
		if ( str_contains( $path, '://' ) || ( isset( $path[1] ) && ':' === $path[1] ) ) {
			return null;
		}

		$segments = explode( '/', $path );
		$clean    = [];

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return null;
			}
			$clean[] = $segment;
		}

		if ( [] === $clean ) {
			return null;
		}

		return implode( '/', $clean );
	}

	public static function join( string $root, string $relative ): ?string {
		$relative = self::normalise_relative( $relative );
		if ( null === $relative ) {
			return null;
		}

		$root = rtrim( wp_normalize_path( $root ), '/' );
		if ( '' === $root ) {
			return null;
		}

		return $root . '/' . $relative;
	}

	public static function is_inside( string $path, string $root ): bool {
		$path = rtrim( wp_normalize_path( $path ), '/' );
		$root = rtrim( wp_normalize_path( $root ), '/' );

		return $path === $root || str_starts_with( $path, $root . '/' );
	}

	/**
	 * Verify an existing path resolves inside the canonical component root.
	 * This catches symlink escapes as well as lexical prefix tricks.
	 */
	public static function existing_path_is_inside( string $path, string $root ): bool {
		$resolved_path = realpath( $path );
		$resolved_root = realpath( $root );

		if ( ! is_string( $resolved_path ) || ! is_string( $resolved_root ) ) {
			return false;
		}

		return self::is_inside( wp_normalize_path( $resolved_path ), wp_normalize_path( $resolved_root ) );
	}
}
