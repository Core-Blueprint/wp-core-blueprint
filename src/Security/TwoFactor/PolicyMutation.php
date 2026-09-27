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

	public static function assert_can_set_mode( string $mode, WP_User $actor ): void {
		$mode = self::normalize_mode( $mode );
		self::assert_trusted_operator( $actor );

		if ( Policy::MODE_ENFORCE !== $mode ) {
			return;
		}
		if ( Failsafe::is_bypassed() ) {
			throw new RuntimeException( __( 'Two-factor enforcement cannot be enabled while Failsafe bypass is active.', 'core-blueprint' ) );
		}
		self::assert_enrolled_operator( $actor );
	}

	/**
	 * Validate the authority required to restore a previously active policy.
	 *
	 * A transaction rollback may restore enforce while Failsafe is active,
	 * because that is restoration of exact pre-transaction state rather than a
	 * new restrictive policy decision. Trusted Operator and Base enrollment
	 * remain mandatory.
	 */
	public static function assert_can_restore_mode( string $mode, WP_User $actor ): void {
		$mode = self::normalize_mode( $mode );
		self::assert_trusted_operator( $actor );
		if ( Policy::MODE_ENFORCE === $mode ) {
			self::assert_enrolled_operator( $actor );
		}
	}

	public static function set_mode( string $mode, WP_User $actor, string $source = 'runtime' ): bool {
		$mode = self::normalize_mode( $mode );
		$before = Policy::mode();
		if ( $before === $mode ) {
			return true;
		}

		self::assert_can_set_mode( $mode, $actor );
		return self::persist_mode( $before, $mode, $actor, $source );
	}

	/**
	 * Restore an exact earlier policy after a bounded transaction failure.
	 *
	 * @internal Profile rollback only.
	 */
	public static function restore_mode(
		string $mode,
		string $expected_current_mode,
		WP_User $actor,
		string $source = 'profile_rollback'
	): bool {
		$mode = self::normalize_mode( $mode );
		$expected_current_mode = self::normalize_mode( $expected_current_mode );
		$current = Policy::mode();

		if ( $current === $mode ) {
			return true;
		}
		if ( $current !== $expected_current_mode ) {
			throw new RuntimeException( __( 'Two-factor policy changed during rollback and was not overwritten.', 'core-blueprint' ) );
		}

		self::assert_can_restore_mode( $mode, $actor );
		return self::persist_mode( $current, $mode, $actor, $source );
	}

	private static function persist_mode( string $before, string $mode, WP_User $actor, string $source ): bool {
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
			throw new RuntimeException( __( 'Could not persist the two-factor policy.', 'core-blueprint' ) );
		}

		Audit::policy_changed( (int) $actor->ID, $before, $mode, $source );
		return true;
	}

	private static function normalize_mode( string $mode ): string {
		$mode = sanitize_key( $mode );
		if ( ! Policy::is_valid_mode( $mode ) ) {
			throw new InvalidArgumentException( __( 'Invalid two-factor policy mode.', 'core-blueprint' ) );
		}
		return $mode;
	}

	private static function assert_trusted_operator( WP_User $actor ): void {
		if ( $actor->ID <= 0 || ! PrivilegedAccessGuard::is_trusted_operator( $actor ) ) {
			throw new RuntimeException( __( 'Two-factor policy changes require a trusted CB Operator.', 'core-blueprint' ) );
		}
	}

	private static function assert_enrolled_operator( WP_User $actor ): void {
		if ( ! CredentialStore::is_enrolled( (int) $actor->ID ) ) {
			throw new RuntimeException( __( 'Two-factor enforcement requires the acting CB Operator to be enrolled in Base two-factor authentication.', 'core-blueprint' ) );
		}
	}
}
