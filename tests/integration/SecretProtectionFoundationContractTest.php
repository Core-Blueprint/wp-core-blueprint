<?php
declare(strict_types=1);

use CB\Core\Security\SecretProtection;

final class CB_Base_Secret_Protection_Foundation_Contract_Test extends WP_UnitTestCase {

	public function test_sp1_round_trip_is_opaque_and_context_bound(): void {
		self::assertTrue( SecretProtection::available() );

		$secret = 'calendar-app-password-123';
		$payload = SecretProtection::seal(
			$secret,
			'core-blueprint-bookings.caldav',
			'connection:42'
		);

		self::assertIsString( $payload );
		self::assertNotSame( $secret, $payload );
		self::assertStringNotContainsString( $secret, $payload );
		$second_payload = SecretProtection::seal(
			$secret,
			'core-blueprint-bookings.caldav',
			'connection:42'
		);
		self::assertIsString( $second_payload );
		self::assertNotSame( $payload, $second_payload, 'Secret protection must use a fresh nonce for every seal operation.' );
		self::assertSame(
			$secret,
			SecretProtection::open(
				$payload,
				'core-blueprint-bookings.caldav',
				'connection:42'
			)
		);

		$wrong_purpose = SecretProtection::open( $payload, 'core-blueprint-bookings.other', 'connection:42' );
		self::assertWPError( $wrong_purpose );
		self::assertSame( 'cb_core_secret_protection_authentication_failed', $wrong_purpose->get_error_code() );

		$wrong_subject = SecretProtection::open( $payload, 'core-blueprint-bookings.caldav', 'connection:43' );
		self::assertWPError( $wrong_subject );
		self::assertSame( 'cb_core_secret_protection_authentication_failed', $wrong_subject->get_error_code() );
	}

	public function test_sp1_plaintext_malformed_tampered_and_oversized_payloads_fail_closed(): void {
		$plaintext = SecretProtection::open(
			'this-is-not-an-encrypted-payload',
			'core-blueprint-bookings.caldav',
			'connection:42'
		);
		self::assertWPError( $plaintext );
		self::assertSame( 'cb_core_secret_protection_unsupported_payload', $plaintext->get_error_code() );

		$payload = SecretProtection::seal(
			'correct-horse-battery-staple',
			'core-blueprint-bookings.caldav',
			'connection:42'
		);
		self::assertIsString( $payload );

		$last = strlen( $payload ) - 1;
		$tampered = substr( $payload, 0, $last ) . ( 'A' === $payload[ $last ] ? 'B' : 'A' );
		$result = SecretProtection::open(
			$tampered,
			'core-blueprint-bookings.caldav',
			'connection:42'
		);
		self::assertWPError( $result );
		self::assertTrue(
			in_array(
				$result->get_error_code(),
				[ 'cb_core_secret_protection_invalid_payload', 'cb_core_secret_protection_authentication_failed' ],
				true
			)
		);

		$too_large = SecretProtection::seal(
			str_repeat( 'x', SecretProtection::MAX_SECRET_BYTES + 1 ),
			'core-blueprint-bookings.caldav',
			'connection:42'
		);
		self::assertWPError( $too_large );
		self::assertSame( 'cb_core_secret_protection_invalid_secret', $too_large->get_error_code() );
	}

	public function test_sp1_invalid_context_and_empty_secret_fail_closed_without_secret_leakage(): void {
		$secret = 'never-show-this-value';

		foreach ( [
			SecretProtection::seal( '', 'core-blueprint-bookings.caldav', 'connection:42' ),
			SecretProtection::seal( $secret, 'Core Blueprint Bookings', 'connection:42' ),
			SecretProtection::seal( $secret, 'core-blueprint-bookings.caldav', 'connection 42' ),
		] as $result ) {
			self::assertWPError( $result );
			self::assertStringNotContainsString( $secret, $result->get_error_message() );
		}
	}

	public function test_sp1_foundation_owns_no_credential_storage_or_diagnostics(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Security/SecretProtection.php' );
		self::assertIsString( $source );
		self::assertSame( 2, substr_count( $source, '#[\\SensitiveParameter]' ), 'Secret-bearing public parameters must remain stack-trace redacted.' );

		foreach ( [
			'$wpdb',
			'update_option(',
			'add_option(',
			'update_user_meta(',
			'add_user_meta(',
			'error_log(',
			'Audit::',
			'Mail\\Secrets',
			'TwoFactor\\Credential',
		] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}
}
