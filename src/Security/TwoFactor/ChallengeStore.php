<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use InvalidArgumentException;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Short-lived, one-time pending-login challenge state.
 *
 * The random bearer token is returned to the caller but never persisted.
 * Server-side state is stored under a SHA-256-derived transient key and is
 * consumed atomically before verification. Failed verification must reissue a
 * fresh token, which prevents concurrent reuse of one pending challenge.
 */
final class ChallengeStore {

	public const FLOW_VERIFY = 'verify';
	public const FLOW_ENROLL = 'enroll';

	public const TTL_SECONDS  = 300;
	public const MAX_ATTEMPTS = 5;

	private const VERSION          = 1;
	private const TRANSIENT_PREFIX = 'cb_core_2fa_ch_';
	private const LOCK_PREFIX      = 'cb_core_2fa_ch_lock_';
	private const LOCK_STALE_AFTER = 30;

	/**
	 * @return string 64-character random bearer token.
	 */
	public static function create(
		int $user_id,
		bool $remember,
		string $redirect_to,
		string $flow
	): string {
		if ( $user_id <= 0 || ! in_array( $flow, [ self::FLOW_VERIFY, self::FLOW_ENROLL ], true ) ) {
			throw new InvalidArgumentException( 'Invalid two-factor challenge context.' );
		}

		$now = time();
		return self::persist_state( [
			'version'    => self::VERSION,
			'user_id'    => $user_id,
			'remember'   => $remember,
			'redirect_to'=> wp_validate_redirect( $redirect_to, admin_url() ),
			'flow'       => $flow,
			'attempts'   => 0,
			'created_at' => $now,
			'expires_at' => $now + self::TTL_SECONDS,
		] );
	}

	/**
	 * Inspect valid challenge state without consuming the one-time token.
	 *
	 * Rendering may read a challenge more than once, but factor submission
	 * must always use take() so the bearer token is consumed atomically.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function inspect( string $token ): ?array {
		if ( ! self::valid_token( $token ) ) {
			return null;
		}

		$state = get_transient( self::TRANSIENT_PREFIX . hash( 'sha256', $token ) );
		return is_array( $state ) ? self::normalize_state( $state ) : null;
	}

	/**
	 * Atomically consume and return challenge state.
	 *
	 * A taken token can never be used again, regardless of verification result.
	 * The caller may reissue a new token from the returned state after a failed
	 * factor attempt.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function take( string $token ): ?array {
		if ( ! self::valid_token( $token ) ) {
			return null;
		}

		$hash = hash( 'sha256', $token );
		$lock = self::LOCK_PREFIX . $hash;
		if ( ! self::acquire_lock( $lock ) ) {
			return null;
		}

		try {
			$key   = self::TRANSIENT_PREFIX . $hash;
			$state = get_transient( $key );
			delete_transient( $key );

			if ( ! is_array( $state ) ) {
				return null;
			}
			return self::normalize_state( $state );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Reissue a failed challenge with a fresh bearer token.
	 *
	 * The original absolute expiry is preserved. Reissuing never extends the
	 * password-authenticated window.
	 */
	public static function reissue( array $state ): ?string {
		$state = self::normalize_state( $state );
		if ( null === $state ) {
			return null;
		}

		$next_attempt = (int) $state['attempts'] + 1;
		if ( $next_attempt >= self::MAX_ATTEMPTS ) {
			return null;
		}

		$state['attempts'] = $next_attempt;
		return self::persist_state( $state );
	}

	public static function remaining_attempts( array $state ): int {
		$state = self::normalize_state( $state );
		if ( null === $state ) {
			return 0;
		}
		return max( 0, self::MAX_ATTEMPTS - ( (int) $state['attempts'] + 1 ) );
	}

	/** @param array<string,mixed> $state */
	private static function persist_state( array $state ): string {
		$state = self::normalize_state( $state, false );
		if ( null === $state ) {
			throw new InvalidArgumentException( 'Invalid two-factor challenge state.' );
		}

		$ttl = (int) $state['expires_at'] - time();
		if ( $ttl <= 0 ) {
			throw new RuntimeException( 'Two-factor challenge has expired.' );
		}

		$token = bin2hex( random_bytes( 32 ) );
		$key   = self::TRANSIENT_PREFIX . hash( 'sha256', $token );
		$saved = set_transient( $key, $state, $ttl );

		if ( false === $saved && get_transient( $key ) !== $state ) {
			throw new RuntimeException( 'Could not persist two-factor challenge state.' );
		}

		return $token;
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>|null
	 */
	private static function normalize_state( array $state, bool $require_unexpired = true ): ?array {
		if (
			self::VERSION !== (int) ( $state['version'] ?? 0 )
			|| (int) ( $state['user_id'] ?? 0 ) <= 0
			|| ! in_array( (string) ( $state['flow'] ?? '' ), [ self::FLOW_VERIFY, self::FLOW_ENROLL ], true )
		) {
			return null;
		}

		$attempts   = (int) ( $state['attempts'] ?? -1 );
		$created_at = (int) ( $state['created_at'] ?? 0 );
		$expires_at = (int) ( $state['expires_at'] ?? 0 );
		if (
			$attempts < 0
			|| $attempts >= self::MAX_ATTEMPTS
			|| $created_at <= 0
			|| $expires_at <= $created_at
			|| ( $require_unexpired && $expires_at <= time() )
		) {
			return null;
		}

		return [
			'version'     => self::VERSION,
			'user_id'     => (int) $state['user_id'],
			'remember'    => ! empty( $state['remember'] ),
			'redirect_to' => wp_validate_redirect( (string) ( $state['redirect_to'] ?? '' ), admin_url() ),
			'flow'        => (string) $state['flow'],
			'attempts'    => $attempts,
			'created_at'  => $created_at,
			'expires_at'  => $expires_at,
		];
	}

	private static function valid_token( string $token ): bool {
		return 64 === strlen( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	private static function acquire_lock( string $lock ): bool {
		if ( add_option( $lock, time(), '', false ) ) {
			return true;
		}

		$created = (int) get_option( $lock, 0 );
		if ( $created <= 0 || $created > time() - self::LOCK_STALE_AFTER ) {
			return false;
		}

		delete_option( $lock );
		return (bool) add_option( $lock, time(), '', false );
	}
}
