<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\Authenticator;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\Totp;

final class CB_Base_Two_Factor_Totp_Contract_Test extends WP_UnitTestCase {

	public function test_tt1_rfc6238_sha1_vectors_match_exactly(): void {
		$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
		$vectors = [
			59          => '94287082',
			1111111109  => '07081804',
			1111111111  => '14050471',
			1234567890  => '89005924',
			2000000000  => '69279037',
			20000000000 => '65353130',
		];

		foreach ( $vectors as $timestamp => $expected ) {
			self::assertSame( $expected, Totp::code( $secret, $timestamp, 8 ), 'RFC 6238 vector mismatch at timestamp ' . $timestamp );
		}
	}

	public function test_tt2_generated_secret_is_unpadded_base32_with_160_bits_entropy(): void {
		$secret = Totp::generate_secret();

		self::assertSame( 32, strlen( $secret ) );
		self::assertMatchesRegularExpression( '/^[A-Z2-7]{32}$/', $secret );
		self::assertStringNotContainsString( '=', $secret );
	}

	public function test_tt3_verification_accepts_bounded_clock_skew_and_rejects_invalid_codes(): void {
		$secret = 'JBSWY3DPEHPK3PXP';
		$timestamp = 1800000000;

		$current  = Totp::code( $secret, $timestamp );
		$previous = Totp::code( $secret, $timestamp - Totp::PERIOD );
		$next     = Totp::code( $secret, $timestamp + Totp::PERIOD );

		self::assertSame( intdiv( $timestamp, Totp::PERIOD ), Totp::verify( $secret, $current, $timestamp ) );
		self::assertSame( intdiv( $timestamp, Totp::PERIOD ) - 1, Totp::verify( $secret, $previous, $timestamp ) );
		self::assertSame( intdiv( $timestamp, Totp::PERIOD ) + 1, Totp::verify( $secret, $next, $timestamp ) );
		self::assertNull( Totp::verify( $secret, '00000', $timestamp ) );
		self::assertNull( Totp::verify( $secret, 'abcdef', $timestamp ) );
		self::assertNull( Totp::verify( $secret, $current, $timestamp, 3 ) );
	}

	public function test_tt4_successful_totp_is_one_time_per_timestep(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret  = 'JBSWY3DPEHPK3PXP';
		$timestamp = 1800000000;

		CredentialStore::store_totp_secret( $user_id, $secret );
		self::assertSame( -1, CredentialStore::last_timestep( $user_id ) );

		$code = Totp::code( $secret, $timestamp );
		self::assertTrue( Authenticator::verify_totp( $user_id, $code, $timestamp ) );

		$timestep = intdiv( $timestamp, Totp::PERIOD );
		self::assertSame( $timestep, CredentialStore::last_timestep( $user_id ) );
		self::assertFalse( Authenticator::verify_totp( $user_id, $code, $timestamp ), 'A TOTP code was accepted twice in the same timestep.' );

		$next_timestamp = $timestamp + Totp::PERIOD;
		$next_code = Totp::code( $secret, $next_timestamp );
		self::assertTrue( Authenticator::verify_totp( $user_id, $next_code, $next_timestamp ) );
		self::assertSame( $timestep + 1, CredentialStore::last_timestep( $user_id ) );
	}

	public function test_tt5_timestep_claim_is_monotonic_compare_and_swap(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );

		self::assertTrue( CredentialStore::claim_timestep( $user_id, 100 ) );
		self::assertFalse( CredentialStore::claim_timestep( $user_id, 100 ) );
		self::assertFalse( CredentialStore::claim_timestep( $user_id, 99 ) );
		self::assertTrue( CredentialStore::claim_timestep( $user_id, 101 ) );
		self::assertSame( 101, CredentialStore::last_timestep( $user_id ) );
	}
}
