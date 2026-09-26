<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Security\Failsafe;
use CB\Core\Settings;
use InvalidArgumentException;
use RuntimeException;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical mutation boundary for the site-wide Base 2FA policy.
 *
 * Policy is portable configuration, but changing its enforcement state is a
 * privileged governance action. Every supported writer must come through this
 * boundary rather than writing Settings::two_factor directly.
 */
final class PolicyMutation {

	public static function set_mode( string $mode, WP_User $actor, string $source = 'runtime' ): bool {
		$mode = sanitize_key( $mode );
		if ( ! Policy::is_valid_mode( $mode ) ) {
			throw new InvalidArgumentException( 'Invalid two-factor policy mode.' );
		}
		if ( $actor->ID <= 0 || ! PrivilegedAccessGuard::is_trusted_operator( $actor ) ) {
			throw new RuntimeException( 'Two-factor policy changes require a trusted CB Operator.' );
		}

		$before = Policy::mode();
		if ( $before === $mode ) {
			return true;
		}

		if ( Policy::MODE_ENFORCE === $mode ) {
			if ( Failsafe::is_bypassed() ) {
				throw new RuntimeException( 'Two-factor enforcement cannot be enabled while Failsafe bypass is active.' );
			}
			if ( ! CredentialStore::is_enrolled( (int) $actor->ID ) ) {
				throw new RuntimeException( 'Two-factor enforcement requires the acting CB Operator to be enrolled in Base two-factor authentication.' );
			}
		}

		$config = Policy::config();
		$config['mode'] = $mode;
		$source = sanitize_key( $source );
		if ( '' === $source ) {
			$source = 'runtime';
		}

		$saved = Settings::set_key(
			Policy::SETTINGS_KEY,
			$config,
			'two_factor:' . $source
		);
		if ( ! $saved && Policy::mode() !== $mode ) {
			throw new RuntimeException( 'Could not persist the two-factor policy.' );
		}

		Audit::policy_changed( (int) $actor->ID, $before, $mode, $source );
		return true;
	}
}
