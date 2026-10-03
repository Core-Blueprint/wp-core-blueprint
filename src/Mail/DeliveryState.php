<?php
declare(strict_types=1);
/**
 * Independent Mail Delivery capability state.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class DeliveryState {
	public static function is_enabled(): bool {
		return Settings::delivery_enabled();
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$current = Settings::all();
		$was = self::is_enabled();
		if ( $was === $enabled ) {
			return;
		}

		$current['delivery_enabled'] = $enabled;
		Settings::save( $current );

		if ( self::is_enabled() !== $enabled ) {
			throw new \RuntimeException( __( 'Mail Delivery state could not be persisted.', 'core-blueprint' ) );
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'mail_delivery_enabled' : 'mail_delivery_disabled',
				'notice',
				[ 'actor' => $actor, 'provider' => Settings::provider() ]
			);
		}
	}

	private function __construct() {}
}
