<?php
declare(strict_types=1);
/**
 * Stable v1 contract for modules participating in Base's activation registry.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Modules;

defined( 'ABSPATH' ) || exit;

interface ModuleStateInterface {
	public static function is_enabled(): bool;
	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void;
}
