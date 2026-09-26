<?php
declare(strict_types=1);

use CB\Core\CLI\Commands\TwoFactor\Reset as TwoFactorReset;
use CB\Core\CLI\Commands\TwoFactor\Status as TwoFactorStatus;
use CB\Core\CLI\Registry as CLIRegistry;
use CB\Core\Console\Registry as ConsoleRegistry;
use CB\Core\Security\TwoFactor\ChallengeStore;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\EnrollmentStore;
use CB\Core\Security\TwoFactor\RecoveryCodes;
use CB\Core\Security\TwoFactor\RecoveryManager;

final class CB_Base_Two_Factor_Recovery_Contract_Test extends WP_UnitTestCase {

	public function test_tr1_domain_reset_revokes_challenges_and_clears_only_base_owned_authentication_state(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		$codes = RecoveryCodes::generate_for_user( $user_id );
		update_user_meta( $user_id, '_cb_unrelated_meta', 'preserve-me' );
		$token = ChallengeStore::create(
			$user_id,
			false,
			admin_url(),
			ChallengeStore::FLOW_VERIFY
		);
		$generation_before = CredentialStore::stored_challenge_generation( $user_id );

		self::assertIsString( $generation_before );
		self::assertIsArray( ChallengeStore::inspect( $token ) );

		$stats = RecoveryManager::reset_user( $user, 'test' );

		self::assertTrue( $stats['changed'] );
		self::assertTrue( $stats['was_enrolled'] );
		self::assertCount( RecoveryCodes::CODE_COUNT, $codes );
		self::assertSame( RecoveryCodes::CODE_COUNT, $stats['recovery_codes'] );
		self::assertTrue( $stats['challenges_revoked'] );
		self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
		self::assertSame( [], CredentialStore::recovery_hashes( $user_id ) );
		self::assertNull( ChallengeStore::inspect( $token ) );
		self::assertNull( ChallengeStore::take( $token ) );
		self::assertNotSame( $generation_before, CredentialStore::stored_challenge_generation( $user_id ) );
		self::assertSame( 'preserve-me', get_user_meta( $user_id, '_cb_unrelated_meta', true ) );
	}

	public function test_tr2_domain_reset_clears_pending_enrollment_and_enrollment_challenge(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		self::assertNotSame( '', EnrollmentStore::start( $user_id ) );
		$token = ChallengeStore::create(
			$user_id,
			false,
			admin_url(),
			ChallengeStore::FLOW_ENROLL
		);
		self::assertIsArray( ChallengeStore::inspect( $token ) );

		$stats = RecoveryManager::reset_user( $user, 'test' );

		self::assertTrue( $stats['changed'] );
		self::assertTrue( $stats['pending_enrollment'] );
		self::assertNull( EnrollmentStore::pending_secret( $user_id ) );
		self::assertNull( ChallengeStore::inspect( $token ) );
	}

	public function test_tr3_cli_surface_is_registered_but_reset_is_absent_from_browser_console(): void {
		$names = array_map(
			static fn ( array $entry ): string => (string) $entry['name'],
			CLIRegistry::commands()
		);

		self::assertContains( 'two-factor status', $names );
		self::assertContains( 'two-factor reset', $names );
		self::assertNull( ConsoleRegistry::find( 'cb-two-factor-reset' ) );
		self::assertNull( ConsoleRegistry::find( 'cb-two-factor-status' ) );
	}

	public function test_tr4_status_reports_base_state_without_exposing_authentication_material(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		RecoveryCodes::generate_for_user( $user_id );

		$result = ( new TwoFactorStatus() )->execute( [ 'user' => (string) $user_id ] );

		self::assertContains( $result->status, [ 'success', 'warning' ] );
		self::assertTrue( (bool) ( $result->data['base_enrolled'] ?? false ) );
		self::assertSame( RecoveryCodes::CODE_COUNT, (int) ( $result->data['recovery_codes'] ?? 0 ) );
		$serialized = (string) wp_json_encode( $result->to_array() );
		self::assertStringNotContainsString( 'JBSWY3DPEHPK3PXP', $serialized );
		self::assertStringNotContainsString( CredentialStore::META_SECRET, $serialized );
	}

	public function test_tr5_reset_command_refuses_browser_execution(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::assertTrue( (bool) WP_CLI );
			return;
		}

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );

		$result = ( new TwoFactorReset() )->execute( [ 'user' => (string) $user_id ] );

		self::assertSame( 'error', $result->status );
		self::assertStringContainsString( 'server-side WP-CLI', $result->message );
		self::assertTrue( CredentialStore::is_enrolled( $user_id ) );
	}
}
