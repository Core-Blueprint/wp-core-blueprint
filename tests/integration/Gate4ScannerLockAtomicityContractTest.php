<?php
declare(strict_types=1);

use CoreBlueprint\Core\Integrity\Scanner\ScannerLock;
use CoreBlueprint\Core\Integrity\Scanner\ScanSliceLock;

final class Gate4ScannerLockAtomicityContractTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'cb_core_integrity_scan_lock' );
		delete_option( 'cb_core_integrity_scan_slice_lock' );
		parent::tearDown();
	}

	public function test_scanner_lock_stale_takeover_and_release(): void {
		update_option( 'cb_core_integrity_scan_lock', [
			'token'        => 'stale-owner',
			'source'       => 'test',
			'job_id'       => 'stale-job',
			'acquired_at'  => time() - 8000,
			'refreshed_at' => time() - 8000,
		], false );

		$token = ScannerLock::acquire( 'test', 'fresh-job' );

		$this->assertNotSame( '', $token );
		$this->assertTrue( ScannerLock::is_owned_by( $token ) );
		$this->assertSame( 'fresh-job', (string) ( ScannerLock::current()['job_id'] ?? '' ) );

		ScannerLock::release( $token );
		$this->assertSame( [], ScannerLock::current() );
	}

	public function test_slice_lock_can_replace_lease_from_superseded_job(): void {
		$global_token = ScannerLock::acquire( 'test', 'fresh-job' );
		update_option( 'cb_core_integrity_scan_slice_lock', [
			'token'       => 'old-slice',
			'job_id'      => 'old-job',
			'acquired_at' => time(),
		], false );

		$slice_token = ScanSliceLock::acquire( 'fresh-job' );

		$this->assertNotNull( $slice_token );
		$this->assertSame( 'fresh-job', (string) ( ScanSliceLock::current()['job_id'] ?? '' ) );

		ScanSliceLock::release( (string) $slice_token );
		$this->assertSame( [], ScanSliceLock::current() );
		ScannerLock::release( $global_token );
	}

	public function test_lock_mutations_are_compare_and_swap_guarded(): void {
		$scanner = file_get_contents( CB_CORE_DIR . 'src/Integrity/Scanner/ScannerLock.php' );
		$slice   = file_get_contents( CB_CORE_DIR . 'src/Integrity/Scanner/ScanSliceLock.php' );

		$this->assertIsString( $scanner );
		$this->assertIsString( $slice );

		$this->assertStringContainsString( 'AND option_value = %s', $scanner );
		$this->assertStringContainsString( 'DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s', $scanner );
		$this->assertStringContainsString( 'AND option_value = %s', $slice );
		$this->assertStringContainsString( 'DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s', $slice );
	}
}
