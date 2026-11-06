<?php
declare(strict_types=1);

use CoreBlueprint\Core\Security\TwoFactor\ChallengeStore;
use CoreBlueprint\Core\Security\TwoFactor\CredentialStore;

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
	public function test_tc5_generation_rotation_revokes_existing_challenges(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create(
			$user_id,
			false,
			admin_url(),
			ChallengeStore::FLOW_VERIFY
		);

		$before = CredentialStore::challenge_generation( $user_id );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $before );
		self::assertIsArray( ChallengeStore::inspect( $token ) );

		$after = CredentialStore::rotate_challenge_generation( $user_id );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $after );
		self::assertNotSame( $before, $after );
		self::assertNull( ChallengeStore::inspect( $token ) );
		self::assertNull( ChallengeStore::take( $token ) );
	}


	public function test_tc6_occupied_stale_lock_never_gets_automatically_reclaimed(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$lock = 'cb_core_2fa_ch_lock_' . hash( 'sha256', $token );
		$stale_owner = [ 'token' => str_repeat( 'a', 32 ), 'acquired_at' => time() - 3600 ];

		self::assertTrue( add_option( $lock, $stale_owner, '', false ) );
		try {
			self::assertNull( ChallengeStore::take( $token ), 'A second claimant took over an occupied challenge lock.' );
			self::assertSame( $stale_owner, get_option( $lock ), 'An expired timestamp must not authorize deleting another owner.' );
			self::assertIsArray( ChallengeStore::inspect( $token ), 'Denied claims must not consume the challenge.' );
		} finally {
			delete_option( $lock );
		}
		self::assertIsArray( ChallengeStore::take( $token ), 'The original challenge should remain usable after the test lock is removed.' );
	}

	public function test_tc7_overlapping_claim_is_denied_while_first_worker_owns_lock(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$key = 'cb_core_2fa_ch_' . hash( 'sha256', $token );
		$second_claim = 'not attempted';
		$listener = static function () use ( $token, &$second_claim ): void {
			$second_claim = ChallengeStore::take( $token );
		};

		add_action( 'delete_transient_' . $key, $listener );
		try {
			self::assertIsArray( ChallengeStore::take( $token ) );
		} finally {
			remove_action( 'delete_transient_' . $key, $listener );
		}
		self::assertNull( $second_claim, 'The overlapping worker must fail closed while the first holds the lock.' );
		self::assertNull( ChallengeStore::take( $token ), 'The first worker must consume the one-time challenge.' );
	}

	public function test_tc8_original_worker_never_deletes_replaced_challenge_lock(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$key = 'cb_core_2fa_ch_' . hash( 'sha256', $token );
		$lock = 'cb_core_2fa_ch_lock_' . hash( 'sha256', $token );
		$replacement = [ 'token' => str_repeat( 'b', 32 ), 'acquired_at' => time() ];
		$replaced = false;
		$listener = static function ( string $deleted_key ) use ( $key, $lock, $replacement, &$replaced ): void {
			if ( $deleted_key === $key ) {
				$replaced = update_option( $lock, $replacement, false );
			}
		};

		add_action( 'deleted_transient', $listener );
		try {
			self::assertIsArray( ChallengeStore::take( $token ) );
			self::assertTrue( $replaced, 'The replacement-owner fixture did not execute.' );
			self::assertSame( $replacement, get_option( $lock ), 'The original worker removed a newer lock owner.' );
		} finally {
			remove_action( 'deleted_transient', $listener );
			delete_option( $lock );
		}
		self::assertNull( ChallengeStore::take( $token ) );
	}

	public function test_tc9_failed_transient_delete_never_authenticates_or_reissues(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$key = 'cb_core_2fa_ch_' . hash( 'sha256', $token );
		$option = '_transient_' . $key;
		$blocked = false;
		$filter = static function ( string $query ) use ( $option, &$blocked ): string {
			if ( str_starts_with( $query, 'DELETE FROM ' ) && str_contains( $query, $option ) ) {
				$blocked = true;
				return 'SELECT 1 WHERE 1 = 0';
			}
			return $query;
		};

		add_filter( 'query', $filter );
		try {
			$result = \CoreBlueprint\Core\Security\TwoFactor\LoginController::process( $token, '000000' );
			self::assertSame( 'invalid', $result['status'] ?? null, 'A deletion failure must not progress to factor verification.' );
			self::assertTrue( $blocked, 'The transient deletion failure was not injected.' );
		} finally {
			remove_filter( 'query', $filter );
			// WordPress may cache a failed delete as a non-existing option.
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		self::assertIsArray( ChallengeStore::inspect( $token ), 'The failed delete must not be mistaken for a consumed challenge.' );
		self::assertIsArray( ChallengeStore::take( $token ), 'The challenge should remain usable after storage recovers.' );
	}
}
