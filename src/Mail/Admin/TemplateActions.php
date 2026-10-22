<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The native Base Mail Platform owns all document mutations through
 * PlatformTransport -> TransactionalSave -> Repository.
 *
 * These pre-v1 admin-post/AJAX mutation hooks MUST NOT be registered.
 * This class is intentionally inert rather than a compatibility writer.
 */
final class TemplateActions {
	public static function boot(): void {
		// No independent admin-post or AJAX Save/Reset authorities.
	}

	public static function save(): void {
		self::refuse();
	}

	public static function save_ajax(): void {
		self::refuse();
	}

	public static function reset(): void {
		self::refuse();
	}

	private static function refuse(): never {
		wp_die(
			esc_html__( 'Mail Designer now saves exclusively through the authorized Platform.', 'core-blueprint' ),
			esc_html__( 'Operation unavailable', 'core-blueprint' ),
			[ 'response' => 410 ]
		);
	}

	private function __construct() {}
}
