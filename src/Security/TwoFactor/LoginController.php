<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal functional wp-login.php controller for Base-owned 2FA.
 *
 * Presentation is intentionally conservative in this workstream. Final visual
 * polish belongs to the later Base UI pass; the security transitions here are
 * the canonical runtime contract.
 */
final class LoginController {

	/**
	 * @return array<string,mixed>
	 */
	public static function prepare( string $token ): array {
		$state = ChallengeStore::inspect( $token );
		if ( ! is_array( $state ) ) {
			return [ 'status' => 'invalid' ];
		}

		$user = get_userdata( (int) $state['user_id'] );
		if ( ! ( $user instanceof WP_User ) ) {
			return [ 'status' => 'invalid' ];
		}

		if ( ProviderDetector::external_provider_owns_user( $user ) ) {
			return [ 'status' => 'restart' ];
		}

		$result = [
			'status' => 'ready',
			'state'  => $state,
			'user'   => $user,
			'token'  => $token,
		];

		if ( ChallengeStore::FLOW_ENROLL === (string) $state['flow'] ) {
			$secret = EnrollmentStore::start( (int) $user->ID );
			$result['secret'] = $secret;
			$result['provisioning_uri'] = self::provisioning_uri( $user, $secret );
		}

		return $result;
	}

	/**
	 * Consume a factor submission.
	 *
	 * @return array<string,mixed>
	 */
	public static function process( string $token, string $candidate ): array {
		$state = ChallengeStore::take( $token );
		if ( ! is_array( $state ) ) {
			return [ 'status' => 'invalid' ];
		}

		$user = get_userdata( (int) $state['user_id'] );
		if ( ! ( $user instanceof WP_User ) ) {
			return [ 'status' => 'invalid' ];
		}

		if ( ProviderDetector::external_provider_owns_user( $user ) ) {
			return [ 'status' => 'restart' ];
		}

		if ( ChallengeStore::FLOW_ENROLL === (string) $state['flow'] ) {
			$codes = EnrollmentStore::confirm( (int) $user->ID, $candidate );
			if ( is_array( $codes ) ) {
				return [
					'status'         => 'success',
					'state'          => $state,
					'user'           => $user,
					'method'         => 'totp',
					'recovery_codes' => $codes,
				];
			}
			return self::failed_result( $state );
		}

		if ( Authenticator::verify_totp( (int) $user->ID, $candidate ) ) {
			return [
				'status' => 'success',
				'state'  => $state,
				'user'   => $user,
				'method' => 'totp',
			];
		}

		if ( Authenticator::verify_recovery_code( (int) $user->ID, $candidate ) ) {
			return [
				'status' => 'success',
				'state'  => $state,
				'user'   => $user,
				'method' => 'recovery',
			];
		}

		return self::failed_result( $state );
	}

	public static function handle(): void {
		$token = isset( $_REQUEST[ LoginFlow::PARAM ] ) && is_scalar( $_REQUEST[ LoginFlow::PARAM ] )
			? sanitize_text_field( wp_unslash( (string) $_REQUEST[ LoginFlow::PARAM ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer challenge is the request authority.
			: '';

		$is_post = isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( $is_post ) {
			$candidate = isset( $_POST['cb_two_factor_code'] ) && is_scalar( $_POST['cb_two_factor_code'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['cb_two_factor_code'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- One-time bearer challenge authorizes this form.
				: '';

			$result = self::process( $token, $candidate );
			if ( 'success' === (string) ( $result['status'] ?? '' ) ) {
				$user  = $result['user'];
				$state = $result['state'];
				$url = LoginFlow::establish_authenticated_session(
					$user,
					! empty( $state['remember'] ),
					(string) $state['redirect_to'],
					(string) $result['method']
				);

				if ( ! empty( $result['recovery_codes'] ) ) {
					self::render_recovery_codes( $result['recovery_codes'], $url );
					exit;
				}

				wp_safe_redirect( $url );
				exit;
			}

			if ( 'retry' === (string) ( $result['status'] ?? '' ) ) {
				self::render_form(
					(string) $result['token'],
					$result['state'],
					__( 'The verification code was not accepted. Try again.', 'core-blueprint' )
				);
				exit;
			}

			self::render_terminal_error();
			exit;
		}

		$result = self::prepare( $token );
		if ( 'ready' !== (string) ( $result['status'] ?? '' ) ) {
			self::render_terminal_error();
			exit;
		}

		self::render_form(
			$token,
			$result['state'],
			'',
			isset( $result['secret'] ) ? (string) $result['secret'] : '',
			isset( $result['provisioning_uri'] ) ? (string) $result['provisioning_uri'] : ''
		);
		exit;
	}

	/** @param array<string,mixed> $state */
	private static function failed_result( array $state ): array {
		$token = ChallengeStore::reissue( $state );
		if ( null === $token ) {
			return [ 'status' => 'locked' ];
		}
		return [
			'status' => 'retry',
			'token'  => $token,
			'state'  => ChallengeStore::inspect( $token ) ?? $state,
		];
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private static function render_form(
		string $token,
		array $state,
		string $error = '',
		string $secret = '',
		string $provisioning_uri = ''
	): void {
		login_header(
			__( 'Two-factor authentication', 'core-blueprint' ),
			'' !== $error ? '<div id="login_error">' . esc_html( $error ) . '</div>' : ''
		);

		$action = LoginFlow::challenge_url( $token );
		$is_enroll = ChallengeStore::FLOW_ENROLL === (string) ( $state['flow'] ?? '' );
		if ( $is_enroll && '' !== $secret ) {
			echo '<p class="message">';
			echo esc_html__( 'Add this account to your authenticator app, then enter the six-digit code to finish enrollment.', 'core-blueprint' );
			echo '</p>';
			echo '<p><strong>' . esc_html__( 'Setup key', 'core-blueprint' ) . '</strong><br><code>' . esc_html( $secret ) . '</code></p>';
			if ( '' !== $provisioning_uri ) {
				echo '<p><a href="' . esc_attr( $provisioning_uri ) . '">' . esc_html__( 'Open in an authenticator app', 'core-blueprint' ) . '</a></p>';
			}
		} else {
			echo '<p class="message">' . esc_html__( 'Enter your authenticator code or a recovery code.', 'core-blueprint' ) . '</p>';
		}

		echo '<form name="cb-two-factor" method="post" action="' . esc_url( $action ) . '">';
		echo '<p><label for="cb_two_factor_code">' . esc_html__( 'Verification code', 'core-blueprint' ) . '</label>';
		echo '<input type="text" name="cb_two_factor_code" id="cb_two_factor_code" class="input" value="" size="24" autocomplete="one-time-code" inputmode="numeric" autofocus></p>';
		echo '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Verify', 'core-blueprint' ) . '"></p>';
		echo '</form>';

		login_footer();
	}

	/** @param string[] $codes */
	private static function render_recovery_codes( array $codes, string $continue_url ): void {
		login_header( __( 'Save your recovery codes', 'core-blueprint' ) );
		echo '<p class="message">' . esc_html__( 'Two-factor authentication is active. Save these recovery codes now. Each code can be used once.', 'core-blueprint' ) . '</p>';
		echo '<ul>';
		foreach ( $codes as $code ) {
			echo '<li><code>' . esc_html( $code ) . '</code></li>';
		}
		echo '</ul>';
		echo '<p><a class="button button-primary button-large" href="' . esc_url( $continue_url ) . '">' . esc_html__( 'Continue', 'core-blueprint' ) . '</a></p>';
		login_footer();
	}

	private static function render_terminal_error(): void {
		login_header( __( 'Two-factor authentication', 'core-blueprint' ) );
		echo '<div id="login_error">' . esc_html__( 'This two-factor sign-in can no longer be used. Start a new sign-in attempt.', 'core-blueprint' ) . '</div>';
		echo '<p><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Back to sign in', 'core-blueprint' ) . '</a></p>';
		login_footer();
	}

	private static function provisioning_uri( WP_User $user, string $secret ): string {
		$issuer = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === trim( $issuer ) ) {
			$issuer = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'WordPress';
		}
		$label = $issuer . ':' . (string) $user->user_login;

		return 'otpauth://totp/' . rawurlencode( $label ) . '?' . http_build_query(
			[
				'secret'    => $secret,
				'issuer'    => $issuer,
				'algorithm' => 'SHA1',
				'digits'    => Totp::DIGITS,
				'period'    => Totp::PERIOD,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}
}
