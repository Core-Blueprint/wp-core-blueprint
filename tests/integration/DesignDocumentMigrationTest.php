<?php
declare(strict_types=1);

/**
 * G1d-A2.2 WordPress/MariaDB fixture. AUTHORED ONLY: no live DB test was run
 * under A2.2 source GO. Execute on a separately approved new test slot.
 *
 * Transactional prepare/two-worker evidence remains a dedicated Level 2+
 * scenario: WP_UnitTestCase itself often owns an ambient DB transaction.
 */
use CoreBlueprint\Core\Design\Document\MigrationBackup;
use CoreBlueprint\Core\Design\Document\MigrationInventory;
use CoreBlueprint\Core\Design\Document\Migration;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;
use CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate;
use CoreBlueprint\Core\Reports\ReportBranding;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;

final class DesignDocumentMigrationTest extends WP_UnitTestCase {
	/** @var array<string,mixed> */
	private array $original = [];

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( Schema::healthy(), 'A2.2 requires the installed A2.1 InnoDB schema.' );
		foreach ( [
			TemplateRepository::OPTION,
			MigrationInventory::SETTINGS_OPTION,
			MigrationBackup::OPTION,
			Migration::PREPARED_OPTION,
		] as $name ) {
			$this->original[$name] = get_option( $name, null );
		}
		delete_option( MigrationBackup::OPTION );
		delete_option( Migration::PREPARED_OPTION );
	}

	public function tear_down(): void {
		foreach ( $this->original as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
		parent::tear_down();
	}

	private function fixtures(): void {
		$this->assertTrue( update_option( TemplateRepository::OPTION, [
			'unknown.inactive' => [
				'subject' => 'Original subject',
				'project' => [ 'root' => [ 'type' => 'mail.root' ] ],
				'base_fingerprint' => 'old-provider',
				'modified_at' => '2026-01-01 00:00:00',
			],
		], false ) );
		$reports = [
			'branding' => ReportBranding::settings_defaults(),
			'composer' => [ 'maintenance' => MaintenanceTemplate::defaults() ],
			'retention_days' => 171, 'email_alerts' => [ 'generation_failed' => false ],
		];
		$this->assertTrue( update_option( MigrationInventory::SETTINGS_OPTION, [
			'reports' => $reports, 'unrelated' => 'retained',
		], false ) );
	}

	public function test_primary_db_inventory_preserves_inactive_provider_and_full_reports(): void {
		$this->fixtures();
		$inventory = MigrationInventory::inspect();
		$this->assertSame( 'ok', $inventory['status'] );
		$this->assertCount( 2, $inventory['documents'] );
		$mail = $inventory['documents'][Repository::MAIL . "\0unknown.inactive"]['document'];
		$this->assertSame( 'Original subject', $mail['subject'] );
		$this->assertSame( 'old-provider', $mail['base_fingerprint'] );
		$this->assertSame( '2026-01-01 00:00:00', $mail['modified_at'] );
		$this->assertTrue( $mail['customized'] );
		$reports = $inventory['documents'][Repository::REPORTS . "\0maintenance"]['document'];
		$this->assertSame( ReportBranding::settings_defaults(), $reports['branding'] );
		$this->assertSame( MaintenanceTemplate::defaults(), $reports['composer']['maintenance'] );
		$this->assertArrayNotHasKey( 'retention_days', $reports );
		$this->assertArrayNotHasKey( 'email_alerts', $reports );
	}

	public function test_private_backup_retains_exact_option_bytes_and_blocks_stale_source(): void {
		$this->fixtures();
		$inventory = MigrationInventory::inspect();
		$this->assertSame( 'ok', $inventory['status'] );
		$this->assertSame( 'ready', MigrationBackup::ensure( $inventory )['status'] );
		$backup = MigrationBackup::read_verified();
		$this->assertSame( 'found', $backup['status'] );
		$this->assertSame( $inventory['source'], $backup['source'] );
		$this->assertSame( 'ready', MigrationBackup::ensure( $inventory )['status'] );

		$current = get_option( TemplateRepository::OPTION );
		$current['unknown.inactive']['subject'] = 'Changed while preparation pending';
		$this->assertTrue( update_option( TemplateRepository::OPTION, $current, false ) );
		$fresh = MigrationInventory::inspect();
		$this->assertSame( 'ok', $fresh['status'] );
		$this->assertNotSame( $inventory['fingerprint'], $fresh['fingerprint'] );
		$this->assertSame( 'source_changed', MigrationBackup::ensure( $fresh )['status'] );
	}

	public function test_noncanonical_reports_composer_is_rejected_without_normalizing(): void {
		$this->fixtures();
		$settings = get_option( MigrationInventory::SETTINGS_OPTION );
		$settings['reports']['composer']['maintenance']['blocks'][] = [
			'id' => 'unknown', 'type' => 'unknown', 'enabled' => true, 'settings' => [],
		];
		$this->assertTrue( update_option( MigrationInventory::SETTINGS_OPTION, $settings, false ) );
		$this->assertSame( 'invalid_source', MigrationInventory::inspect()['status'] );
		$this->assertSame( $settings, get_option( MigrationInventory::SETTINGS_OPTION ) );
	}

	public function test_no_active_cutover_marker_is_defined_or_written_by_inventory(): void {
		$this->fixtures();
		$this->assertSame( 'ok', MigrationInventory::inspect()['status'] );
		$this->assertFalse( defined( 'CB_CORE_DESIGN_DOCS_ACTIVE' ) );
		$this->assertFalse( has_action( 'init', [ Migration::class, 'prepare' ] ) );
		$this->assertNull( get_option( Migration::PREPARED_OPTION, null ) );
	}
}
