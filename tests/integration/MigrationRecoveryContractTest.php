<?php
declare(strict_types=1);

use CB\Core\Migration\Recovery;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\TrustSchemaMigrator;
use CB\Core\Security\TwoFactor\ChallengeStore;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\EnrollmentStore;

final class MigrationRecoveryContractTest extends WP_UnitTestCase {

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

	public function test_unrelated_request_keeps_recovery_failsafe_filter_zero_query(): void {
		global $wpdb;

		self::assertInstanceOf( wpdb::class, $wpdb );
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_URI'] = '/shop/';
		$_REQUEST = [];

		wp_cache_delete( 'cb_core_migration_recovery_state', 'options' );
		$before = (int) $wpdb->num_queries;
		self::assertFalse( Recovery::filter_failsafe_bypass( false ) );
		self::assertSame(
			$before,
			(int) $wpdb->num_queries,
			'Unrelated requests must not read migration recovery state through the global Failsafe capability path.'
		);
	}

	public function test_activation_ignores_pre_switch_cached_recovery_state_after_database_swap(): void {
		global $wpdb;

		$actor_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$actor = get_userdata( $actor_id );
		self::assertInstanceOf( WP_User::class, $actor );
		self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'destination_preflight' ) );
		wp_set_current_user( $actor_id );

		$ticket = (string) Recovery::issue_ticket( 'migration-cache-boundary', 'https://destination.test' )['ticket'];

		$stale = [
			'version'          => 1,
			'recovery_id'      => 'previous-migration',
			'ticket_hash'      => str_repeat( 'a', 64 ),
			'target_site_url'  => 'https://destination.test',
			'issued_at'        => time() - 60,
			'expires_at'       => time() + HOUR_IN_SECONDS,
			'status'           => 'pending_auth',
			'reviewed_users'   => 1,
			'approved_user_id' => 0,
			'authenticated_at' => 0,
		];
		update_option( 'cb_core_migration_recovery_state', $stale, false );
		self::assertSame( $stale, get_option( 'cb_core_migration_recovery_state' ) );

		// Simulate the atomic table swap: the new live database has no recovery
		// row, while this PHP request still carries the pre-switch cached value.
		$wpdb->delete(
			$wpdb->options,
			[ 'option_name' => 'cb_core_migration_recovery_state' ],
			[ '%s' ]
		);
		self::assertSame(
			$stale,
			get_option( 'cb_core_migration_recovery_state' ),
			'Fixture must retain the pre-switch cached state after the direct database replacement.'
		);

		$activated = Recovery::activate_destination( $ticket );
		self::assertSame( 'migration-cache-boundary', $activated['recovery_id'] ?? '' );
		self::assertSame( 'pending_reconcile', $activated['status'] ?? '' );

		wp_cache_delete( 'cb_core_migration_recovery_state', 'options' );
		$persisted = get_option( 'cb_core_migration_recovery_state', [] );
		self::assertIsArray( $persisted );
		self::assertSame( 'migration-cache-boundary', $persisted['recovery_id'] ?? '' );
		self::assertSame( 'pending_reconcile', $persisted['status'] ?? '' );
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

		CredentialStore::store_totp_secret( $imported_id, 'JBSWY3DPEHPK3PXP' );
		CredentialStore::store_recovery_hashes( $imported_id, [ wp_hash_password( 'ABCDE12345ABCDE12345' ) ] );
		CredentialStore::store_totp_secret( $other_id, 'JBSWY3DPEHPK3PXP' );

		$subscriber_with_2fa_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		CredentialStore::store_totp_secret( $subscriber_with_2fa_id, 'JBSWY3DPEHPK3PXP' );
		update_user_meta( $subscriber_with_2fa_id, '_cb_unrelated_meta', 'preserve-me' );

		$pending_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		EnrollmentStore::start( $pending_user_id );

		$imported_challenge = ChallengeStore::create(
			$imported_id,
			false,
			admin_url(),
			ChallengeStore::FLOW_VERIFY
		);
		$imported_generation = CredentialStore::challenge_generation( $imported_id );
		self::assertSame(
			$imported_generation,
			get_user_meta( $imported_id, CredentialStore::META_CHALLENGE_GENERATION, true )
		);
		self::assertTrue( CredentialStore::is_enrolled( $imported_id ) );
		self::assertTrue( CredentialStore::is_enrolled( $subscriber_with_2fa_id ) );
		self::assertNotNull( EnrollmentStore::pending_secret( $pending_user_id ) );
		self::assertIsArray( ChallengeStore::inspect( $imported_challenge ) );

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

		$activated = Recovery::activate_destination( $ticket );
		self::assertSame( 'pending_reconcile', $activated['status'] );
		self::assertFalse(
			RolePolicySchema::inspect( false, 'test' )['canonical'],
			'Live-switch activation must not reconcile Base policy through stale request-local caches.'
		);
		$imported_before_reconcile = get_userdata( $imported_id );
		self::assertInstanceOf( WP_User::class, $imported_before_reconcile );
		self::assertTrue(
			PrivilegedAccessRegistry::is_approved( $imported_before_reconcile ),
			'Activation phase unexpectedly mutated imported trust before a fresh runtime was available.'
		);

		$state = Recovery::reconcile_destination( $ticket );
		self::assertSame( 'pending_auth', $state['status'] );
		self::assertTrue( RolePolicySchema::inspect( false, 'test' )['canonical'] );
		self::assertSame( TrustSchemaMigrator::current_schema(), TrustSchemaMigrator::stored_schema() );
		self::assertNotFalse( get_option( 'cb_core_privileged_guard_bootstrapped', false ) );

		self::assertFalse( CredentialStore::is_enrolled( $imported_id ), 'Imported privileged Base 2FA crossed the destination trust boundary.' );
		self::assertFalse( CredentialStore::is_enrolled( $other_id ), 'Imported secondary privileged Base 2FA crossed the destination trust boundary.' );
		self::assertFalse( CredentialStore::is_enrolled( $subscriber_with_2fa_id ), 'Imported non-privileged Base 2FA remained available for future privilege elevation.' );
		self::assertSame( [], CredentialStore::recovery_hashes( $imported_id ) );
		self::assertNull( EnrollmentStore::pending_secret( $pending_user_id ) );
		self::assertNull( ChallengeStore::inspect( $imported_challenge ) );
		self::assertFalse(
			metadata_exists( 'user', $imported_id, CredentialStore::META_CHALLENGE_GENERATION ),
			'Imported 2FA challenge generation crossed the destination trust boundary.'
		);
		self::assertSame( 'preserve-me', get_user_meta( $subscriber_with_2fa_id, '_cb_unrelated_meta', true ) );

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
		Recovery::activate_destination( $ticket );
	}

	public function test_pretty_routing_requirement_follows_destination_runtime(): void {
		update_option( 'permalink_structure', '', false );
		self::assertFalse( Recovery::requires_pretty_routing() );

		update_option( 'permalink_structure', '/%postname%/', false );
		self::assertTrue( Recovery::requires_pretty_routing() );
	}
}
