<?php
declare(strict_types=1);
/**
 * Canonical aggregate Mail module state used by the Dashboard activation card.
 *
 * Mail Delivery and Mail Designer are independent persisted capabilities. The
 * aggregate module state is enabled whenever either capability is enabled.
 * Enabling the module-level control starts Delivery while Designer remains an
 * explicit opt-in; disabling the module disables both capabilities.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Modules\ModuleStateInterface;

defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {
	public static function is_enabled(): bool {
		return Settings::enabled();
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$current = Settings::all();
		$was = self::is_enabled();
		if ( $was === $enabled ) {
			return;
		}

		$previous = $current;
		if ( $enabled ) {
			// The module-level on action starts outbound delivery by default.
			// Designer remains an explicit opt-in capability.
			$current['delivery_enabled'] = true;
		} else {
			$current['delivery_enabled'] = false;
			$current['designer_enabled'] = false;
		}
		Settings::save( $current );

		if ( self::is_enabled() !== $enabled ) {
			Settings::save( $previous );
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'Mail state could not be persisted consistently.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'mail_subsystem_enabled' : 'mail_subsystem_disabled',
				'notice',
				[
					'actor'    => $actor,
					'provider' => Settings::provider(),
					'delivery' => DeliveryState::is_enabled(),
					'designer' => DesignerState::is_enabled(),
				]
			);
		}
	}
}
