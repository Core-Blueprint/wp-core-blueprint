<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use CB\Core\Admin\ProfileActionForms;
use CB\Core\Admin\SecureActionScreen;
use CB\Core\Admin\UserProfileSectionRegistry;
use CB\Core\UI\Assets as UiAssets;
use CB\Core\UI\Field;
use CB\Core\UI\Notice;
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
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_profile_assets' ] );
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

	public static function enqueue_profile_assets( string $hook_suffix ): void {
		if ( 'profile.php' !== $hook_suffix ) {
			return;
		}

		$user = wp_get_current_user();
		if (
			! ( $user instanceof WP_User )
			|| $user->ID <= 0
			|| ! Policy::is_in_scope( $user )
			|| ! EnrollmentStore::has_pending( (int) $user->ID )
		) {
			return;
		}

		UiAssets::enqueue_modals( UiAssets::MODAL_PRESENTATION_WP_NATIVE );
		self::enqueue_cancel_script();
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
			self::render_setup_secret( $user, $secret );
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
			AccountManager::cancel_enrollment( $user );
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
		ProfileActionForms::register( self::FORM_CANCEL, self::CANCEL_ACTION );
		echo '<div class="cb-core-stack cb-core-stack--form">';
		self::render_profile_code_field(
			self::FORM_CONFIRM,
			'cb-core-two-factor-profile-code',
			__( 'Verification code', 'core-blueprint' ),
			true
		);
		self::render_profile_password_field( self::FORM_CONFIRM, 'cb-core-two-factor-confirm-password' );
		echo '<div class="cb-core-form-actions">';
		echo '<button type="submit" class="button button-primary" form="' . esc_attr( self::FORM_CONFIRM ) . '">' . esc_html__( 'Enable two-factor authentication', 'core-blueprint' ) . '</button>';
		echo self::cancel_button_html( self::FORM_CANCEL, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every attribute and label.
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
		echo '<div class="cb-core-form-actions">';
		echo self::cancel_button_html( self::FORM_CANCEL, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every attribute and label.
		echo '</div>';
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

	private static function render_setup_secret( WP_User $user, string $secret ): never {
		self::enqueue_cancel_script();
		self::enqueue_enrollment_script();
		$provisioning_uri = Provisioning::uri( $user, $secret );

		ob_start();
		echo '<div data-cb-two-factor-enrollment>';
		echo '<p class="cb-core-secure-action__intro">' . esc_html__( 'Complete these steps to protect this account with an authenticator app.', 'core-blueprint' ) . '</p>';
		echo '<div class="cb-core-secure-action__steps">';

		echo '<section class="cb-core-secure-action__step">';
		echo '<span class="cb-core-secure-action__step-number" aria-hidden="true">1</span>';
		echo '<div class="cb-core-secure-action__step-content">';
		echo '<h2 class="cb-core-secure-action__step-title">' . esc_html__( 'Add Core Blueprint to your authenticator app', 'core-blueprint' ) . '</h2>';
		echo '<p class="cb-core-secure-action__step-copy">' . esc_html__( 'Scan this QR code with your authenticator app.', 'core-blueprint' ) . '</p>';
		echo '<div class="cb-core-secure-action__qr" data-cb-two-factor-qr data-cb-two-factor-provisioning-uri="' . esc_attr( $provisioning_uri ) . '" data-cb-two-factor-qr-label="' . esc_attr__( 'Authenticator setup QR code', 'core-blueprint' ) . '" hidden></div>';
		echo '<div class="cb-core-secure-action__manual">';
		echo '<p class="cb-core-secure-action__manual-copy"><strong>' . esc_html__( "Can't scan the QR code?", 'core-blueprint' ) . '</strong><br>' . esc_html__( 'Enter this setup key manually.', 'core-blueprint' ) . '</p>';
		echo '<div class="cb-core-secure-action__key-row">';
		echo '<div class="cb-core-secure-action__key" role="group" aria-label="' . esc_attr__( 'Setup key', 'core-blueprint' ) . '"><code>' . esc_html( $secret ) . '</code></div>';
		echo '<button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-two-factor-copy-secret="' . esc_attr( $secret ) . '" aria-label="' . esc_attr__( 'Copy setup key', 'core-blueprint' ) . '">' . esc_html__( 'Copy setup key', 'core-blueprint' ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '</div></section>';

		echo '<form class="cb-core-secure-action__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden_action( self::CONFIRM_ACTION );

		echo '<section class="cb-core-secure-action__step">';
		echo '<span class="cb-core-secure-action__step-number" aria-hidden="true">2</span>';
		echo '<div class="cb-core-secure-action__step-content">';
		echo '<h2 class="cb-core-secure-action__step-title">' . esc_html__( 'Enter the six-digit verification code', 'core-blueprint' ) . '</h2>';
		echo '<p class="cb-core-secure-action__step-copy">' . esc_html__( 'Enter the current code shown for this account in your authenticator app.', 'core-blueprint' ) . '</p>';
		echo Field::render( [
			'label'     => __( 'Verification code', 'core-blueprint' ),
			'label_for' => 'cb-core-two-factor-setup-code',
			'control'   => '<input type="text" id="cb-core-two-factor-setup-code" class="regular-text" name="cb_two_factor_code" autocomplete="one-time-code" inputmode="numeric" required>',
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Field owns label escaping; control is static markup.
		echo '</div></section>';

		echo '<section class="cb-core-secure-action__step">';
		echo '<span class="cb-core-secure-action__step-number" aria-hidden="true">3</span>';
		echo '<div class="cb-core-secure-action__step-content">';
		echo '<h2 class="cb-core-secure-action__step-title">' . esc_html__( 'Confirm with your current WordPress password', 'core-blueprint' ) . '</h2>';
		echo '<p class="cb-core-secure-action__step-copy">' . esc_html__( 'Enter your current password to confirm this security change.', 'core-blueprint' ) . '</p>';
		echo Field::render( [
			'label'     => __( 'Current password', 'core-blueprint' ),
			'label_for' => 'cb-core-two-factor-setup-password',
			'control'   => '<input type="password" id="cb-core-two-factor-setup-password" class="regular-text" name="cb_two_factor_password" autocomplete="current-password" required>',
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Field owns label escaping; control is static markup.
		echo '<div class="cb-core-form-actions">';
		echo '<button type="submit" class="button button-primary cb-core-button cb-core-button--primary">' . esc_html__( 'Enable two-factor authentication', 'core-blueprint' ) . '</button>';
		echo self::cancel_button_html( self::FORM_CANCEL, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every attribute and label.
		echo '</div>';
		echo '</div></section>';
		echo '</form>';
		echo '</div>';
		echo '</div>';

		self::render_hidden_cancel_form();
		$body = (string) ob_get_clean();

		SecureActionScreen::render( [
			'title'          => __( 'Set up two-factor authentication', 'core-blueprint' ),
			'status_variant' => 'ready',
			'status_label'   => __( 'Not active yet', 'core-blueprint' ),
			'body'           => $body,
		] );
	}

	/** @param string[] $codes */
	private static function render_recovery_codes( array $codes ): never {
		ob_start();
		echo '<p class="cb-core-secure-action__intro">' . esc_html__( 'Two-factor authentication is now enabled for this account.', 'core-blueprint' ) . '</p>';
		echo Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice owns escaping.
			'variant' => Notice::WARNING,
			'title'   => __( 'Save your recovery codes now', 'core-blueprint' ),
			'message' => __( 'Use a recovery code if you lose access to your authenticator app. Each code can be used once, and these codes will not be shown again.', 'core-blueprint' ),
		] );

		echo '<ul class="cb-core-secure-action__recovery-list" aria-label="' . esc_attr__( 'Recovery codes', 'core-blueprint' ) . '">';
		foreach ( $codes as $code ) {
			echo '<li><code>' . esc_html( $code ) . '</code></li>';
		}
		echo '</ul>';
		echo '<div class="cb-core-secure-action__recovery-actions">';
		echo '<a class="button button-primary cb-core-button cb-core-button--primary" href="' . esc_url( self::profile_url() ) . '">' . esc_html__( 'I have saved my recovery codes', 'core-blueprint' ) . '</a>';
		echo '</div>';
		$body = (string) ob_get_clean();

		SecureActionScreen::render( [
			'title'          => __( 'Two-factor authentication is active', 'core-blueprint' ),
			'status_variant' => 'active',
			'status_label'   => __( 'Active', 'core-blueprint' ),
			'body'           => $body,
		] );
	}

	private static function render_hidden_cancel_form(): void {
		echo '<form id="' . esc_attr( self::FORM_CANCEL ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden aria-hidden="true">';
		self::hidden_action( self::CANCEL_ACTION );
		echo '</form>';
	}

	private static function cancel_button_html( string $form_id, bool $core_presentation ): string {
		$classes = $core_presentation
			? 'button cb-core-button cb-core-button--secondary'
			: 'button button-secondary';

		return '<button type="button" class="' . esc_attr( $classes ) . '"'
			. ' data-cb-two-factor-cancel'
			. ' data-cb-two-factor-cancel-form="' . esc_attr( $form_id ) . '"'
			. ' data-cb-two-factor-cancel-title="' . esc_attr__( 'Cancel two-factor setup?', 'core-blueprint' ) . '"'
			. ' data-cb-two-factor-cancel-body="' . esc_attr__( 'This will discard the unfinished setup and its setup key. Two-factor authentication will not be enabled.', 'core-blueprint' ) . '"'
			. ' data-cb-two-factor-cancel-confirm="' . esc_attr__( 'Cancel setup', 'core-blueprint' ) . '"'
			. ' data-cb-two-factor-cancel-dismiss="' . esc_attr__( 'Keep setting up', 'core-blueprint' ) . '">'
			. esc_html__( 'Cancel setup', 'core-blueprint' )
			. '</button>';
	}

	private static function enqueue_cancel_script(): void {
		wp_enqueue_script(
			'cb-core-two-factor-profile',
			CB_CORE_URL . 'assets/js/features/two-factor-profile.js',
			[],
			CB_CORE_VERSION,
			true
		);
	}

	private static function enqueue_enrollment_script(): void {
		wp_enqueue_script_module(
			'@cb-core/two-factor-enrollment',
			CB_CORE_URL . 'assets/js/features/two-factor-enrollment.js',
			[],
			self::enrollment_asset_version()
		);
	}

	/**
	 * Bust stale browser/CDN copies while release candidates intentionally keep
	 * the same public plugin version during iterative runtime validation.
	 */
	private static function enrollment_asset_version(): string {
		$path = CB_CORE_DIR . 'assets/js/features/two-factor-enrollment.js';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return CB_CORE_VERSION;
		}

		$hash = hash_file( 'sha256', $path );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return CB_CORE_VERSION;
		}

		return CB_CORE_VERSION . '-' . substr( $hash, 0, 12 );
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
		return admin_url( 'profile.php#cb-core-user-profile-' . self::SECTION_ID );
	}
}
