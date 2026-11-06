<?php
declare(strict_types=1);

use CoreBlueprint\Core\Integrity\Quarantine\MutationLock;

final class CB_Base_Quarantine_Mutation_Lock_Security_Contract_Test extends WP_UnitTestCase {
	private const ID = 'q_1234567890abcdef';

	private function key(): string {
		return 'cb_core_quarantine_mutation_lock_' . self::ID;
	}

	protected function tearDown(): void {
		delete_option( $this->key() );
		parent::tearDown();
	}

	public function test_f03_exclusive_owner_acquires_and_releases(): void {
		$token = MutationLock::acquire( self::ID );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
		$owner = get_option( $this->key() );
		self::assertSame( $token, $owner['token'] ?? null );

		try {
			MutationLock::acquire( self::ID );
			self::fail( 'Another worker acquired a held quarantine lock.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Another quarantine action', $error->getMessage() );
		}

		MutationLock::release( self::ID, $token );
		self::assertFalse( get_option( $this->key(), false ) );
	}

	public function test_f03_stale_timestamp_never_allows_takeover(): void {
		$stale = [ 'token' => str_repeat( 'a', 32 ), 'acquired_at' => time() - 7200 ];
		self::assertTrue( add_option( $this->key(), $stale, '', false ) );
		try {
			MutationLock::acquire( self::ID );
			self::fail( 'Quarantine lock was reclaimed based only on elapsed time.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Another quarantine action', $error->getMessage() );
		}
		self::assertSame( $stale, get_option( $this->key() ) );
	}

	public function test_f03_old_worker_cannot_release_new_owner(): void {
		$token = MutationLock::acquire( self::ID );
		$replacement = [ 'token' => str_repeat( 'b', 32 ), 'acquired_at' => time() ];
		self::assertTrue( update_option( $this->key(), $replacement, false ) );

		try {
			MutationLock::release( self::ID, $token );
			self::fail( 'Stale worker deleted a successor owner.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'ownership was lost', $error->getMessage() );
		}
		self::assertSame( $replacement, get_option( $this->key() ) );
	}

	public function test_f03_missing_lock_fails_closed_on_release(): void {
		$token = MutationLock::acquire( self::ID );
		delete_option( $this->key() );
		$this->expectException( RuntimeException::class );
		MutationLock::release( self::ID, $token );
	}

	public function test_f03_untrusted_runtime_cannot_recover_orphan(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->markTestSkipped( 'Trusted CLI recovery requires separate operator approval.' );
		}
		MutationLock::acquire( self::ID );
		self::assertNull( MutationLock::lock_evidence( self::ID ) );
		$this->expectException( RuntimeException::class );
		MutationLock::recover_lock( self::ID, str_repeat( '0', 64 ), true );
	}

	public function test_f03_invalid_item_id_is_rejected_before_lock_claim(): void {
		$this->expectException( RuntimeException::class );
		MutationLock::acquire( '../outside' );
	}

	public function test_f03_source_enforces_exact_value_delete_without_stale_takeover(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Integrity/Quarantine/MutationLock.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'AND BINARY option_value = BINARY %s', $source );
		self::assertStringNotContainsString( 'STALE_TTL', $source );
		self::assertStringContainsString( 'confirmed_inactive', $source );
	}
}
