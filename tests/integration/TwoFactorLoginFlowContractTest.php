<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\Authenticator;
use CB\Core\Security\TwoFactor\ChallengeStore;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\EnrollmentStore;
use CB\Core\Security\TwoFactor\LoginController;
use CB\Core\Security\TwoFactor\LoginFlow;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\Totp;
use CB\Core\Settings;

final class CB_Base_Two_Factor_Login_Flow_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $original_policy = [];

	private string $original_pagenow = '';

	private mixed $original_bypass = false;

	private mixed $original_request_method = null;

	private mixed $original_script_name = null;

	private mixed $original_http_referer = null;

	public function set_up(): void {
		parent::set_up();
		$this->original_policy = is_array( Settings::get()[ Policy::SETTINGS_KEY ] ?? null )
			? Settings::get()[ Policy::SETTINGS_KEY ]
			: Policy::default_config();
		$this->original_pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		$this->original_bypass = get_option( CB_CORE_BYPASS_OPT, false );
		$this->original_request_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$this->original_script_name = $_SERVER['SCRIPT_NAME'] ?? null;
		$this->original_http_referer = $_SERVER['HTTP_REFERER'] ?? null;
		delete_option( CB_CORE_BYPASS_OPT );
		$GLOBALS['pagenow'] = 'wp-login.php';
		LoginFlow::reset_request_state();
		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-login-test' );
	}

	public function tear_down(): void {
		LoginFlow::reset_request_state();
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-login-test-restore' );
		$GLOBALS['pagenow'] = $this->original_pagenow;
		if ( false === $this->original_bypass ) {
			delete_option( CB_CORE_BYPASS_OPT );
		} else {
			update_option( CB_CORE_BYPASS_OPT, $this->original_bypass, false );
		}
		foreach ( [
			'REQUEST_METHOD' => $this->original_request_method,
			'SCRIPT_NAME'    => $this->original_script_name,
			'HTTP_REFERER'   => $this->original_http_referer,
		] as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	public function test_lf1_optional_only_challenges_already_enrolled_privileged_users(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );

		self::assertSame( LoginFlow::DECISION_NONE, LoginFlow::decision_for( $user ) );

		CredentialStore::store_totp_secret( (int) $user->ID, 'JBSWY3DPEHPK3PXP' );
		self::assertSame( LoginFlow::DECISION_VERIFY, LoginFlow::decision_for( $user ) );
	}

	public function test_lf2_enforce_routes_unenrolled_privileged_user_into_enrollment(): void {
		Settings::set_key( Policy::SETTINGS_KEY, [
			'mode'  => Policy::MODE_ENFORCE,
			'scope' => Policy::SCOPE_PRIVILEGED,
		], 'two-factor-login-test' );

		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertSame( LoginFlow::DECISION_ENROLL, LoginFlow::decision_for( $user ) );
	}

	public function test_lf3_password_stage_withholds_cookies_and_destroys_temporary_session(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );
		CredentialStore::store_totp_secret( (int) $user->ID, 'JBSWY3DPEHPK3PXP' );

		self::assertSame( $user, LoginFlow::filter_authenticate( $user ) );
		self::assertTrue( LoginFlow::is_password_stage_pending( (int) $user->ID ) );
		self::assertFalse( LoginFlow::filter_send_auth_cookies( true, 0, 0, (int) $user->ID ) );

		$manager = WP_Session_Tokens::get_instance( (int) $user->ID );
		$token = $manager->create( time() + HOUR_IN_SECONDS );
		self::assertTrue( $manager->verify( $token ) );

		LoginFlow::capture_auth_token( '', 0, 0, (int) $user->ID, 'auth', $token );
		$challenge = LoginFlow::finalize_password_stage( $user, true, admin_url( 'plugins.php' ) );

		self::assertSame( ChallengeStore::FLOW_VERIFY, $challenge['flow'] );
		self::assertFalse( $manager->verify( $token ), 'Password-stage WordPress session token survived 2FA interception.' );
		self::assertFalse( LoginFlow::is_password_stage_pending( (int) $user->ID ) );
		self::assertIsArray( ChallengeStore::inspect( $challenge['token'] ) );
	}

	public function test_lf4_challenge_inspection_is_non_consuming_but_submission_is_one_time(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );

		self::assertIsArray( ChallengeStore::inspect( $token ) );
		self::assertIsArray( ChallengeStore::inspect( $token ) );
		self::assertIsArray( ChallengeStore::take( $token ) );
		self::assertNull( ChallengeStore::inspect( $token ) );
		self::assertNull( ChallengeStore::take( $token ) );
	}

	public function test_lf5_valid_totp_consumes_challenge_and_returns_authenticated_result(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret = 'JBSWY3DPEHPK3PXP';
		CredentialStore::store_totp_secret( $user_id, $secret );

		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$code  = Totp::code( $secret, time() );
		$result = LoginController::process( $token, $code );

		self::assertSame( 'success', $result['status'] ?? null );
		self::assertSame( 'totp', $result['method'] ?? null );
		self::assertNull( ChallengeStore::inspect( $token ) );
		self::assertFalse( Authenticator::verify_totp( $user_id, $code ), 'Successful challenge TOTP remained reusable.' );
	}

	public function test_lf6_invalid_factor_rotates_challenge_without_extending_absolute_expiry(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );

		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$before = ChallengeStore::inspect( $token );
		self::assertIsArray( $before );

		$result = LoginController::process( $token, '000000' );
		self::assertSame( 'retry', $result['status'] ?? null );
		self::assertNotSame( $token, $result['token'] ?? null );
		self::assertNull( ChallengeStore::inspect( $token ) );

		$after = ChallengeStore::inspect( (string) $result['token'] );
		self::assertIsArray( $after );
		self::assertSame( $before['created_at'], $after['created_at'] );
		self::assertSame( $before['expires_at'], $after['expires_at'] );
		self::assertSame( 1, (int) $after['attempts'] );
	}

	public function test_lf7_enrollment_challenge_promotes_secret_and_returns_recovery_codes_once(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_ENROLL );

		$prepared = LoginController::prepare( $token );
		self::assertSame( 'ready', $prepared['status'] ?? null );
		$secret = (string) ( $prepared['secret'] ?? '' );
		self::assertMatchesRegularExpression( '/^[A-Z2-7]{32}$/', $secret );

		$result = LoginController::process( $token, Totp::code( $secret, time() ) );
		self::assertSame( 'success', $result['status'] ?? null );
		self::assertCount( 10, $result['recovery_codes'] ?? [] );
		self::assertTrue( CredentialStore::is_enrolled( $user_id ) );
		self::assertNull( EnrollmentStore::pending_secret( $user_id ) );
	}

	public function test_lf8_failsafe_bypasses_base_owned_factor_before_challenge(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );
		CredentialStore::store_totp_secret( (int) $user->ID, 'JBSWY3DPEHPK3PXP' );
		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );

		self::assertSame( LoginFlow::DECISION_NONE, LoginFlow::decision_for( $user ) );
		self::assertSame( $user, LoginFlow::filter_authenticate( $user ) );
		self::assertFalse( LoginFlow::is_password_stage_pending( (int) $user->ID ) );
	}

	public function test_lf9_existing_challenge_stands_down_when_failsafe_becomes_active(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );

		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );
		$result = LoginController::process( $token, '' );

		self::assertSame( 'success', $result['status'] ?? null );
		self::assertSame( 'failsafe', $result['method'] ?? null );
		self::assertNull( ChallengeStore::inspect( $token ) );
	}

	public function test_lf10_existing_challenge_get_stands_down_immediately_when_failsafe_becomes_active(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		$token = ChallengeStore::create(
			$user_id,
			true,
			admin_url( 'plugins.php' ),
			ChallengeStore::FLOW_VERIFY
		);

		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );
		$result = LoginController::prepare( $token );

		self::assertSame( 'success', $result['status'] ?? null );
		self::assertSame( 'failsafe', $result['method'] ?? null );
		self::assertSame( $user_id, (int) ( $result['user']->ID ?? 0 ) );
		self::assertTrue( ! empty( $result['state']['remember'] ) );
		self::assertSame( admin_url( 'plugins.php' ), $result['state']['redirect_to'] ?? null );
		self::assertNull(
			ChallengeStore::inspect( $token ),
			'Failsafe GET bypass left the old one-time challenge reusable.'
		);
	}

	public function test_lf11_application_password_authentication_stands_down_without_weakening_normal_noninteractive_auth(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );
		CredentialStore::store_totp_secret( (int) $user->ID, 'JBSWY3DPEHPK3PXP' );

		$GLOBALS['pagenow'] = 'xmlrpc.php';

		$blocked = LoginFlow::filter_authenticate( $user );
		self::assertInstanceOf( WP_Error::class, $blocked );
		self::assertSame( 'cb_core_two_factor_interactive_required', $blocked->get_error_code() );

		LoginFlow::mark_application_password_authentication( $user, [ 'uuid' => 'fixture-only' ] );
		self::assertSame( $user, LoginFlow::filter_authenticate( $user ) );
		self::assertFalse( LoginFlow::is_password_stage_pending( (int) $user->ID ) );

		LoginFlow::reset_request_state();
		$blocked_again = LoginFlow::filter_authenticate( $user );
		self::assertInstanceOf( WP_Error::class, $blocked_again );
		self::assertSame( 'cb_core_two_factor_interactive_required', $blocked_again->get_error_code() );
	}

	public function test_lf12_frontend_browser_post_is_interactive_and_uses_same_site_referrer_as_default_redirect(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );
		CredentialStore::store_totp_secret( (int) $user->ID, 'JBSWY3DPEHPK3PXP' );

		$GLOBALS['pagenow'] = 'index.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['HTTP_REFERER'] = home_url( '/my-account/' );

		self::assertSame( $user, LoginFlow::filter_authenticate( $user ) );
		self::assertTrue( LoginFlow::is_password_stage_pending( (int) $user->ID ) );
		self::assertSame( home_url( '/my-account/' ), LoginFlow::request_redirect_target() );
	}

	public function test_lf13_ajax_login_is_fail_closed_by_default_but_can_be_opted_in_by_a_dedicated_adapter(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );
		CredentialStore::store_totp_secret( (int) $user->ID, 'JBSWY3DPEHPK3PXP' );

		$GLOBALS['pagenow'] = 'admin-ajax.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';

		$blocked = LoginFlow::filter_authenticate( $user );
		self::assertInstanceOf( WP_Error::class, $blocked );
		self::assertSame( 'cb_core_two_factor_interactive_required', $blocked->get_error_code() );

		$adapter = static fn ( bool $interactive ): bool => true;
		add_filter( 'cb_core_two_factor_interactive_login_request', $adapter, 10, 1 );
		try {
			self::assertSame( $user, LoginFlow::filter_authenticate( $user ) );
			self::assertTrue( LoginFlow::is_password_stage_pending( (int) $user->ID ) );
		} finally {
			remove_filter( 'cb_core_two_factor_interactive_login_request', $adapter, 10 );
		}
	}

	public function test_lf14_stale_enrollment_challenge_is_revoked_when_policy_no_longer_requires_enrollment(): void {
		Settings::set_key( Policy::SETTINGS_KEY, [
			'mode'  => Policy::MODE_ENFORCE,
			'scope' => Policy::SCOPE_PRIVILEGED,
		], 'two-factor-login-test' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create(
			$user_id,
			false,
			admin_url(),
			ChallengeStore::FLOW_ENROLL
		);

		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-login-test' );
		$result = LoginController::prepare( $token );

		self::assertSame( 'restart', $result['status'] ?? null );
		self::assertNull( ChallengeStore::inspect( $token ) );
	}

	public function test_lf15_stale_verify_challenge_is_revoked_when_current_requirement_becomes_enrollment(): void {
		Settings::set_key( Policy::SETTINGS_KEY, [
			'mode'  => Policy::MODE_ENFORCE,
			'scope' => Policy::SCOPE_PRIVILEGED,
		], 'two-factor-login-test' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		$token = ChallengeStore::create(
			$user_id,
			false,
			admin_url(),
			ChallengeStore::FLOW_VERIFY
		);

		CredentialStore::clear( $user_id );
		$result = LoginController::prepare( $token );

		self::assertSame( 'restart', $result['status'] ?? null );
		self::assertNull( ChallengeStore::inspect( $token ) );
	}

	public function test_lf16_noninteractive_password_auth_fails_closed_for_base_owned_2fa(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );
		CredentialStore::store_totp_secret( (int) $user->ID, 'JBSWY3DPEHPK3PXP' );

		$GLOBALS['pagenow'] = 'xmlrpc.php';
		$result = LoginFlow::filter_authenticate( $user );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'cb_core_two_factor_interactive_required', $result->get_error_code() );
	}
}
