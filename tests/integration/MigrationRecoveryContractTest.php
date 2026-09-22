<?php
declare(strict_types=1);

use CB\Core\Migration\Recovery;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\TrustSchemaMigrator;

final class CB_Base_Migration_Recovery_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
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
	}

	public function tear_down(): void {
		delete_option( 'cb_core_migration_recovery_state' );
		wp_set_current_user( 0 );
		$_GET = [];
		$_POST = [];
		$_REQUEST = [];
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	public function test_cross_site_recovery_creates_new_trust_domain_and_reapproves_only_authenticated_identity(): void {
		$actor_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$actor = get_userdata( $actor_id );
		self::assertInstanceOf( WP_User::class, $actor );
		self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'destination_preflight' ) );
		wp_set_current_user( $actor_id );

		$ticket_data = Recovery::issue_ticket( 'migration-contract-1', 'https://destination.test' );
		$ticket = (string) $ticket_data['ticket'];
		self::assertNotSame( '', $ticket );
		self::assertStringContainsString( '/wp-login.php?', Recovery::login_url( $ticket, admin_url() ) );

		$imported_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$imported = get_userdata( $imported_id );
		self::assertInstanceOf( WP_User::class, $imported );
		self::assertTrue(
			PrivilegedAccessRegistry::approve( $imported, 0, 'simulated_source_approval' ),
			'Fixture must start with a cryptographically valid approval to prove migration invalidates trust even when salts match.'
		);

		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = get_userdata( $other_id );
		self::assertInstanceOf( WP_User::class, $other );
		self::assertTrue( PrivilegedAccessRegistry::approve( $other, 0, 'simulated_source_approval' ) );

		// Simulate older imported Base policy state against the preserved current runtime.
		delete_option( 'cb_core_role_policy_schema_version' );
		$administrator = get_role( 'administrator' );
		self::assertNotNull( $administrator );
		PrivilegedAccessGuard::trusted_mutation(
			static function () use ( $administrator ): void {
				$administrator->remove_cap( 'cb_core_hud_use' );
			}
		);
		update_option( 'cb_core_trust_schema_version', 0, false );
		delete_option( 'cb_core_privileged_guard_bootstrapped' );

		$state = Recovery::reconcile_destination( $ticket );
		self::assertSame( 'pending_auth', $state['status'] );
		self::assertTrue( RolePolicySchema::inspect( false, 'test' )['canonical'] );
		self::assertSame( TrustSchemaMigrator::current_schema(), TrustSchemaMigrator::stored_schema() );
		self::assertNotFalse( get_option( 'cb_core_privileged_guard_bootstrapped', false ) );

		foreach ( [ $actor_id, $imported_id, $other_id ] as $id ) {
			$user = get_userdata( $id );
			self::assertInstanceOf( WP_User::class, $user );
			self::assertFalse( PrivilegedAccessRegistry::is_approved( $user ), 'Imported privileged approval crossed the migration trust boundary.' );
			$review = PrivilegedAccessRegistry::review_state( $user );
			self::assertSame( 'site_migration', $review['reason'] ?? '' );
			self::assertSame( 'migration_recovery', $review['source'] ?? '' );
		}

		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_URI'] = '/shop/';
		$_REQUEST = [];
		self::assertFalse( Recovery::filter_failsafe_bypass( false ), 'Migration recovery became a global frontend bypass.' );

		$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		$_REQUEST = [ Recovery::PARAM => $ticket ];
		self::assertTrue( Recovery::filter_failsafe_bypass( false ), 'Canonical destination login was not opened for the active signed ticket.' );

		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$subscriber = get_userdata( $subscriber_id );
		self::assertInstanceOf( WP_User::class, $subscriber );
		self::assertInstanceOf( WP_Error::class, Recovery::require_management_identity( $subscriber, 'ignored' ) );
		self::assertSame( $imported, Recovery::require_management_identity( $imported, 'ignored' ) );

		Recovery::complete_authenticated_login( (string) $imported->user_login, $imported );
		$imported = get_userdata( $imported_id );
		self::assertInstanceOf( WP_User::class, $imported );
		self::assertTrue( PrivilegedAccessRegistry::is_approved( $imported ) );
		self::assertSame( [], PrivilegedAccessRegistry::review_state( $imported ) );

		$other = get_userdata( $other_id );
		self::assertInstanceOf( WP_User::class, $other );
		self::assertFalse( PrivilegedAccessRegistry::is_approved( $other ) );
		self::assertSame( 'site_migration', PrivilegedAccessRegistry::review_state( $other )['reason'] ?? '' );

		$status = Recovery::status( $ticket );
		self::assertSame( 'authenticated', $status['status'] ?? '' );
		self::assertSame( $imported_id, (int) ( $status['approved_user_id'] ?? 0 ) );

		wp_set_current_user( $imported_id );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin.php';
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
		self::assertTrue( Recovery::filter_failsafe_bypass( false ), 'Recovered identity cannot reach destination admin during final verification.' );

		self::assertTrue( Recovery::finalize( $ticket ) );
		self::assertSame( [], Recovery::status( $ticket ) );
		$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
		$_REQUEST = [ Recovery::PARAM => $ticket ];
		self::assertFalse( Recovery::filter_failsafe_bypass( false ), 'Consumed migration recovery ticket remained an active bypass.' );
	}

	public function test_ticket_is_destination_bound_and_tamper_evident(): void {
		$actor_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$actor = get_userdata( $actor_id );
		self::assertInstanceOf( WP_User::class, $actor );
		self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'destination_preflight' ) );
		wp_set_current_user( $actor_id );

		$ticket = (string) Recovery::issue_ticket( 'migration-contract-2', 'https://destination.test' )['ticket'];
		$tampered = substr( $ticket, 0, -1 ) . ( str_ends_with( $ticket, 'a' ) ? 'b' : 'a' );

		$this->expectException( RuntimeException::class );
		Recovery::reconcile_destination( $tampered );
	}

	public function test_newer_imported_role_policy_fails_closed(): void {
		$actor_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$actor = get_userdata( $actor_id );
		self::assertInstanceOf( WP_User::class, $actor );
		self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'destination_preflight' ) );
		wp_set_current_user( $actor_id );

		$ticket = (string) Recovery::issue_ticket( 'migration-contract-3', 'https://destination.test' )['ticket'];
		update_option( 'cb_core_role_policy_schema_version', RolePolicySchema::current_schema() + 1, false );

		$this->expectException( RuntimeException::class );
		Recovery::reconcile_destination( $ticket );
	}
}
