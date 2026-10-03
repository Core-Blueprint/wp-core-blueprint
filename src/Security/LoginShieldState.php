<?php
declare(strict_types=1);
/**
 * LoginShieldState - ActivationRegistry adapter for Login Shield.
 *
 * Keeps Dashboard master activation on the same canonical login_shield config
 * used by the runtime and settings form. No secondary activation option exists.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Security;

use RuntimeException;

use CoreBlueprint\Core\Modules\ModuleStateInterface;

defined( 'ABSPATH' ) || exit;

final class LoginShieldState implements ModuleStateInterface {

	public static function is_enabled(): bool {
		return ! empty( LoginShield::config()['enabled'] );
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$current = LoginShield::config();
		if ( $enabled === ! empty( $current['enabled'] ) ) {
			return;
		}

		if ( $enabled && '' === LoginShield::sanitize_slug( (string) ( $current['slug'] ?? '' ) ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Configure a custom login URL before enabling Login Shield.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$current['enabled'] = $enabled;
		LoginShield::save( $current, $actor );

		if ( self::is_enabled() !== $enabled ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not update Login Shield.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
