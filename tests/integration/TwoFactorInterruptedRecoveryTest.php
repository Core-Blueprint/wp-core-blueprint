<?php
declare(strict_types=1);

use CoreBlueprint\Core\Security\TwoFactor\AccountManager;
use CoreBlueprint\Core\Security\TwoFactor\ChallengeStore;
use CoreBlueprint\Core\Security\TwoFactor\CredentialStore;
use CoreBlueprint\Core\Security\TwoFactor\LoginController;
use CoreBlueprint\Core\Security\TwoFactor\RecoveryCodes;
use CoreBlueprint\Core\Security\TwoFactor\RecoveryManager;
use CoreBlueprint\Core\Security\TwoFactor\Totp;

final class CB_H1_R02_Interrupted_Recovery_Test extends WP_UnitTestCase {
	private function last_code( int $user_id ): string {
		$code = 'ABCDE-12345-67890-ABCDE';
		CredentialStore::store_recovery_hashes( $user_id, [ wp_hash_password( str_replace( '-', '', $code ) ) ] );
		return $code;
	}

	public function test_lost_display_with_working_totp_allows_factor_authorized_regeneration(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator', 'user_pass' => 'known-h1-password' ] );
		$user = get_userdata( $user_id );
		$secret = 'JBSWY3DPEHPK3PXP';
		CredentialStore::store_totp_secret( $user_id, $secret );
		$old_code = $this->last_code( $user_id );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$result = LoginController::process( $token, $old_code );
		self::assertSame( 'success', $result['status'] ?? null );
		self::assertCount( RecoveryCodes::CODE_COUNT, $result['recovery_codes'] ?? [] );
		unset( $result ); // Simulated lost one-time response. No plaintext recovery material is saved.
		self::assertFalse( RecoveryCodes::consume( $user_id, $old_code ) );
		self::assertCount( RecoveryCodes::CODE_COUNT, CredentialStore::recovery_hashes( $user_id ) );
		$raw = (string) wp_json_encode( get_user_meta( $user_id, CredentialStore::META_RECOVERY, true ) );
		self::assertStringNotContainsString( 'ABCDE1234567890ABCDE', $raw );

		$before = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			$codes = AccountManager::regenerate_recovery_codes(
				$user,
				'known-h1-password',
				Totp::code( $secret, time() )
			);
			self::assertCount( RecoveryCodes::CODE_COUNT, $codes );
		} finally {
			wp_set_current_user( $before );
		}
	}

	public function test_lost_display_with_broken_totp_requires_authorized_reset_and_revokes_challenge(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		update_user_meta( $user_id, CredentialStore::META_ENROLLED_AT, time() );
		update_user_meta( $user_id, CredentialStore::META_SECRET, [
			'version' => 1, 'algorithm' => 'sodium_secretbox', 'nonce' => 'invalid', 'ciphertext' => 'invalid',
		] );
		$code = $this->last_code( $user_id );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$result = LoginController::process( $token, $code );
		self::assertSame( 'success', $result['status'] ?? null );
		self::assertCount( RecoveryCodes::CODE_COUNT, $result['recovery_codes'] ?? [] );
		unset( $result );
		self::assertFalse( RecoveryCodes::consume( $user_id, $code ) );
		$operator_approval_before = get_user_meta( $user_id, '_cb_core_privileged_approval', true );
		$subsequent = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		self::assertIsArray( ChallengeStore::inspect( $subsequent ) );
		$stats = RecoveryManager::reset_user( $user, 'test_lost_delivery_operator_recovery' );
		self::assertTrue( $stats['challenges_revoked'] );
		self::assertNull( ChallengeStore::inspect( $subsequent ) );
		self::assertSame( CredentialStore::STATUS_NOT_ENROLLED, CredentialStore::enrollment_status( $user_id ) );
		self::assertSame( [], CredentialStore::recovery_hashes( $user_id ) );
		self::assertSame( $operator_approval_before, get_user_meta( $user_id, '_cb_core_privileged_approval', true ) );
	}
}
