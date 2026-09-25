<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\ChallengeStore;

final class CB_Base_Two_Factor_Challenge_Store_Contract_Test extends WP_UnitTestCase {

	public function test_tc1_challenge_token_is_one_time_and_never_persisted_plaintext(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create(
			$user_id,
			true,
			admin_url( 'plugins.php' ),
			ChallengeStore::FLOW_VERIFY
		);

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );

		$like = '%' . $wpdb->esc_like( $token ) . '%';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s",
				$like,
				$like
			)
		);
		self::assertSame( 0, $count, 'Plaintext two-factor challenge token was persisted in wp_options.' );

		$state = ChallengeStore::take( $token );
		self::assertIsArray( $state );
		self::assertSame( $user_id, $state['user_id'] );
		self::assertTrue( $state['remember'] );
		self::assertSame( ChallengeStore::FLOW_VERIFY, $state['flow'] );
		self::assertArrayNotHasKey( 'password', $state );
		self::assertArrayNotHasKey( 'token', $state );

		self::assertNull( ChallengeStore::take( $token ), 'Consumed challenge token remained reusable.' );
	}

	public function test_tc2_reissue_rotates_token_preserves_expiry_and_enforces_attempt_limit(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create(
			$user_id,
			false,
			admin_url(),
			ChallengeStore::FLOW_VERIFY
		);

		$original_created = 0;
		$original_expiry  = 0;

		for ( $attempt = 0; $attempt < ChallengeStore::MAX_ATTEMPTS; $attempt++ ) {
			$state = ChallengeStore::take( $token );
			self::assertIsArray( $state );
			self::assertSame( $attempt, (int) $state['attempts'] );

			if ( 0 === $attempt ) {
				$original_created = (int) $state['created_at'];
				$original_expiry  = (int) $state['expires_at'];
			} else {
				self::assertSame( $original_created, (int) $state['created_at'] );
				self::assertSame( $original_expiry, (int) $state['expires_at'] );
			}

			if ( $attempt < ChallengeStore::MAX_ATTEMPTS - 1 ) {
				$replacement = ChallengeStore::reissue( $state );
				self::assertIsString( $replacement );
				self::assertNotSame( $token, $replacement );
				self::assertNull( ChallengeStore::take( $token ), 'Old challenge token survived rotation.' );
				$token = $replacement;
				continue;
			}

			self::assertNull( ChallengeStore::reissue( $state ), 'Attempt limit still allowed a fresh challenge token.' );
		}
	}

	public function test_tc3_external_redirect_is_reduced_to_safe_admin_fallback(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create(
			$user_id,
			false,
			'https://attacker.example/steal',
			ChallengeStore::FLOW_ENROLL
		);

		$state = ChallengeStore::take( $token );
		self::assertIsArray( $state );
		self::assertSame( admin_url(), $state['redirect_to'] );
		self::assertSame( ChallengeStore::FLOW_ENROLL, $state['flow'] );
	}

	public function test_tc4_invalid_tokens_fail_closed_without_state_access(): void {
		self::assertNull( ChallengeStore::take( '' ) );
		self::assertNull( ChallengeStore::take( str_repeat( 'g', 64 ) ) );
		self::assertNull( ChallengeStore::take( str_repeat( 'a', 63 ) ) );
	}
}
