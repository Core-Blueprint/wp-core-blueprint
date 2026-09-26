<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\EnrollmentStore;
use CB\Core\Security\TwoFactor\RecoveryCodes;
use CB\Core\Security\TwoFactor\Totp;

final class CB_Base_Two_Factor_Enrollment_Contract_Test extends WP_UnitTestCase {

	public function test_te1_pending_secret_is_encrypted_user_bound_and_resumable(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret  = EnrollmentStore::start( $user_id );

		self::assertMatchesRegularExpression( '/^[A-Z2-7]{32}$/', $secret );
		self::assertSame( $secret, EnrollmentStore::start( $user_id ) );
		self::assertSame( $secret, EnrollmentStore::pending_secret( $user_id ) );

		$stored = get_user_meta( $user_id, EnrollmentStore::META_PENDING, true );
		self::assertIsArray( $stored );
		self::assertStringNotContainsString( $secret, (string) wp_json_encode( $stored ) );
		self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
	}

	public function test_te2_invalid_confirmation_keeps_pending_state_and_creates_no_credential(): void {
		$user_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret    = EnrollmentStore::start( $user_id );
		$timestamp = 1800000000;

		self::assertNull( EnrollmentStore::confirm( $user_id, '000000', $timestamp ) );
		self::assertSame( $secret, EnrollmentStore::pending_secret( $user_id ) );
		self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
		self::assertSame( [], CredentialStore::recovery_hashes( $user_id ) );
	}

	public function test_te3_valid_confirmation_promotes_secret_consumes_code_and_issues_recovery_codes(): void {
		$user_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret    = EnrollmentStore::start( $user_id );
		$timestamp = 1800000000;
		$code      = Totp::code( $secret, $timestamp );

		$codes = EnrollmentStore::confirm( $user_id, $code, $timestamp );

		self::assertIsArray( $codes );
		self::assertCount( RecoveryCodes::CODE_COUNT, $codes );
		self::assertTrue( CredentialStore::is_enrolled( $user_id ) );
		self::assertSame( $secret, CredentialStore::totp_secret( $user_id ) );
		self::assertSame( intdiv( $timestamp, Totp::PERIOD ), CredentialStore::last_timestep( $user_id ) );
		self::assertSame( RecoveryCodes::CODE_COUNT, RecoveryCodes::remaining( $user_id ) );
		self::assertNull( EnrollmentStore::pending_secret( $user_id ) );
		self::assertFalse( metadata_exists( 'user', $user_id, EnrollmentStore::META_PENDING ) );

		// The enrollment-confirmation TOTP is consumed and cannot immediately be
		// reused as the first login factor in the same timestep.
		self::assertNull(
			Totp::verify(
				$secret,
				$code,
				$timestamp,
				1,
				CredentialStore::last_timestep( $user_id )
			)
		);

		$stored_json = (string) wp_json_encode( [
			'secret'   => get_user_meta( $user_id, CredentialStore::META_SECRET, true ),
			'recovery' => CredentialStore::recovery_hashes( $user_id ),
		] );
		self::assertStringNotContainsString( $secret, $stored_json );
		foreach ( $codes as $recovery_code ) {
			self::assertStringNotContainsString( str_replace( '-', '', $recovery_code ), $stored_json );
		}
	}

	public function test_te4_expired_pending_enrollment_is_removed_and_cannot_confirm(): void {
		$user_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret    = EnrollmentStore::start( $user_id );
		$timestamp = 1800000000;
		$state     = get_user_meta( $user_id, EnrollmentStore::META_PENDING, true );

		self::assertIsArray( $state );
		$state['expires_at'] = time() - 1;
		update_user_meta( $user_id, EnrollmentStore::META_PENDING, $state );

		self::assertNull( EnrollmentStore::pending_secret( $user_id ) );
		self::assertNull( EnrollmentStore::confirm( $user_id, Totp::code( $secret, $timestamp ), $timestamp ) );
		self::assertFalse( metadata_exists( 'user', $user_id, EnrollmentStore::META_PENDING ) );
		self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
	}

	public function test_te5_already_enrolled_user_cannot_start_parallel_enrollment(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );

		$this->expectException( RuntimeException::class );
		EnrollmentStore::start( $user_id );
	}
}
