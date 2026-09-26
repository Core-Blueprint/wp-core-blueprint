<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use CB\Core\Admin\ProfileActionForms;
use CB\Core\Admin\UserProfileSectionRegistry;
use CB\Core\UI\Field;
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

	private const SECTION_ID = 'core-blueprint-two-factor';
	private const FORM_START = 'cb-core-two-factor-start-form';
	private const FORM_CONFIRM = 'cb-core-two-factor-confirm-form';
	private const FORM_CANCEL = 'cb-core-two-factor-cancel-form';
	private const FORM_REMOVE = 'cb-core-two-factor-remove-form';
	private const FORM_REGENERATE = 'cb-core-two-factor-regenerate-form';

	private const NOTICE_PREFIX = 'cb_core_two_factor_profile_notice_';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'cb_core_register_user_profile_sections', [ self::class, 'register_profile_section' ] );
		add_action( 'admin_post_' . self::START_ACTION, [ self::class, 'start' ] );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, [ self::class, 'confirm' ] );
		add_action( 'admin_post_' . self::CANCEL_ACTION, [ self::class, 'cancel' ] );
		add_action( 'admin_post_' . self::REMOVE_ACTION, [ self::class, 'remove' ] );
		add_action( 'admin_post_' . self::REGENERATE_ACTION, [ self::class, 'regenerate_recovery_codes' ] );
	}

	public static function register_profile_section(): void {
		UserProfileSectionRegistry::register(
			self::SECTION_ID,
			[
				'title'    => __( 'Two-factor authentication', 'core-blueprint' ),
				'order'    => 200,
				'contexts' => [ UserProfileSectionRegistry::CONTEXT_SELF ],
				'visible'  => [ self::class, 'section_visible' ],
				'renderer' => [ self::class, 'render' ],
			]
		);
	}

	public static function section_visible( WP_User $profile_user, string $context ): bool {
		return UserProfileSectionRegistry::CONTEXT_SELF === $context
			&& $profile_user->ID > 0
			&& get_current_user_id() === (int) $profile_user->ID
			&& Policy::is_in_scope( $profile_user );
	}

	public static function render( WP_User $profile_user, string $context = UserProfileSectionRegistry::CONTEXT_SELF ): void {
		if ( ! self::section_visible( $profile_user, $context ) ) {
			return;
		}

		$user_id   = (int) $profile_user->ID;
		$providers = ProviderDetector::providers_for_user( $profile_user );
		$enrolled  = CredentialStore::is_enrolled( $user_id );
		$pending   = ! $enrolled && EnrollmentStore::has_pending( $user_id );
		$notice    = self::take_notice( $user_id );


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
			ProfileActionForms::register( self::FORM_REGENERATE, self::REGENERATE_ACTION );
			echo '<div class="cb-core-stack cb-core-stack--form">';
			self::render_profile_password_field( self::FORM_REGENERATE, 'cb-core-two-factor-regenerate-password' );
			self::render_profile_code_field(
				self::FORM_REGENERATE,
				'cb-core-two-factor-regenerate-code',
				__( 'Authenticator or recovery code', 'core-blueprint' ),
				false
			);
			self::render_profile_submit_button(
				self::FORM_REGENERATE,
				__( 'Generate new recovery codes', 'core-blueprint' ),
				'secondary'
			);
			echo '</div>';
		}

		if ( Policy::requires_enrollment( $user ) && [] === $providers ) {
			echo '<p>' . esc_html__( 'Site policy requires two-factor authentication for this account, so Base two-factor authentication cannot be removed here.', 'core-blueprint' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'To remove Base two-factor authentication, confirm your current password and a current authenticator or recovery code.', 'core-blueprint' ) . '</p>';
		ProfileActionForms::register( self::FORM_REMOVE, self::REMOVE_ACTION );
		echo '<div class="cb-core-stack cb-core-stack--form">';
		self::render_profile_password_field( self::FORM_REMOVE, 'cb-core-two-factor-remove-password' );
		self::render_profile_code_field(
			self::FORM_REMOVE,
			'cb-core-two-factor-remove-code',
			__( 'Authenticator or recovery code', 'core-blueprint' ),
			false
		);
		self::render_profile_submit_button(
			self::FORM_REMOVE,
			__( 'Remove Base two-factor authentication', 'core-blueprint' ),
			'delete'
		);
		echo '</div>';
	}

	private static function render_pending( bool $external_provider ): void {
		echo '<p><strong>' . esc_html__( 'Two-factor setup is waiting for confirmation.', 'core-blueprint' ) . '</strong></p>';
		if ( $external_provider ) {
			echo '<p>' . esc_html__( 'An external provider is now active. Cancel the pending Base setup if you no longer need it.', 'core-blueprint' ) . '</p>';
			echo '<div class="cb-core-stack cb-core-stack--form">';
			self::render_profile_cancel_controls();
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'If you already added the account to your authenticator app, confirm the setup below.', 'core-blueprint' ) . '</p>';
		echo '<p>' . esc_html__( 'If you did not save the setup key, cancel this setup and start again to display a new key after password confirmation.', 'core-blueprint' ) . '</p>';

		ProfileActionForms::register( self::FORM_CONFIRM, self::CONFIRM_ACTION );
		echo '<div class="cb-core-stack cb-core-stack--loose">';
		echo '<div class="cb-core-stack cb-core-stack--form">';
		self::render_profile_password_field( self::FORM_CONFIRM, 'cb-core-two-factor-confirm-password' );
		self::render_profile_code_field(
			self::FORM_CONFIRM,
			'cb-core-two-factor-profile-code',
			__( 'Verification code', 'core-blueprint' ),
			true
		);
		self::render_profile_submit_button( self::FORM_CONFIRM, __( 'Verify', 'core-blueprint' ), 'primary' );
		echo '</div>';

		echo '<div class="cb-core-stack cb-core-stack--form">';
		self::render_profile_cancel_controls();
		echo '</div>';
		echo '</div>';
	}

	private static function render_start_form(): void {
		echo '<p>' . esc_html__( 'Add an authenticator app as a second factor for this privileged account.', 'core-blueprint' ) . '</p>';
		ProfileActionForms::register( self::FORM_START, self::START_ACTION );
		echo '<div class="cb-core-stack cb-core-stack--form">';
		self::render_profile_password_field( self::FORM_START, 'cb-core-two-factor-start-password' );
		self::render_profile_submit_button( self::FORM_START, __( 'Start two-factor setup', 'core-blueprint' ), 'primary' );
		echo '</div>';
	}

	private static function render_profile_cancel_controls(): void {
		ProfileActionForms::register( self::FORM_CANCEL, self::CANCEL_ACTION );
		self::render_profile_password_field( self::FORM_CANCEL, 'cb-core-two-factor-cancel-password' );
		self::render_profile_submit_button( self::FORM_CANCEL, __( 'Cancel two-factor setup', 'core-blueprint' ), 'secondary' );
	}

	private static function render_profile_password_field( string $form_id, string $input_id ): void {
		$control = '<input type="password" id="' . esc_attr( $input_id ) . '" class="regular-text" name="cb_two_factor_password" form="' . esc_attr( $form_id ) . '" autocomplete="current-password" required>';
		echo Field::render( [
			'label'     => __( 'Current password', 'core-blueprint' ),
			'label_for' => $input_id,
			'control'   => $control,
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Field escapes labels; control is assembled from escaped static values.
	}

	private static function render_profile_code_field( string $form_id, string $input_id, string $label, bool $numeric ): void {
		$control = '<input type="text" id="' . esc_attr( $input_id ) . '" class="regular-text" name="cb_two_factor_code" form="' . esc_attr( $form_id ) . '" autocomplete="one-time-code"';
		$control .= $numeric ? ' inputmode="numeric"' : ' autocapitalize="characters" spellcheck="false"';
		$control .= ' required>';

		echo Field::render( [
			'label'     => $label,
			'label_for' => $input_id,
			'control'   => $control,
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Field escapes labels; control is assembled from escaped static values.
	}

	private static function render_profile_submit_button( string $form_id, string $label, string $variant ): void {
		$classes = 'button';
		if ( 'primary' === $variant ) {
			$classes .= ' button-primary';
		} elseif ( 'delete' === $variant ) {
			$classes .= ' button-secondary button-link-delete';
		}

		echo '<div class="cb-core-form-actions">';
		echo '<button type="submit" class="' . esc_attr( $classes ) . '" form="' . esc_attr( $form_id ) . '">' . esc_html( $label ) . '</button>';
		echo '</div>';
	}

	private static function render_standalone_cancel_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden_action( self::CANCEL_ACTION );
		self::render_standalone_password_field();
		submit_button( __( 'Cancel two-factor setup', 'core-blueprint' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function render_standalone_password_field(): void {
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
		self::render_standalone_password_field();
		echo '<p><label>' . esc_html__( 'Verification code', 'core-blueprint' ) . '<br>';
		echo '<input type="text" class="regular-text" name="cb_two_factor_code" autocomplete="one-time-code" inputmode="numeric" required></label></p>';
		submit_button( __( 'Verify', 'core-blueprint' ), 'primary', 'submit', false );
		echo '</form>';
		self::render_standalone_cancel_form();
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
