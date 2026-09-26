<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Native WordPress profile surface for a user's own Base 2FA state.
 *
 * Other-user administration is deliberately excluded. Operator recovery stays
 * server-side through WP-CLI. TOTP setup material is rendered only immediately
 * after password-confirmed enrollment start and is never stored in plaintext.
 */
final class ProfileController {

	public const START_ACTION   = 'cb_core_two_factor_profile_start';
	public const CONFIRM_ACTION = 'cb_core_two_factor_profile_confirm';
	public const CANCEL_ACTION  = 'cb_core_two_factor_profile_cancel';
	public const REMOVE_ACTION     = 'cb_core_two_factor_profile_remove';
	public const REGENERATE_ACTION = 'cb_core_two_factor_profile_regenerate_recovery';

	private const NOTICE_PREFIX = 'cb_core_two_factor_profile_notice_';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'show_user_profile', [ self::class, 'render' ] );
		add_action( 'admin_post_' . self::START_ACTION, [ self::class, 'start' ] );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, [ self::class, 'confirm' ] );
		add_action( 'admin_post_' . self::CANCEL_ACTION, [ self::class, 'cancel' ] );
		add_action( 'admin_post_' . self::REMOVE_ACTION, [ self::class, 'remove' ] );
		add_action( 'admin_post_' . self::REGENERATE_ACTION, [ self::class, 'regenerate_recovery_codes' ] );
	}

	public static function render( WP_User $profile_user ): void {
		if (
			$profile_user->ID <= 0
			|| get_current_user_id() !== (int) $profile_user->ID
			|| ! Policy::is_in_scope( $profile_user )
		) {
			return;
		}

		$user_id   = (int) $profile_user->ID;
		$providers = ProviderDetector::providers_for_user( $profile_user );
		$enrolled  = CredentialStore::is_enrolled( $user_id );
		$pending   = ! $enrolled && EnrollmentStore::has_pending( $user_id );
		$notice    = self::take_notice( $user_id );

		echo '<h2 id="cb-core-two-factor">' . esc_html__( 'Two-factor authentication', 'core-blueprint' ) . '</h2>';

		if ( null !== $notice ) {
			$class = 'error' === $notice['type'] ? 'notice notice-error inline' : 'notice notice-success inline';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th>' . esc_html__( 'Base two-factor authentication', 'core-blueprint' ) . '</th><td>';

		if ( [] !== $providers ) {
			echo '<p><strong>' . esc_html__( 'External provider active', 'core-blueprint' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'An external provider currently manages two-factor authentication for this account. Base will not add a second login challenge.', 'core-blueprint' ) . '</p>';
			echo '<p><code>' . esc_html( implode( ', ', $providers ) ) . '</code></p>';
		}

		if ( $enrolled ) {
			self::render_enrolled( $profile_user, $providers );
		} elseif ( $pending ) {
			self::render_pending( [] !== $providers );
		} elseif ( [] === $providers ) {
			self::render_start_form();
		}

		echo '</td></tr></tbody></table>';
	}

	public static function start(): void {
		$user = self::require_action_user( self::START_ACTION );

		try {
			$secret = AccountManager::start_enrollment( $user, self::posted_password() );
			self::render_setup_secret( $secret );
		} catch ( \Throwable $error ) {
			self::set_notice( (int) $user->ID, 'error', $error->getMessage() );
			self::redirect_profile();
		}
	}

	public static function confirm(): void {
		$user = self::require_action_user( self::CONFIRM_ACTION );

		try {
			$codes = AccountManager::confirm_enrollment(
				$user,
				self::posted_password(),
				self::posted_code()
			);
			self::render_recovery_codes( $codes );
		} catch ( \Throwable $error ) {
			self::set_notice( (int) $user->ID, 'error', $error->getMessage() );
			self::redirect_profile();
		}
	}

	public static function cancel(): void {
		$user = self::require_action_user( self::CANCEL_ACTION );

		try {
			AccountManager::cancel_enrollment( $user, self::posted_password() );
			self::set_notice( (int) $user->ID, 'success', __( 'Pending two-factor setup was cancelled.', 'core-blueprint' ) );
		} catch ( \Throwable $error ) {
			self::set_notice( (int) $user->ID, 'error', $error->getMessage() );
		}

		self::redirect_profile();
	}

	public static function regenerate_recovery_codes(): void {
		$user = self::require_action_user( self::REGENERATE_ACTION );

		try {
			$codes = AccountManager::regenerate_recovery_codes(
				$user,
				self::posted_password(),
				self::posted_code()
			);
			self::render_recovery_codes( $codes );
		} catch ( \Throwable $error ) {
			self::set_notice( (int) $user->ID, 'error', $error->getMessage() );
			self::redirect_profile();
		}
	}

	public static function remove(): void {
		$user = self::require_action_user( self::REMOVE_ACTION );

		try {
			AccountManager::remove(
				$user,
				self::posted_password(),
				self::posted_code()
			);
			self::set_notice( (int) $user->ID, 'success', __( 'Base two-factor authentication was removed.', 'core-blueprint' ) );
		} catch ( \Throwable $error ) {
			self::set_notice( (int) $user->ID, 'error', $error->getMessage() );
		}

		self::redirect_profile();
	}

	private static function render_enrolled( WP_User $user, array $providers ): void {
		echo '<p><strong>' . esc_html__( 'Base two-factor authentication is active.', 'core-blueprint' ) . '</strong></p>';
		echo '<p>' . esc_html( sprintf(
			/* translators: %d: number of unused recovery codes */
			__( 'Unused recovery codes: %d', 'core-blueprint' ),
			RecoveryCodes::remaining( (int) $user->ID )
		) ) . '</p>';

		if ( [] === $providers ) {
			echo '<p>' . esc_html__( 'Generate a new set of recovery codes by confirming your current password and a current authenticator or recovery code.', 'core-blueprint' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::hidden_action( self::REGENERATE_ACTION );
			self::render_password_field();
			echo '<p><label>' . esc_html__( 'Authenticator or recovery code', 'core-blueprint' ) . '<br>';
			echo '<input type="text" class="regular-text" name="cb_two_factor_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required></label></p>';
			submit_button( __( 'Generate new recovery codes', 'core-blueprint' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		if ( Policy::requires_enrollment( $user ) && [] === $providers ) {
			echo '<p>' . esc_html__( 'Site policy requires two-factor authentication for this account, so Base two-factor authentication cannot be removed here.', 'core-blueprint' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'To remove Base two-factor authentication, confirm your current password and a current authenticator or recovery code.', 'core-blueprint' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden_action( self::REMOVE_ACTION );
		self::render_password_field();
		echo '<p><label for="cb_two_factor_remove_code">' . esc_html__( 'Authenticator or recovery code', 'core-blueprint' ) . '</label><br>';
		echo '<input type="text" class="regular-text" name="cb_two_factor_code" id="cb_two_factor_remove_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required></p>';
		submit_button( __( 'Remove Base two-factor authentication', 'core-blueprint' ), 'delete', 'submit', false );
		echo '</form>';
	}

	private static function render_pending( bool $external_provider ): void {
		echo '<p><strong>' . esc_html__( 'Two-factor setup is waiting for confirmation.', 'core-blueprint' ) . '</strong></p>';
		if ( $external_provider ) {
			echo '<p>' . esc_html__( 'An external provider is now active. Cancel the pending Base setup if you no longer need it.', 'core-blueprint' ) . '</p>';
			self::render_cancel_form();
			return;
		}

		echo '<p>' . esc_html__( 'If you already added the account to your authenticator app, confirm the setup below.', 'core-blueprint' ) . '</p>';
		echo '<p>' . esc_html__( 'If you did not save the setup key, cancel this setup and start again to display a new key after password confirmation.', 'core-blueprint' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden_action( self::CONFIRM_ACTION );
		self::render_password_field();
		echo '<p><label for="cb_two_factor_profile_code">' . esc_html__( 'Verification code', 'core-blueprint' ) . '</label><br>';
		echo '<input type="text" class="regular-text" name="cb_two_factor_code" id="cb_two_factor_profile_code" autocomplete="one-time-code" inputmode="numeric" required></p>';
		submit_button( __( 'Verify', 'core-blueprint' ), 'primary', 'submit', false );
		echo '</form>';

		self::render_cancel_form();
	}

	private static function render_start_form(): void {
		echo '<p>' . esc_html__( 'Add an authenticator app as a second factor for this privileged account.', 'core-blueprint' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden_action( self::START_ACTION );
		self::render_password_field();
		submit_button( __( 'Start two-factor setup', 'core-blueprint' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function render_cancel_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden_action( self::CANCEL_ACTION );
		self::render_password_field();
		submit_button( __( 'Cancel two-factor setup', 'core-blueprint' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function render_password_field(): void {
		echo '<p><label>' . esc_html__( 'Current password', 'core-blueprint' ) . '<br>';
		echo '<input type="password" class="regular-text" name="cb_two_factor_password" autocomplete="current-password" required></label></p>';
	}

	private static function render_setup_secret( string $secret ): never {
		nocache_headers();

		ob_start();
		echo '<p>' . esc_html__( 'Add this account to your authenticator app, then enter the six-digit code to finish enrollment.', 'core-blueprint' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Setup key', 'core-blueprint' ) . '</strong><br><code>' . esc_html( $secret ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden_action( self::CONFIRM_ACTION );
		self::render_password_field();
		echo '<p><label>' . esc_html__( 'Verification code', 'core-blueprint' ) . '<br>';
		echo '<input type="text" class="regular-text" name="cb_two_factor_code" autocomplete="one-time-code" inputmode="numeric" required></label></p>';
		submit_button( __( 'Verify', 'core-blueprint' ), 'primary', 'submit', false );
		echo '</form>';
		self::render_cancel_form();
		$html = (string) ob_get_clean();

		wp_die(
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built exclusively from escaped values and nonce helpers.
			esc_html__( 'Two-factor authentication', 'core-blueprint' ),
			[ 'response' => 200 ]
		);
	}

	/** @param string[] $codes */
	private static function render_recovery_codes( array $codes ): never {
		nocache_headers();

		$html = '<p>' . esc_html__( 'Two-factor authentication is active. Save these recovery codes now. Each code can be used once.', 'core-blueprint' ) . '</p><ul>';
		foreach ( $codes as $code ) {
			$html .= '<li><code>' . esc_html( $code ) . '</code></li>';
		}
		$html .= '</ul><p><a href="' . esc_url( self::profile_url() ) . '">' . esc_html__( 'Return to your profile', 'core-blueprint' ) . '</a></p>';

		wp_die(
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constructed exclusively from escaped static text and generated recovery codes.
			esc_html__( 'Save your recovery codes', 'core-blueprint' ),
			[ 'response' => 200 ]
		);
	}

	private static function hidden_action( string $action ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	private static function require_action_user( string $action ): WP_User {
		check_admin_referer( $action );

		$user = wp_get_current_user();
		if (
			! ( $user instanceof WP_User )
			|| $user->ID <= 0
			|| ! Policy::is_in_scope( $user )
		) {
			wp_die(
				esc_html__( 'Base two-factor self-service is available only for your own privileged account.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
		return $user;
	}

	private static function posted_password(): string {
		return isset( $_POST['cb_two_factor_password'] ) && is_scalar( $_POST['cb_two_factor_password'] )
			? (string) wp_unslash( (string) $_POST['cb_two_factor_password'] )
			: '';
	}

	private static function posted_code(): string {
		return isset( $_POST['cb_two_factor_code'] ) && is_scalar( $_POST['cb_two_factor_code'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cb_two_factor_code'] ) )
			: '';
	}

	private static function set_notice( int $user_id, string $type, string $message ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient(
			self::NOTICE_PREFIX . $user_id,
			[
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => substr( sanitize_text_field( $message ), 0, 1000 ),
			],
			MINUTE_IN_SECONDS
		);
	}

	/** @return array{type:string,message:string}|null */
	private static function take_notice( int $user_id ): ?array {
		$key = self::NOTICE_PREFIX . $user_id;
		$notice = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $notice ) || ! is_string( $notice['message'] ?? null ) ) {
			return null;
		}
		return [
			'type'    => 'error' === (string) ( $notice['type'] ?? '' ) ? 'error' : 'success',
			'message' => (string) $notice['message'],
		];
	}

	private static function redirect_profile(): never {
		wp_safe_redirect( self::profile_url() );
		exit;
	}

	private static function profile_url(): string {
		return admin_url( 'profile.php#cb-core-two-factor' );
	}
}
