<?php
declare(strict_types=1);
/**
 * Media Formats module master-switch state.
 *
 * New module: disabled by default until an operator explicitly enables it.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats;

use CoreBlueprint\Core\Modules\ModuleStateInterface;

defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {
	public static function is_enabled(): bool {
		return Settings::all()['enabled'];
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		Settings::set_enabled( $enabled, $actor );
	}
}
