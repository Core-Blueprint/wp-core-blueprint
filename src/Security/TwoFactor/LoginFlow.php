<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use CB\Core\Security\Failsafe;
use WP_Error;
use WP_Session_Tokens;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Interactive WordPress login boundary for Base-owned privileged 2FA.
 *
 * Username/password authentication is allowed to complete so WordPress keeps
 * its normal credential semantics, but the generated auth cookies are withheld
 * and the temporary password-stage session token is destroyed. Only a valid
 * one-time challenge can establish the final WordPress session.
 */
final class LoginFlow {

	public const ACTION = 'cb_two_factor';
	public const PARAM  = 'cb_2fa_ch';

	public const DECISION_NONE   = 'none';
	public const DECISION_VERIFY = 'verify';
	public const DECISION_ENROLL = 'enroll';

	/** @var array<int,string> */
	private static array $pending = [];

	/** @var array<int,array<string,bool>> */
	private static array $password_tokens = [];

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'authenticate', [ self::class, 'filter_authenticate' ], 31, 3 );
		add_filter( 'send_auth_cookies', [ self::class, 'filter_send_auth_cookies' ], PHP_INT_MAX, 6 );
		add_action( 'set_auth_cookie', [ self::class, 'capture_auth_token' ], PHP_INT_MAX, 6 );
		add_action( 'wp_login', [ self::class, 'after_password_login' ], PHP_INT_MAX, 2 );
		add_action( 'login_form_' . self::ACTION, [ LoginController::class, 'handle' ] );
	}

	public static function decision_for( WP_User $user ): string {
		if (
			$user->ID <= 0
			|| Failsafe::is_bypassed()
			|| ! Policy::is_in_scope( $user )
			|| ProviderDetector::external_provider_owns_user( $user )
		) {
			return self::DECISION_NONE;
		}

		if ( CredentialStore::is_enrolled( (int) $user->ID ) ) {
			return self::DECISION_VERIFY;
		}

		return Policy::requires_enrollment( $user )
			? self::DECISION_ENROLL
			: self::DECISION_NONE;
	}

	public static function filter_authenticate( WP_User|WP_Error|null $user, string $username = '', string $password = '' ): WP_User|WP_Error|null {
		unset( $username, $password );

		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		$decision = self::decision_for( $user );
		if ( self::DECISION_NONE === $decision ) {
			return $user;
		}

		if ( ! self::is_interactive_login_request() ) {
			return new WP_Error(
				'cb_core_two_factor_interactive_required',
				__( 'This account requires an interactive two-factor sign-in.', 'core-blueprint' )
			);
		}

		self::$pending[ (int) $user->ID ] = $decision;
		return $user;
	}

	public static function filter_send_auth_cookies(
		bool $send,
		int $expire = 0,
		int $expiration = 0,
		int $user_id = 0,
		string $scheme = '',
		string $token = ''
	): bool {
		unset( $expire, $expiration, $scheme, $token );
		return isset( self::$pending[ $user_id ] ) ? false : $send;
	}

	public static function capture_auth_token(
		string $auth_cookie,
		int $expire,
		int $expiration,
		int $user_id,
		string $scheme,
		string $token
	): void {
		unset( $auth_cookie, $expire, $expiration, $scheme );
		if ( ! isset( self::$pending[ $user_id ] ) || '' === $token ) {
			return;
		}
		self::$password_tokens[ $user_id ][ $token ] = true;
	}

	public static function is_password_stage_pending( int $user_id ): bool {
		return $user_id > 0 && isset( self::$pending[ $user_id ] );
	}

	public static function after_password_login( string $user_login, WP_User $user ): void {
		unset( $user_login );

		$user_id = (int) $user->ID;
		if ( ! isset( self::$pending[ $user_id ] ) ) {
			return;
		}

		$remember = ! empty( $_POST['rememberme'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Password authentication is the authority for this transition.
		$redirect = isset( $_REQUEST['redirect_to'] ) && is_scalar( $_REQUEST['redirect_to'] )
			? wp_unslash( (string) $_REQUEST['redirect_to'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stored only after wp_validate_redirect().
			: admin_url();

		$challenge = self::finalize_password_stage( $user, $remember, $redirect );

		wp_safe_redirect( $challenge['url'] );
		exit;
	}

	/**
	 * Finish the password stage without exposing a reusable WordPress session.
	 *
	 * @return array{token:string,url:string,flow:string}
	 */
	public static function finalize_password_stage( WP_User $user, bool $remember, string $redirect_to ): array {
		$user_id = (int) $user->ID;
		$flow = self::$pending[ $user_id ] ?? self::DECISION_NONE;
		if ( ! in_array( $flow, [ self::DECISION_VERIFY, self::DECISION_ENROLL ], true ) ) {
			throw new \RuntimeException( 'Two-factor password stage is not pending.' );
		}

		$challenge_flow = self::DECISION_ENROLL === $flow
			? ChallengeStore::FLOW_ENROLL
			: ChallengeStore::FLOW_VERIFY;

		$token = ChallengeStore::create(
			$user_id,
			$remember,
			wp_validate_redirect( $redirect_to, admin_url() ),
			$challenge_flow
		);

		self::destroy_password_stage_tokens( $user_id );
		unset( self::$pending[ $user_id ] );

		return [
			'token' => $token,
			'url'   => self::challenge_url( $token ),
			'flow'  => $challenge_flow,
		];
	}

	public static function challenge_url( string $token ): string {
		return add_query_arg(
			[
				'action'      => self::ACTION,
				self::PARAM   => $token,
			],
			wp_login_url()
		);
	}

	public static function establish_authenticated_session(
		WP_User $user,
		bool $remember,
		string $redirect_to,
		string $method
	): string {
		wp_set_auth_cookie( (int) $user->ID, $remember );
		do_action( 'cb_core_two_factor_authenticated', $user, $method );

		$safe = wp_validate_redirect( $redirect_to, admin_url() );
		return (string) apply_filters( 'login_redirect', $safe, $safe, $user );
	}

	/**
	 * Test/runtime cleanup for request-local state. Persistent credentials and
	 * challenge state are deliberately untouched.
	 *
	 * @internal
	 */
	public static function reset_request_state(): void {
		self::$pending = [];
		self::$password_tokens = [];
	}

	private static function destroy_password_stage_tokens( int $user_id ): void {
		$tokens = array_keys( self::$password_tokens[ $user_id ] ?? [] );
		if ( [] !== $tokens ) {
			$manager = WP_Session_Tokens::get_instance( $user_id );
			foreach ( $tokens as $token ) {
				$manager->destroy( $token );
			}
		}
		unset( self::$password_tokens[ $user_id ] );
	}

	private static function is_interactive_login_request(): bool {
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === (string) $GLOBALS['pagenow'] ) {
			return true;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		return 'wp-login.php' === $script;
	}
}
