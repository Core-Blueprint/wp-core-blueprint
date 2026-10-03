<?php
declare(strict_types=1);
/**
 * Provider transport boundary for Core Blueprint Mail.
 *
 * Each provider owns only its transport hook registration. Runtime selects one
 * implementation by slug, so adding another provider does not require changes
 * to WordPress callers or the mail logging layer.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail\Transport;

defined( 'ABSPATH' ) || exit;

interface TransportInterface {
	public static function slug(): string;
	public static function boot(): void;
}
