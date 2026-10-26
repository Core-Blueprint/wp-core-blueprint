<?php
declare(strict_types=1);

use CoreBlueprint\Core\CLI\Commands\TwoFactor\Unlock;
use CoreBlueprint\Core\CLI\Registry as CLIRegistry;
use CoreBlueprint\Core\Console\Registry as ConsoleRegistry;
use CoreBlueprint\Core\Security\TwoFactor\CredentialStore;
use CoreBlueprint\Core\Security\TwoFactor\EnrollmentStore;
use CoreBlueprint\Core\Security\TwoFactor\RecoveryManager;

final class CB_H1_R01_Enrollment_Lock_Recovery_Test extends WP_UnitTestCase {
	private function option( int $user_id ): string {
		return 'cb_core_2fa_enrollment_lock_' . $user_id;
	}

	public function test_normal_acquire_release_and_reentrant_enrollment_denied(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		$inside = false;
		CredentialStore::with_enrollment_lock( $user_id, static function () use ( $user_id, $key, &$inside ): void {
			$inside = true;
			$record = get_option( $key );
			self::assertIsArray( $record );
			self::assertSame( 1, $record['version'] );
			self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $record['owner'] );
			self::assertIsInt( $record['created_at'] );
			try {
				EnrollmentStore::start( $user_id );
				self::fail( 'Overlapping enrollment acquired a locked user.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'busy', $error->getMessage() );
			}
		} );
		self::assertTrue( $inside );
		self::assertFalse( get_option( $key, false ) );
		self::assertNotSame( '', EnrollmentStore::start( $user_id ) );
	}

	public function test_original_unlock_never_removes_replaced_lock(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		$replacement = [ 'version' => 1, 'owner' => str_repeat( 'e', 32 ), 'created_at' => time() - 1200 ];
		try {
			CredentialStore::with_enrollment_lock( $user_id, static function () use ( $key, $replacement ): void {
				self::assertTrue( update_option( $key, $replacement, false ) );
			} );
			self::fail( 'Replaced lock was released by the previous owner.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'release', $error->getMessage() );
			self::assertSame( $replacement, get_option( $key ) );
		} finally {
			delete_option( $key );
		}
	}

	public function test_lock_fail_closed_after_uncaught_operation_error(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		try {
			CredentialStore::with_enrollment_lock( $user_id, static function (): void {
				throw new RuntimeException( 'simulated write failure' );
			} );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'simulated write failure', $error->getMessage() );
		}
		self::assertFalse( get_option( $key, false ) );
	}

	public function test_legacy_string_lock_remains_fail_closed(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		self::assertTrue( add_option( $key, 'older-owner', '', false ) );
		try {
			EnrollmentStore::start( $user_id );
			self::fail( 'Legacy lock was automatically replaced.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'busy', $error->getMessage() );
		} finally {
			self::assertSame( 'older-owner', get_option( $key ) );
			delete_option( $key );
		}
	}

	public function test_trusted_cli_only_registration_and_no_browser_console(): void {
		$names = array_column( CLIRegistry::commands(), 'name' );
		self::assertContains( 'two-factor unlock', $names );
		self::assertNull( ConsoleRegistry::find( 'cb-two-factor-unlock' ) );
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			self::assertSame( 'error', ( new Unlock() )->execute( [ 'user' => '1', 'inspect' => true ] )->status );
		}
	}

	public function test_recovery_only_after_explicit_exact_evidence_and_cli_authority(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		$record = [ 'version' => 1, 'owner' => str_repeat( 'a', 32 ), 'created_at' => time() - 1200 ];
		self::assertTrue( add_option( $key, $record, '', false ) );
		try {
			if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
				try {
					CredentialStore::recover_enrollment_lock( $user_id, hash( 'sha256', maybe_serialize( $record ) ), true );
					self::fail( 'Browser-style recovery bypassed trusted CLI.' );
				} catch ( RuntimeException $error ) {
					self::assertStringContainsString( 'Trusted CLI', $error->getMessage() );
				}
				return;
			}
			$proof = CredentialStore::enrollment_lock_evidence( $user_id );
			self::assertIsArray( $proof );
			self::assertFalse( $proof['legacy'] );
			self::assertFalse( CredentialStore::recover_enrollment_lock( $user_id, str_repeat( '0', 64 ), true ) );
			self::assertSame( $record, get_option( $key ) );
			self::assertTrue( CredentialStore::recover_enrollment_lock( $user_id, $proof['sha256'], true ) );
			self::assertFalse( get_option( $key, false ) );
			$user = get_userdata( $user_id );
			CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
			$stats = RecoveryManager::reset_user( $user, 'h1_r01_test' );
			self::assertTrue( $stats['was_enrolled'] );
			self::assertSame( CredentialStore::STATUS_NOT_ENROLLED, CredentialStore::enrollment_status( $user_id ) );
		} finally {
			delete_option( $key );
		}
	}

	public function test_database_unlock_failure_retains_original_lock(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		$blocked = false;
		$filter = static function ( string $sql ) use ( $key, &$blocked ): string {
			if ( str_starts_with( $sql, 'DELETE FROM ' ) && str_contains( $sql, $key ) ) {
				$blocked = true;
				return 'SELECT 1 WHERE 0'; // Simulate an unsuccessful conditional delete.
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			try {
				CredentialStore::with_enrollment_lock( $user_id, static function (): void {} );
				self::fail( 'Failed unlock was accepted as successful.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'release', $error->getMessage() );
			}
		} finally {
			remove_filter( 'query', $filter );
			self::assertTrue( $blocked );
			self::assertIsArray( get_option( $key ) );
			delete_option( $key );
		}
	}

	public function test_binary_exact_delete_refuses_owner_case_replacement(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		$replacement = null;
		try {
			CredentialStore::with_enrollment_lock( $user_id, static function () use ( $key, &$replacement ): void {
				$replacement = get_option( $key );
				$replacement['owner'] = strtoupper( $replacement['owner'] );
				if ( $replacement['owner'] === get_option( $key )['owner'] ) {
					$replacement['owner'] = str_repeat( 'A', 32 );
				}
				self::assertTrue( update_option( $key, $replacement, false ) );
			} );
			self::fail( 'Case-insensitive SQL deleted a different owner.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'release', $error->getMessage() );
			self::assertSame( $replacement, get_option( $key ) );
		} finally {
			delete_option( $key );
		}
	}

	public function test_legacy_and_fresh_lock_recovery_guards_under_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			self::assertTrue( true ); // Dedicated trusted WP-CLI runtime is a separate acceptance gate.
			return;
		}
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		$fresh = [ 'version' => 1, 'owner' => str_repeat( 'c', 32 ), 'created_at' => time() ];
		self::assertTrue( add_option( $key, $fresh, '', false ) );
		try {
			$evidence = CredentialStore::enrollment_lock_evidence( $user_id );
			self::assertIsArray( $evidence );
			$this->expectException( RuntimeException::class );
			CredentialStore::recover_enrollment_lock( $user_id, $evidence['sha256'], true );
		} finally {
			self::assertSame( $fresh, get_option( $key ) );
			delete_option( $key );
		}
	}

	public function test_enrollment_lock_acquire_refuses_failed_insert(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$key = $this->option( $user_id );
		$blocked = false;
		$filter = static function ( string $sql ) use ( $key, &$blocked ): string {
			if ( str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, $key ) ) {
				$blocked = true;
				return 'SELECT 1 WHERE 0';
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			try {
				CredentialStore::with_enrollment_lock( $user_id, static function (): void {
					self::fail( 'Callback executed without acquiring the lock.' );
				} );
				self::fail( 'Failed lock insert was accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'busy', $error->getMessage() );
			}
		} finally {
			remove_filter( 'query', $filter );
			self::assertTrue( $blocked );
			self::assertFalse( get_option( $key, false ) );
		}
	}
}
