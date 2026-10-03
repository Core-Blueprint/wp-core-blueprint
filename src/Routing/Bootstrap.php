<?php
declare(strict_types=1);
/**
 * URL Governance bootstrap.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Routing;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Runtime::boot();
		Admin::boot();
	}

	private function __construct() {}
}
