<?php
declare(strict_types=1);

/**
 * G1d-A2.2 isolated WordPress/MariaDB transactional conformance.
 *
 * Test-only WP-CLI eval-file fixture. Runs OUTSIDE the WP_UnitTestCase
 * transaction, after the canonical isolated CLI WordPress installation.
 * Never invoke against real users, customer data, staging or production.
 *
 * AUTHORED under source-only test GO; running requires a distinct DB Level 2 GO.
 */

use CoreBlueprint\Core\Design\Document\Migration;
use CoreBlueprint\Core\Design\Document\MigrationBackup;
use CoreBlueprint\Core\Design\Document\MigrationInventory;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;
use CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate;
use CoreBlueprint\Core\Reports\ReportBranding;

global $wpdb;

function cb_a22_assert( bool $pass, string $case ): void {
	if ( ! $pass ) {
		throw new RuntimeException( 'A2.2 isolated migration gate failed: ' . $case );
	}
}

/** Return only raw primary-DB option row, not a possibly stale WP cache hit. */
function cb_a22_raw_option( string $name ): ?array {
	global $wpdb;
	$wpdb->last_error = '';
	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 1',
		$wpdb->options, $name
	), ARRAY_A );
	cb_a22_assert( '' === (string) $wpdb->last_error, 'raw option SELECT' );
	return is_array( $row ) ? $row : null;
}

function cb_a22_write_option( string $name, mixed $value ): void {
	global $wpdb;
	$existing = cb_a22_raw_option( $name );
	$raw = maybe_serialize( $value );
	if ( null === $existing ) {
		$changed = $wpdb->query( $wpdb->prepare(
			'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
			$wpdb->options, $name, $raw, 'no'
		) );
	} else {
		$changed = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET option_value = %s WHERE option_name = %s',
			$wpdb->options, $raw, $name
		) );
	}
	cb_a22_assert( 1 === $changed, 'isolated fixture option write' );
}

function cb_a22_count(): int {
	global $wpdb;
	$wpdb->last_error = '';
	$count = $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE document_type IN (%s, %s)',
		Schema::table_name(), Repository::MAIL, Repository::REPORTS
	) );
	cb_a22_assert( '' === (string) $wpdb->last_error && null !== $count, 'target table COUNT' );
	return (int) $count;
}

/** Test fixture reset, permitted ONLY in the exclusively guarded fresh CLI slot. */
function cb_a22_clear_preparation(): void {
	global $wpdb;
	foreach ( [ Migration::PREPARED_OPTION, MigrationBackup::OPTION ] as $key ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $key ) );
		cb_a22_assert( null === cb_a22_raw_option( $key ), 'fixture marker removal' );
	}
	$wpdb->query( $wpdb->prepare(
		'DELETE FROM %i WHERE document_type IN (%s, %s)',
		Schema::table_name(), Repository::MAIL, Repository::REPORTS
	) );
	cb_a22_assert( 0 === cb_a22_count(), 'fixture target reset' );
}

/** Verify only test-created content has reached the dedicated table. */
function cb_a22_doc( string $type, string $key ): array {
	$read = Repository::get( $type, $key );
	cb_a22_assert( 'found' === $read['status'], 'expected prepared document exists' );
	cb_a22_assert( '1' === $read['revision'], 'first prepared revision is exact string 1' );
	return $read['document'];
}

$slot = getenv( 'CB_BASE_TEST_SLOT' );
$target = getenv( 'WP_DB_NAME' );
$root = (string) getenv( 'WP_CORE_DIR' );
$cli_url = (string) get_option( 'siteurl', '' );
cb_a22_assert(
	is_string( $slot ) && 1 === preg_match( '/^g1d_a22_[a-z0-9_]+$/D', $slot )
	&& $target === 'core_blueprint_base_' . $slot . '_test'
	&& defined( 'DB_NAME' ) && DB_NAME === $target
	&& is_string( $root ) && str_starts_with( $root, '/tmp/core-blueprint-base-' . $slot . '/' )
	&& $wpdb->prefix === (string) getenv( 'CB_CLI_TABLE_PREFIX' )
	&& $wpdb->prefix === 'cbcli_'
	&& getenv( 'WP_DB_HOST' ) === '127.0.0.1:3307'
	&& defined( 'WP_CLI' ) && WP_CLI === true
	&& str_contains( $cli_url, 'core-blueprint-cli.test' ),
	'fresh isolated CLI database and WP installation guard'
);
cb_a22_assert( in_array( 'core-blueprint/core-blueprint.php', (array) get_option( 'active_plugins', [] ), true ), 'Base active in test WP site' );
cb_a22_assert( Schema::healthy(), 'A2.1 document table InnoDB health' );
cb_a22_assert( 0 === cb_a22_count(), 'dedicated table initially empty' );
cb_a22_assert( null === cb_a22_raw_option( Migration::PREPARED_OPTION ), 'prepared marker absent' );
cb_a22_assert( null === cb_a22_raw_option( MigrationBackup::OPTION ), 'backup absent' );
// Never adopt/remove an unexpected trigger from an earlier failed test run.
$wpdb->last_error = '';
$trigger_count = $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	'cb_a22_migration_abort_reports'
) );
cb_a22_assert( '' === (string) $wpdb->last_error && '0' === (string) $trigger_count, 'no preexisting SQL fault trigger' );

$old_mail = cb_a22_raw_option( TemplateRepository::OPTION );
$old_settings = cb_a22_raw_option( MigrationInventory::SETTINGS_OPTION );
$trigger = 'cb_a22_migration_abort_reports';
// A test trigger must never be left behind, including on failure.
$trigger_created = false;

try {
	$branding = ReportBranding::settings_defaults();
	$branding['provider_name'] = 'Fixture Maintenance Studio';
	$branding['accent_color'] = '#224466';
	$composer = MaintenanceTemplate::defaults();
	$settings = [
		'reports' => [
			'branding' => $branding,
			'composer' => [ 'maintenance' => $composer ],
			'retention_days' => 721,
			'email_alerts' => [ 'generation_failed' => false ],
		],
		'other' => [ 'persist' => 'unchanged' ],
	];
	$mail = [
		'known.template' => [
			'subject' => 'A2.2 public template',
			'project' => [ 'root' => [ 'type' => 'mail.root' ] ],
			'base_fingerprint' => 'test-a',
			'modified_at' => '2026-10-01 00:00:00',
		],
		'unknown.inactive-provider' => [
			'subject' => 'A2.2 inactive template',
			'project' => [ 'root' => [ 'type' => 'mail.root' ] ],
			'base_fingerprint' => 'test-b',
		],
	];

	cb_a22_write_option( TemplateRepository::OPTION, $mail );
	cb_a22_write_option( MigrationInventory::SETTINGS_OPTION, $settings );
	$original_mail = cb_a22_raw_option( TemplateRepository::OPTION );
	$original_settings = cb_a22_raw_option( MigrationInventory::SETTINGS_OPTION );

	// Recovery exists while no target row/marker exists: restartable after a
	// crash between backup and START TRANSACTION.
	$inventory = MigrationInventory::inspect();
	cb_a22_assert( 'ok' === $inventory['status'] && 3 === count( $inventory['documents'] ), 'complete raw inventory' );
	cb_a22_assert( 'ready' === MigrationBackup::ensure( $inventory )['status'], 'verified recovery snapshot before prepare' );
	cb_a22_assert( 0 === cb_a22_count(), 'backup did not stage documents' );
	cb_a22_assert( null === cb_a22_raw_option( Migration::PREPARED_OPTION ), 'backup did not claim prepare' );
	cb_a22_assert( 'prepared' === Migration::prepare()['status'], 'real SQL transaction/commit' );
	cb_a22_assert( 'prepared' === Migration::freshness()['status'], 'complete readback receipt' );
	cb_a22_assert( 'prepared' === Migration::prepare()['status'], 'idempotent second invocation' );
	cb_a22_assert( 3 === cb_a22_count(), 'exact document count' );
	cb_a22_assert( cb_a22_doc( Repository::MAIL, 'known.template' )['subject'] === 'A2.2 public template', 'Mail subject unchanged' );
	$inactive = cb_a22_doc( Repository::MAIL, 'unknown.inactive-provider' );
	cb_a22_assert( $inactive['subject'] === 'A2.2 inactive template' && $inactive['base_fingerprint'] === 'test-b', 'inactive provider preserved' );
	$report = cb_a22_doc( Repository::REPORTS, 'maintenance' );
	cb_a22_assert( $report['branding'] === $branding && $report['composer']['maintenance'] === $composer, 'Reports full raw fidelity' );
	cb_a22_assert( cb_a22_raw_option( TemplateRepository::OPTION ) === $original_mail, 'legacy Mail option unchanged' );
	cb_a22_assert( cb_a22_raw_option( MigrationInventory::SETTINGS_OPTION ) === $original_settings, 'legacy Reports and unrelated settings unchanged' );
	$backup = MigrationBackup::read_verified();
	cb_a22_assert( 'found' === $backup['status'] && $backup['source'] === $inventory['source'], 'privacy recovery exact-byte readback' );
	cb_a22_assert( in_array( cb_a22_raw_option( MigrationBackup::OPTION )['autoload'], [ 'no', 'off' ], true ), 'backup not autoloaded' );
	cb_a22_assert( in_array( cb_a22_raw_option( Migration::PREPARED_OPTION )['autoload'], [ 'no', 'off' ], true ), 'prepared marker not autoloaded' );
	echo "[A2.2] real prepare, idempotence, recovery and full data roundtrip PASS\n";

	// Existing target cannot be claimed, and a second provider's row cannot
	// be dropped from the complete target-set check.
	$updated = $mail;
	$updated['known.template']['subject'] = 'post-prepare old writer';
	cb_a22_write_option( TemplateRepository::OPTION, $updated );
	cb_a22_assert( 'source_changed' === Migration::freshness()['status'], 'legacy writer invalidates prepared source' );
	cb_a22_assert( 'source_changed' === Migration::prepare()['status'], 'no implicit re-preparation' );
	cb_a22_write_option( TemplateRepository::OPTION, $mail );
	$wpdb->query( $wpdb->prepare(
		'UPDATE %i SET revision = %d WHERE identity_hash = %s',
		Schema::table_name(), 9, hash( 'sha256', Repository::MAIL . "\0known.template" )
	) );
	cb_a22_assert( 'target_changed' === Migration::freshness()['status'], 'tampered target revision rejected' );
	cb_a22_assert( null === cb_a22_raw_option( 'cb_core_design_docs_cutover' ), 'no active cutover marker' );
	echo "[A2.2] stale legacy fingerprint and tampered target fail-closed PASS\n";

	cb_a22_clear_preparation();
	// Pre-existing orphaned table documents prohibit adopting a partial set.
	cb_a22_assert(
		'saved' === Repository::create(
			Repository::MAIL, 'orphan.target', '0',
			[ 'subject' => 'Orphan', 'project' => [ 'root' => [ 'type' => 'mail.root' ] ] ]
		)['status'],
		'test orphan target setup'
	);
	cb_a22_assert( 'conflict' === Migration::prepare()['status'], 'orphan target fails before prepare' );
	cb_a22_assert( null === cb_a22_raw_option( Migration::PREPARED_OPTION ), 'no marker after orphan conflict' );
	echo "[A2.2] preexisting target-row conflict PASS\n";

	cb_a22_clear_preparation();
	// Inject a deterministic SQL failure on the REPORTS singleton, after both
	// Mail rows have been inserted. Real InnoDB must roll all rows back.
	$sql = "CREATE TRIGGER {$trigger} BEFORE INSERT ON " . Schema::table_name()
		. " FOR EACH ROW BEGIN IF NEW.document_type = 'base.maintenance-report-design' "
		. "THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CB_A22_FIXTURE_ABORT'; "
		. 'END IF; END';
	cb_a22_assert( false !== $wpdb->query( $sql ), 'test-only abort trigger created' );
	$trigger_created = true;
	$failed = Migration::prepare();
	cb_a22_assert( 'error' === $failed['status'], 'midtransaction insert fault rejected' );
	cb_a22_assert( 0 === cb_a22_count(), 'partial inserts fully rolled back' );
	cb_a22_assert( null === cb_a22_raw_option( Migration::PREPARED_OPTION ), 'no prepared marker after rollback' );
	cb_a22_assert( 'found' === MigrationBackup::read_verified()['status'], 'recovery backup survives rollback' );
	cb_a22_assert( false !== $wpdb->query( "DROP TRIGGER {$trigger}" ), 'test abort trigger dropped' );
	$trigger_created = false;
	cb_a22_assert( 'prepared' === Migration::prepare()['status'], 'retry after genuine rollback succeeds' );
	cb_a22_assert( 'prepared' === Migration::freshness()['status'], 'retry fully verified' );
	echo "[A2.2] real SQL mid-migration rollback, durable backup and safe retry PASS\n";

	// Read-only assertion: the migration never switched readers or the source.
	cb_a22_assert( null === cb_a22_raw_option( 'cb_core_design_docs_cutover' ), 'no active marker' );
	cb_a22_assert( cb_a22_raw_option( TemplateRepository::OPTION ) === $original_mail, 'live Mail source still unchanged' );
	cb_a22_assert( cb_a22_raw_option( MigrationInventory::SETTINGS_OPTION ) === $original_settings, 'live Reports source still unchanged' );
	echo "[A2.2] final prepare-only conformance PASS\n";
} finally {
	if ( $trigger_created ) {
		cb_a22_assert( false !== $wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" ), 'test fault trigger removed on failure' );
	}
	// Fixture-only cleanup, bounded to a proven new local test database and
	// prefix. Never remove arbitrary pre-existing recovery or customer data.
	cb_a22_clear_preparation();
	foreach ( [ TemplateRepository::OPTION => $old_mail, MigrationInventory::SETTINGS_OPTION => $old_settings ] as $name => $old ) {
		if ( null === $old ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		} else {
			$wpdb->query( $wpdb->prepare(
				'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s',
				$wpdb->options, $old['option_value'], $old['autoload'], $name
			) );
		}
		cb_a22_assert( cb_a22_raw_option( $name ) === $old, 'exact fixture source restored' );
	}
}
