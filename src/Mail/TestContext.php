<?php
declare(strict_types=1);
/**
 * Request-local marker for Core Blueprint test messages.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class TestContext {
	private static bool $active = false;

	public static function begin(): void { self::$active = true; }
	public static function end(): void { self::$active = false; }
	public static function is_active(): bool { return self::$active; }
}
