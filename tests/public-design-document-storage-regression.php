<?php
declare(strict_types=1);

/**
 * Standalone, SOURCE-ONLY CAS state machine regression (no WP or MariaDB).
 * Real InnoDB two-worker evidence is a separate authorized Level 2 gate.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags | JSON_THROW_ON_ERROR );
}

final class DesignDocumentFakeDb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public string $engine = 'InnoDB';
	public bool $bad_index = false;
	public bool $fail_write = false;
	public bool $bad_readback = false;
	public mixed $before_update = null;
	public array $rows = [];
	private array $statements = [];

	public function prepare( string $sql, mixed ...$args ): string {
		$id = 'statement:' . count( $this->statements );
		$this->statements[$id] = [ $sql, $args ];
		return $id;
	}

	public function get_row( string $statement, mixed $format = null ): ?array {
		[ $sql, $args ] = $this->statements[$statement];
		if ( str_contains( $sql, 'information_schema.TABLES' ) ) {
			return [ 'engine' => $this->engine ];
		}
		if ( str_contains( $sql, 'SELECT identity_hash' ) ) {
			$row = $this->rows[$args[0]][$args[1]] ?? null;
			if ( null !== $row && $this->bad_readback ) {
				$row['revision'] = (string) ( (int) $row['revision'] + 1 );
			}
			return $row;
		}
		throw new RuntimeException( 'Unexpected source-only SELECT.' );
	}

	public function get_results( string $statement, mixed $format = null ): array {
		[ $sql ] = $this->statements[$statement];
		if ( str_contains( $sql, 'information_schema.COLUMNS' ) ) {
			return array_map( static fn ( array $v ): array => [
				'column_name' => $v[0], 'data_type' => $v[1], 'column_type' => $v[2],
				'nullable' => 'NO', 'max_length' => $v[3],
			], [
				[ 'id', 'bigint', 'bigint(20) unsigned', null ],
				[ 'identity_hash', 'char', 'char(64)', 64 ],
				[ 'document_type', 'varchar', 'varchar(48)', 48 ],
				[ 'document_key', 'varchar', 'varchar(191)', 191 ],
				[ 'payload_schema', 'smallint', 'smallint unsigned', null ],
				[ 'revision', 'bigint', 'bigint(20) unsigned', null ],
				[ 'document_json', 'longtext', 'longtext', null ],
				[ 'created_at', 'datetime', 'datetime', null ],
				[ 'updated_at', 'datetime', 'datetime', null ],
			] );
		}
		if ( str_contains( $sql, 'information_schema.STATISTICS' ) ) {
			return [
				[ 'index_name' => 'PRIMARY', 'non_unique' => 0, 'seq' => 1, 'column_name' => 'id' ],
				[ 'index_name' => 'identity_hash', 'non_unique' => $this->bad_index ? 1 : 0, 'seq' => 1, 'column_name' => 'identity_hash' ],
			];
		}
		throw new RuntimeException( 'Unexpected source-only metadata query.' );
	}

	public function query( string $statement ): int|false {
		[ $sql, $args ] = $this->statements[$statement];
		if ( $this->fail_write ) {
			return false;
		}
		if ( str_starts_with( $sql, 'INSERT INTO' ) ) {
			[ $table, $hash, $type, $key, $json ] = $args;
			if ( isset( $this->rows[$table][$hash] ) ) {
				return false;
			}
			$this->rows[$table][$hash] = [
				'identity_hash' => $hash, 'document_type' => $type,
				'document_key' => $key, 'payload_schema' => '1',
				'revision' => '1', 'document_json' => $json,
			];
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE' ) ) {
			[ $table, $json, $now, $hash, $type, $key, $expected ] = $args;
			if ( is_callable( $this->before_update ) ) {
				$callback = $this->before_update;
				$this->before_update = null;
				$callback( $this, $table, $hash );
			}
			$row = $this->rows[$table][$hash] ?? null;
			if ( null === $row || $type !== $row['document_type'] || $key !== $row['document_key']
				|| $expected !== $row['revision'] ) {
				return 0;
			}
			$this->rows[$table][$hash]['revision'] = (string) ( (int) $expected + 1 );
			$this->rows[$table][$hash]['document_json'] = $json;
			return 1;
		}
		throw new RuntimeException( 'Unexpected source-only write.' );
	}
}

require_once dirname( __DIR__ ) . '/src/Design/Document/Schema.php';
require_once dirname( __DIR__ ) . '/src/Design/Document/Repository.php';

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;

function check_design_document( bool $pass, string $label ): void {
	if ( ! $pass ) {
		throw new RuntimeException( 'Design document source regression failed: ' . $label );
	}
}

$wpdb = new DesignDocumentFakeDb();
$mail = [ 'subject' => 'Saved subject', 'project' => [ 'root' => [ 'type' => 'mail.root' ] ] ];
$other = [ 'subject' => 'Other', 'project' => [ 'root' => [ 'type' => 'mail.root' ] ] ];
$report = [ 'branding' => [ 'accent_color' => '#0064c8' ], 'composer' => [ 'blocks' => [] ] ];

check_design_document( 'wp_cb_design_documents' === Schema::table_name(), 'site-scoped table name' );
check_design_document( Schema::healthy(), 'source schema metadata' );
check_design_document( Repository::valid_identity( Repository::MAIL, 'wordpress.password-reset' ), 'literal Mail template ID' );
check_design_document( ! Repository::valid_identity( Repository::MAIL, 'wrong/provider' ), 'reject untrusted identity' );
check_design_document( ! Repository::valid_identity( Repository::REPORTS, 'other' ), 'Reports singleton scope' );
check_design_document( Repository::valid_revision( '9007199254740993' ), 'BIGINT above JS safe integer' );
check_design_document( ! Repository::valid_revision( '09' ) && ! Repository::valid_revision( '1e5' ), 'canonical decimal revision' );
check_design_document( ! Repository::valid_revision( '9223372036854775808' ), 'overflow rejected' );
check_design_document( 'invalid' === Repository::create( Repository::MAIL, 'a.b', '1', $mail )['status'], 'create must require revision zero' );
check_design_document( 'saved' === Repository::create( Repository::MAIL, 'a.b', '0', $mail )['status'], 'first create succeeds' );
check_design_document( 'conflict' === Repository::create( Repository::MAIL, 'a.b', '0', $mail )['status'], 'duplicate key conflict' );
check_design_document( 'saved' === Repository::create( Repository::MAIL, 'c.d', '0', $other )['status'], 'independent Mail row' );
check_design_document( 'saved' === Repository::create( Repository::REPORTS, 'maintenance', '0', $report )['status'], 'Reports singleton create' );
check_design_document( 'saved' === Repository::save( Repository::MAIL, 'a.b', '1', $mail )['status'], 'same-value save advances revision' );
check_design_document( 'conflict' === Repository::save( Repository::MAIL, 'a.b', '1', $mail )['status'], 'stale update rejected' );
check_design_document( '2' === Repository::get( Repository::MAIL, 'a.b' )['revision'], 'lossless revision readback' );
check_design_document( '1' === Repository::get( Repository::MAIL, 'c.d' )['revision'], 'other Mail row unchanged' );
check_design_document( 'saved' === Repository::reset( Repository::MAIL, 'a.b', '2', $mail )['status'], 'Reset advances same identity' );
check_design_document( 'conflict' === Repository::save( Repository::MAIL, 'a.b', '2', $other )['status'], 'Reset ABA blocked' );
$wpdb->before_update = static function ( DesignDocumentFakeDb $db, string $table, string $hash ): void {
	$db->rows[$table][$hash]['revision'] = '4';
};
check_design_document( 'conflict' === Repository::save( Repository::MAIL, 'a.b', '3', $other )['status'], 'interleaved writer wins atomically' );
$wpdb->fail_write = true;
check_design_document( 'error' === Repository::save( Repository::MAIL, 'a.b', '4', $other )['status'], 'SQL error is not success' );
$wpdb->fail_write = false;
$wpdb->bad_readback = true;
check_design_document( 'uncertain' === Repository::save( Repository::MAIL, 'a.b', '4', $other )['status'], 'failed readback cannot acknowledge' );
$wpdb->bad_readback = false;
$wpdb->bad_index = true;
check_design_document( 'unavailable' === Repository::get( Repository::MAIL, 'a.b' )['status'], 'missing unique constraint fails closed' );
$wpdb->bad_index = false;
$wpdb->engine = 'MyISAM';
check_design_document( 'unavailable' === Repository::save( Repository::REPORTS, 'maintenance', '1', $report )['status'], 'non-InnoDB fails closed' );
$wpdb->engine = 'InnoDB';
$wpdb->prefix = 'wp_2_';
check_design_document( 'missing' === Repository::get( Repository::MAIL, 'a.b' )['status'], 'second blog has distinct rows' );
check_design_document( 'saved' === Repository::create( Repository::MAIL, 'a.b', '0', $mail )['status'], 'second blog can create same key' );
check_design_document( '3' !== Repository::get( Repository::MAIL, 'a.b' )['revision'], 'new blog has independent revision' );
echo "Base Design Document source-only CAS regression: PASS\n";
