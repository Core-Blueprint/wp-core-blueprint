<?php
declare(strict_types=1);

/**
 * Test-only environment identity seam.
 *
 * WordPress caches wp_get_environment_type() after its first call outside core
 * tests. Base may read the environment during bootstrap, so integration tests
 * cannot reliably switch WP_ENVIRONMENT_TYPE later with putenv(). This shim is
 * defined before Base loads and defaults to the real WordPress API unless an
 * Environment Governance test explicitly sets an allowed override.
 */

namespace CoreBlueprint\Core\Environment;

final class EnvironmentTypeTestShim {
	private static ?string $override = null;

	public static function set( string $type ): void {
		if ( ! in_array( $type, [ 'local', 'development', 'staging', 'production' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid WordPress environment type fixture.' );
		}

		self::$override = $type;
	}

	public static function reset(): void {
		self::$override = null;
	}

	public static function current(): string {
		return self::$override ?? \wp_get_environment_type();
	}

	private function __construct() {}
}

function wp_get_environment_type(): string {
	return EnvironmentTypeTestShim::current();
}
