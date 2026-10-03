<?php
declare(strict_types=1);
/**
 * Compliance Resources subsystem bootstrap.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Compliance;

use CoreBlueprint\Core\Compliance\Admin\Actions;
use CoreBlueprint\Core\Compliance\Admin\ObjectSearch;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		ResourceRegistry::init();
		Actions::init();
		ObjectSearch::init();
		add_action( 'init', [ Shortcode::class, 'register' ], 20 );
	}
}
