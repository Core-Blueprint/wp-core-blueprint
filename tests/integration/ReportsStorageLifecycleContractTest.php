<?php
declare(strict_types=1);

use CB\Core\DB;
use CB\Core\Database\SchemaRegistry;
use CB\Core\Reports\MaintenanceAggregator;
use CB\Core\Reports\Storage;

final class CB_Base_Reports_Storage_Lifecycle_Contract_Test extends WP_UnitTestCase {

	private mixed $original_version = null;
	private bool $version_existed = false;
	private int $sentinel_id = 0;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->version_existed = false !== get_option( Storage::DB_OPT_KEY, false );
		$this->original_version = get_option( Storage::DB_OPT_KEY, null );

		Storage::install_schema();
		update_option( Storage::DB_OPT_KEY, Storage::DB_VERSION, false );

		$inserted = $wpdb->insert(
			Storage::table_name(),
			[
				'period_start' => '2026-09-01',
				'period_end'   => '2026-09-30',
				'generated_at' => '2026-09-30 12:00:00',
				'generated_by' => null,
				'report_data'  => wp_json_encode( [ 'snapshot_version' => MaintenanceAggregator::SNAPSHOT_VERSION, 'sentinel' => 'preserve-me' ] ),
				'status'       => 'generated',
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		self::assertSame( 1, $inserted );
		$this->sentinel_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $this->sentinel_id );
	}

	public function tear_down(): void {
		global $wpdb;

		if ( $this->sentinel_id > 0 ) {
			$wpdb->delete( Storage::table_name(), [ 'id' => $this->sentinel_id ], [ '%d' ] );
		}

		if ( $this->version_existed ) {
			update_option( Storage::DB_OPT_KEY, $this->original_version, false );
		} else {
			delete_option( Storage::DB_OPT_KEY );
		}

		parent::tear_down();
	}

	public function test_reconciliation_preserves_existing_reports_with_stale_or_missing_version_marker(): void {
		self::assertArrayHasKey( 'maintenance-reports', SchemaRegistry::definitions() );

		update_option( Storage::DB_OPT_KEY, '1.0', false );
		self::assertTrue( DB::reconcile_registered_schema( 'maintenance-reports', false ) );
		self::assertSame( Storage::DB_VERSION, (string) get_option( Storage::DB_OPT_KEY ) );
		$this->assert_sentinel_preserved();

		delete_option( Storage::DB_OPT_KEY );
		self::assertTrue( DB::reconcile_registered_schema( 'maintenance-reports', false ) );
		self::assertSame( Storage::DB_VERSION, (string) get_option( Storage::DB_OPT_KEY ) );
		$this->assert_sentinel_preserved();
	}

	private function assert_sentinel_preserved(): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, report_data, status FROM ' . Storage::table_name() . ' WHERE id = %d',
				$this->sentinel_id
			),
			ARRAY_A
		);

		self::assertIsArray( $row );
		self::assertSame( $this->sentinel_id, (int) $row['id'] );
		self::assertSame( 'generated', $row['status'] );
		self::assertStringContainsString( 'preserve-me', (string) $row['report_data'] );
	}
}
