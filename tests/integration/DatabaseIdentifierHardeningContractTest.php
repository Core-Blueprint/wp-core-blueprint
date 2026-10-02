<?php
declare(strict_types=1);

use CB\Core\DB\DeleteBuilder;
use CB\Core\DB\InsertBuilder;
use CB\Core\DB\QueryBuilder;
use CB\Core\DB\UpdateBuilder;

defined( 'ABSPATH' ) || exit;

final class DatabaseIdentifierHardeningContractTest extends WP_UnitTestCase {
	private string $table;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->table = $wpdb->prefix . 'cb_identifier_hardening_fixture';

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table ) );
		$wpdb->query(
			$wpdb->prepare(
				'CREATE TABLE %i (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					name VARCHAR(191) NOT NULL,
					status VARCHAR(32) NOT NULL,
					PRIMARY KEY (id)
				)',
				$this->table
			)
		);
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table ) );

		parent::tearDown();
	}

	public function test_identifier_placeholder_capability_is_available(): void {
		global $wpdb;
		self::assertTrue( $wpdb->has_cap( 'identifier_placeholders' ) );
	}

	public function test_builders_execute_with_bound_identifiers_and_values(): void {
		$inserted = ( new InsertBuilder( $this->table ) )
			->values_batch( [
				[ 'name' => 'Alpha', 'status' => 'open' ],
				[ 'name' => 'Beta', 'status' => 'open' ],
			] )
			->execute();

		self::assertSame( 2, $inserted );

		$rows = ( new QueryBuilder( $this->table ) )
			->order_by_asc( 'id' )
			->get_rows( ARRAY_A );

		self::assertCount( 2, $rows );
		self::assertSame( 'Alpha', $rows[0]['name'] );
		self::assertSame( 'Beta', $rows[1]['name'] );

		$updated = ( new UpdateBuilder( $this->table ) )
			->set( [ 'status' => 'closed' ] )
			->int_equals_if_set( 'id', (int) $rows[0]['id'] )
			->execute();

		self::assertSame( 1, $updated );

		$deleted = ( new DeleteBuilder( $this->table ) )
			->int_equals_if_set( 'id', (int) $rows[1]['id'] )
			->execute();

		self::assertSame( 1, $deleted );
		self::assertSame( 1, ( new QueryBuilder( $this->table ) )->count() );
	}

	public function test_builders_reject_unsafe_table_identifiers(): void {
		foreach ( [
			QueryBuilder::class,
			InsertBuilder::class,
			UpdateBuilder::class,
			DeleteBuilder::class,
		] as $builder_class ) {
			try {
				new $builder_class( 'wp_fixture; DROP TABLE wp_users' );
				self::fail( $builder_class . ' accepted an unsafe table identifier.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertSame( 'Invalid SQL table identifier.', $error->getMessage() );
			}
		}
	}

	public function test_write_builders_reject_unsafe_column_identifiers(): void {
		try {
			( new InsertBuilder( $this->table ) )->values( [ 'name) VALUES ("x"); --' => 'bad' ] );
			self::fail( 'InsertBuilder accepted an unsafe column identifier.' );
		} catch ( InvalidArgumentException $error ) {
			self::assertSame( 'Invalid SQL column identifier.', $error->getMessage() );
		}

		try {
			( new UpdateBuilder( $this->table ) )->set( [ 'status = "closed"; --' => 'bad' ] );
			self::fail( 'UpdateBuilder accepted an unsafe column identifier.' );
		} catch ( InvalidArgumentException $error ) {
			self::assertSame( 'Invalid SQL column identifier.', $error->getMessage() );
		}
	}
}
