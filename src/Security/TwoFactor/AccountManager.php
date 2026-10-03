<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Security\TwoFactor;

use CoreBlueprint\Core\Log\AuditLog;
use RuntimeException;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Self-service security boundary for a user's own Base 2FA state.
 *
 * Administrative recovery is deliberately separate and remains server-side.
 */
final class AccountManager {

	public static function start_enrollment( WP_User $user, string $password ): string {
		self::assert_self_privileged_user( $user );
		if ( ProviderDetector::external_provider_owns_user( $user ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'An external two-factor provider already manages this account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( CredentialStore::is_enrolled( (int) $user->ID ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Base two-factor authentication is already active for this account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		self::assert_current_password(
			$user,
			$password,
			__( 'Password confirmation failed. Two-factor setup was not changed.', 'core-blueprint' )
		);
		return EnrollmentStore::start( (int) $user->ID );
	}

	public static function cancel_enrollment( WP_User $user ): void {
		self::assert_self_privileged_user( $user );
		EnrollmentStore::clear( (int) $user->ID );
	}

	/** @return string[] */
	public static function confirm_enrollment( WP_User $user, string $password, string $code ): array {
		self::assert_self_privileged_user( $user );
		if ( ProviderDetector::external_provider_owns_user( $user ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'An external two-factor provider already manages this account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		self::assert_current_password(
			$user,
			$password,
			__( 'Password confirmation failed. Two-factor setup was not changed.', 'core-blueprint' )
		);

		$codes = EnrollmentStore::confirm( (int) $user->ID, $code );
		if ( ! is_array( $codes ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The verification code was not accepted. Try again.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $codes;
	}

	/**
	 * Replace every recovery code after re-verifying password and the active factor.
	 *
	 * @return string[] Plaintext replacement codes shown once to the user.
	 */
	public static function regenerate_recovery_codes( WP_User $user, string $password, string $factor ): array {
		self::assert_self_privileged_user( $user );
		$user_id = (int) $user->ID;

		if ( ! CredentialStore::is_enrolled( $user_id ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Base two-factor authentication is not active for this account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ProviderDetector::external_provider_owns_user( $user ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'An external two-factor provider already manages this account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		self::assert_current_password(
			$user,
			$password,
			__( 'Password confirmation failed. Recovery codes were not changed.', 'core-blueprint' )
		);

		$method = '';
		if ( Authenticator::verify_totp( $user_id, $factor ) ) {
			$method = 'totp';
		} elseif ( Authenticator::verify_recovery_code( $user_id, $factor ) ) {
			$method = 'recovery';
		}
		if ( '' === $method ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Two-factor verification failed. Recovery codes were not changed.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$codes = RecoveryCodes::regenerate_for_user( $user_id );
		if ( [] === $codes ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Recovery codes could not be regenerated. Try again.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		Audit::recovery_codes_regenerated( $user_id, $method );
		return $codes;
	}

	/**
	 * Remove Base 2FA after re-verifying both password and the active factor.
	 *
	 * @return array<string,mixed>
	 */
	public static function remove( WP_User $user, string $password, string $factor ): array {
		self::assert_self_privileged_user( $user );
		$user_id = (int) $user->ID;

		if ( ! CredentialStore::is_enrolled( $user_id ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Base two-factor authentication is not active for this account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$external_owner = ProviderDetector::external_provider_owns_user( $user );
		if ( Policy::requires_enrollment( $user ) && ! $external_owner ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Base two-factor authentication cannot be removed while enforcement is required for this account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		self::assert_current_password(
			$user,
			$password,
			__( 'Password confirmation failed. Two-factor authentication was not removed.', 'core-blueprint' )
		);

		$method = '';
		if ( Authenticator::verify_totp( $user_id, $factor ) ) {
			$method = 'totp';
		} elseif ( Authenticator::verify_recovery_code( $user_id, $factor ) ) {
			$method = 'recovery';
		}
		if ( '' === $method ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Two-factor verification failed. Two-factor authentication was not removed.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$stats = RecoveryManager::reset_user( $user, 'profile_remove' );
		Audit::removed( $user_id, $method );
		return $stats;
	}

	private static function assert_current_password( WP_User $user, string $password, string $error_message ): void {
		if ( '' !== $password && wp_check_password( $password, (string) $user->user_pass, (int) $user->ID ) ) {
			return;
		}

		AuditLog::log( 'security.password_reconfirm_failed', 'warning', [
			'user_login' => (string) $user->user_login,
		] );
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
		throw new RuntimeException( $error_message );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	private static function assert_self_privileged_user( WP_User $user ): void {
		if (
			$user->ID <= 0
			|| get_current_user_id() !== (int) $user->ID
			|| ! Policy::is_in_scope( $user )
		) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Base two-factor self-service is available only for your own privileged account.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
