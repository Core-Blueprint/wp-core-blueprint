<?php
declare(strict_types=1);
/**
 * CoreShieldState - ActivationRegistry adapter for the Core Shield master gate.
 *
 * The canonical state remains cb_core_settings.shield_enabled via Settings;
 * this class only exposes the shared is_enabled()/set_enabled() contract.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Security;

use CoreBlueprint\Core\Settings;
use RuntimeException;

use CoreBlueprint\Core\Modules\ModuleStateInterface;

defined( 'ABSPATH' ) || exit;

final class CoreShieldState implements ModuleStateInterface {

	public static function is_enabled(): bool {
		return Settings::shield_enabled();
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		if ( self::is_enabled() === $enabled ) {
			return;
		}

		Settings::set_shield_enabled( $enabled, $actor );
		if ( self::is_enabled() !== $enabled ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not update Core Shield.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
