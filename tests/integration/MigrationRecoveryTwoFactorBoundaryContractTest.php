<?php
declare(strict_types=1);

use CB\Core\Migration\Recovery;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\TrustSchemaMigrator;
use CB\Core\Security\Failsafe;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\LoginController;
use CB\Core\Security\TwoFactor\LoginFlow;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\Totp;
use CB\Core\Settings;

final class MigrationRecoveryTwoFactorBoundaryContractTest extends WP_UnitTestCase {

	/** @var array{mode:string,scope:string} */
	private array $original_policy;

	public function set_up(): void {
		parent::set_up();

		$settings = Settings::get();
		$this->original_policy = is_array( $settings[ Policy::SETTINGS_KEY ] ?? null )
			? $settings[ Policy::SETTINGS_KEY ]
			: Policy::default_config();

		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'migration-recovery-two-factor-test' );
		LoginFlow::reset_request_state();
		Recovery::boot();

		$_GET = [];
		$_POST = [];
		$_REQUEST = [];
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_URI'] = '/';

		update_option( 'siteurl', 'https://destination.test', false );
		update_option( 'home', 'https://destination.test', false );
		RolePolicySchema::repair();
		TrustSchemaMigrator::mark_current();
		update_option( 'cb_core_privileged_guard_bootstrapped', time(), false );
		delete_option( 'cb_core_migration_recovery_state' );
		delete_option( CB_CORE_BYPASS_OPT );
		delete_transient( Failsafe::BYPASS_TRANSIENT );
	}

	public function tear_down(): void {
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'migration-recovery-two-factor-test-restore' );
		LoginFlow::reset_request_state();
		delete_option( 'cb_core_migration_recovery_state' );
		delete_option( CB_CORE_BYPASS_OPT );
		delete_transient( Failsafe::BYPASS_TRANSIENT );
		wp_set_current_user( 0 );

		$_GET = [];
		$_POST = [];
		$_REQUEST = [];
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_URI'] = '/';

		parent::tear_down();
	}

	public function test_migration_recovery_authority_does_not_count_as_operator_two_factor_bypass(): void {
		[ $actor, $ticket ] = $this->prepare_recovery( 'migration-2fa-authority', Policy::MODE_ENFORCE );

		self::assertTrue( Recovery::filter_failsafe_bypass( false ) );
		self::assertTrue( Failsafe::is_bypassed() );
		self::assertFalse( Failsafe::is_operator_bypass_active() );
		self::assertSame( LoginFlow::DECISION_ENROLL, LoginFlow::decision_for( $actor ) );

		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );
		self::assertTrue( Failsafe::is_operator_bypass_active() );
		self::assertSame( LoginFlow::DECISION_NONE, LoginFlow::decision_for( $actor ) );

		self::assertNotSame( '', $ticket );
	}

	public function test_enforced_recovery_requires_real_destination_enrollment_before_approval(): void {
		[ $actor, $ticket ] = $this->prepare_recovery( 'migration-2fa-enforce', Policy::MODE_ENFORCE );
		$actor_id = (int) $actor->ID;

		self::assertSame( LoginFlow::DECISION_ENROLL, LoginFlow::decision_for( $actor ) );
		self::assertSame( $actor, LoginFlow::filter_authenticate( $actor ) );
		self::assertTrue( LoginFlow::is_password_stage_pending( $actor_id ) );

		Recovery::complete_authenticated_login( (string) $actor->user_login, $actor );

		$status = Recovery::status( $ticket );
		self::assertSame( 'pending_two_factor', $status['status'] ?? '' );
		self::assertSame( $actor_id, (int) ( $status['pending_user_id'] ?? 0 ) );
		self::assertFalse( PrivilegedAccessRegistry::is_approved( $actor ) );
		self::assertFalse( Recovery::finalize( $ticket ), 'Password authentication alone finalized enforced migration recovery.' );

		$challenge = LoginFlow::finalize_password_stage( $actor, false, admin_url() );
		self::assertSame( 'enroll', $challenge['flow'] ?? '' );

		// The 2FA challenge is a new wp-login.php request and intentionally does
		// not carry the migration ticket. Recovery completion is bound to the
		// persisted pending identity plus the canonical 2FA completion event.
		$_REQUEST = [ LoginFlow::PARAM => (string) $challenge['token'] ];
		self::assertFalse( Recovery::filter_failsafe_bypass( false ) );

		$prepared = LoginController::prepare( (string) $challenge['token'] );
		self::assertSame( 'ready', $prepared['status'] ?? '' );
		$secret = (string) ( $prepared['secret'] ?? '' );
		self::assertNotSame( '', $secret );

		$result = LoginController::process(
			(string) $challenge['token'],
			Totp::code( $secret, time() )
		);
		self::assertSame( 'success', $result['status'] ?? '' );
		self::assertSame( 'totp', $result['method'] ?? '' );
		self::assertTrue( CredentialStore::is_enrolled( $actor_id ) );
		self::assertSame( 'pending_two_factor', Recovery::status( $ticket )['status'] ?? '' );
		self::assertFalse( PrivilegedAccessRegistry::is_approved( $actor ) );

		$other = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $other );
		do_action( 'cb_core_two_factor_authenticated', $other, 'totp' );
		self::assertSame( 'pending_two_factor', Recovery::status( $ticket )['status'] ?? '' );

		do_action( 'cb_core_two_factor_authenticated', $actor, 'totp' );

		$status = Recovery::status( $ticket );
		self::assertSame( 'authenticated', $status['status'] ?? '' );
		self::assertSame( $actor_id, (int) ( $status['approved_user_id'] ?? 0 ) );
		self::assertArrayNotHasKey( 'pending_user_id', $status );
		self::assertTrue( PrivilegedAccessRegistry::is_approved( get_userdata( $actor_id ) ) );

		wp_set_current_user( $actor_id );
		self::assertTrue( Recovery::finalize( $ticket ) );
		self::assertSame( [], Recovery::status( $ticket ) );
	}

	public function test_optional_recovery_does_not_force_new_base_two_factor_enrollment(): void {
		[ $actor, $ticket ] = $this->prepare_recovery( 'migration-2fa-optional', Policy::MODE_OPTIONAL );

		self::assertSame( LoginFlow::DECISION_NONE, LoginFlow::decision_for( $actor ) );
		self::assertSame( $actor, LoginFlow::filter_authenticate( $actor ) );
		self::assertFalse( LoginFlow::is_password_stage_pending( (int) $actor->ID ) );

		Recovery::complete_authenticated_login( (string) $actor->user_login, $actor );

		$status = Recovery::status( $ticket );
		self::assertSame( 'authenticated', $status['status'] ?? '' );
		self::assertTrue( PrivilegedAccessRegistry::is_approved( get_userdata( (int) $actor->ID ) ) );
		self::assertFalse( CredentialStore::is_enrolled( (int) $actor->ID ) );

		wp_set_current_user( (int) $actor->ID );
		self::assertTrue( Recovery::finalize( $ticket ) );
	}

	public function test_explicit_emergency_bypass_still_allows_enforced_recovery_without_base_two_factor(): void {
		[ $actor, $ticket ] = $this->prepare_recovery( 'migration-2fa-emergency', Policy::MODE_ENFORCE );

		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );

		self::assertTrue( Failsafe::is_operator_bypass_active() );
		self::assertSame( LoginFlow::DECISION_NONE, LoginFlow::decision_for( $actor ) );
		self::assertSame( $actor, LoginFlow::filter_authenticate( $actor ) );
		self::assertFalse( LoginFlow::is_password_stage_pending( (int) $actor->ID ) );

		Recovery::complete_authenticated_login( (string) $actor->user_login, $actor );

		self::assertSame( 'authenticated', Recovery::status( $ticket )['status'] ?? '' );
		self::assertTrue( PrivilegedAccessRegistry::is_approved( get_userdata( (int) $actor->ID ) );

		wp_set_current_user( (int) $actor->ID );
		self::assertTrue( Recovery::finalize( $ticket ) );
	}

	/**
	 * @return array{0:WP_User,1:string}
	 */
	private function prepare_recovery( string $recovery_id, string $mode ): array {
		$actor = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $actor );
		self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'destination_preflight' ) );
		wp_set_current_user( (int) $actor->ID );

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ 'mode' => $mode, 'scope' => Policy::SCOPE_PRIVILEGED ],
			'migration-recovery-two-factor-test'
		);

		$ticket = (string) Recovery::issue_ticket( $recovery_id, 'https://destination.test' )['ticket'];

		// Simulate Base-owned 2FA material imported with the source database.
		CredentialStore::store_totp_secret( (int) $actor->ID, 'JBSWY3DPEHPK3PXP' );
		self::assertTrue( CredentialStore::is_enrolled( (int) $actor->ID ) );

		Recovery::activate_destination( $ticket );
		$state = Recovery::reconcile_destination( $ticket );

		self::assertSame( 'pending_auth', $state['status'] ?? '' );
		self::assertFalse( CredentialStore::is_enrolled( (int) $actor->ID ) );

		$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		$_REQUEST = [ Recovery::PARAM => $ticket ];

		return [ $actor, $ticket ];
	}
}
