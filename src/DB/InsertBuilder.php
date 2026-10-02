<?php
declare(strict_types=1);
/**
 * InsertBuilder - fluent INSERT construction for Core Blueprint tables.
 *
 * Two modes:
 *
 *   Single-row - `values( [ col => val, … ] )->execute()` returns the new
 *   row ID. Delegates to `$wpdb->insert()` with auto-format detection.
 *
 *   Batch - `values_batch( [ [col => val, …], [col => val, …], … ] )->execute()`
 *   composes one `INSERT INTO ... VALUES (...), (...), …` prepared statement
 *   and returns the number of rows inserted. One round-trip instead of N,
 *   meaningful at scale (e.g. bulk-inserting 1000 invoice line-items).
 *
 * Column identifiers validated as bare names (no aliases - INSERT writes
 * into a single table). Values typed automatically: `%d` for integers,
 * `%s` for everything else.
 *
 * Usage (single row):
 *
 *     $id = ( new InsertBuilder( AuditLog::table() ) )
 *         ->values( [
 *             'event_type' => 'user.login',
 *             'user_id'    => $user_id,
 *         ] )
 *         ->execute();
 *
 * Usage (batch):
 *
 *     $rows_inserted = ( new InsertBuilder( SiteTags::table() ) )
 *         ->values_batch( [
 *             [ 'site_id' => 42, 'tag_id' => 1 ],
 *             [ 'site_id' => 42, 'tag_id' => 5 ],
 *             [ 'site_id' => 42, 'tag_id' => 12 ],
 *         ] )
 *         ->execute();
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DB;

defined( 'ABSPATH' ) || exit;

final class InsertBuilder {

	private string $table;

	/** @var array<string, mixed> Single-row values set via values(). */
	private array $values = [];

	/** @var array<int, array<string, mixed>> Multiple rows set via values_batch(). */
	private array $batch = [];

	public function __construct( string $table ) {
		$this->validate_table( $table );
		$this->table = $table;
	}

	/**
	 * Set the column/value pairs to insert. Each key is validated as a
	 * column identifier (bare `column_name` only - no aliases in INSERTs).
	 * Calling twice merges: later keys override earlier ones.
	 *
	 * Mutually exclusive with {@see values_batch()} - if batch values are
	 * already set, this call clears them. Use one or the other per builder
	 * instance.
	 *
	 * @param array<string, mixed> $values Column → value map.
	 */
	public function values( array $values ): self {
		foreach ( $values as $column => $_v ) {
			$this->validate_bare_column( (string) $column );
		}
		$this->values = array_merge( $this->values, $values );
		$this->batch  = []; // Clear batch state when switching to single-row.
		return $this;
	}

	/**
	 * Insert multiple rows in a single `INSERT INTO ... VALUES (...), (...)`
	 * statement. One round-trip instead of N.
	 *
	 * All rows must have **identical** column sets - the first row defines
	 * the column order, subsequent rows must match. Mismatched rows are
	 * rejected with `_doing_it_wrong()` and the batch is abandoned.
	 *
	 * Mutually exclusive with {@see values()}.
	 *
	 * @param array<int, array<string, mixed>> $rows List of column→value maps.
	 */
	public function values_batch( array $rows ): self {
		if ( empty( $rows ) ) {
			return $this;
		}

		// First row defines the column set. Validate each column identifier once.
		$first_row = reset( $rows );
		if ( ! is_array( $first_row ) || empty( $first_row ) ) {
			_doing_it_wrong( __METHOD__, 'InsertBuilder::values_batch() requires an array of non-empty row arrays', '1.0.16' );
			return $this;
		}
		$expected_columns = array_keys( $first_row );
		foreach ( $expected_columns as $column ) {
			$this->validate_bare_column( (string) $column );
		}

		// All subsequent rows must share the same column set, in any order.
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				_doing_it_wrong( __METHOD__, 'InsertBuilder::values_batch() row ' . (int) $i . ' is not an array', '1.0.16' );
				return $this;
			}
			$row_columns = array_keys( $row );
			if ( count( $row_columns ) !== count( $expected_columns )
				|| array_diff( $expected_columns, $row_columns ) !== [] ) {
				_doing_it_wrong( __METHOD__, 'InsertBuilder::values_batch() row ' . (int) $i . ' has mismatched columns', '1.0.16' );
				return $this;
			}
		}

		$this->batch  = array_values( $rows );
		$this->values = []; // Clear single-row state when switching to batch.
		return $this;
	}

	/**
	 * Execute the INSERT.
	 *
	 * Single-row mode (via `values()`): returns the new row ID, or 0 on failure.
	 * Batch mode (via `values_batch()`): returns the number of rows inserted,
	 * or 0 on failure. Note that batch mode does NOT return insert IDs for
	 * individual rows - `$wpdb->insert_id` for a multi-VALUES INSERT is
	 * MySQL-implementation-defined (typically the first new ID, not all).
	 * If you need per-row IDs, use single-row `values()` in a loop.
	 */
	public function execute(): int {
		global $wpdb;

		if ( ! empty( $this->batch ) ) {
			return $this->execute_batch();
		}

		if ( empty( $this->values ) ) {
			_doing_it_wrong( __METHOD__, 'InsertBuilder::execute() called with no values set', '1.0.16' );
			return 0;
		}

		$result = $wpdb->insert( $this->table, $this->values );
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Execute a batch INSERT. Composes one prepared statement of the form
	 *   INSERT INTO {table} ({cols}) VALUES (%s, %d, …), (%s, %d, …), …
	 * where placeholders match each row's values in column order.
	 *
	 * Column order is determined from the first row's keys; all rows are
	 * normalised to that order before placeholders are generated.
	 */
	private function execute_batch(): int {
		global $wpdb;

		$first_row = $this->batch[0];
		$columns   = array_keys( $first_row );

		// Build placeholder groups: one group per row, one placeholder per column.
		$row_placeholder_groups = [];
		$flat_params            = [];
		foreach ( $this->batch as $row ) {
			$row_placeholders = [];
			foreach ( $columns as $col ) {
				$value              = $row[ $col ];
				$row_placeholders[] = is_int( $value ) ? '%d' : '%s';
				$flat_params[]      = $value;
			}
			$row_placeholder_groups[] = '(' . implode( ', ', $row_placeholders ) . ')';
		}

		$columns_sql = '`' . implode( '`, `', $columns ) . '`';
		$sql         = "INSERT INTO {$this->table} ({$columns_sql}) VALUES "
					 . implode( ', ', $row_placeholder_groups );

		$result = $wpdb->query( $wpdb->prepare( $sql, $flat_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Accept only bare column identifiers - no dots, no aliases. INSERTs
	 * write into a single table; a qualified column like `t.column` would
	 * be nonsensical here.
	 */
	private function validate_bare_column( string $column ): void {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $column ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid column identifier in INSERT: ' . esc_html( $column ), '1.0.16' );
			throw new \InvalidArgumentException( 'Invalid SQL column identifier.' );
		}
	}

	private function validate_table( string $table ): void {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid INSERT table identifier: ' . esc_html( $table ), '1.0.0' );
			throw new \InvalidArgumentException( 'Invalid SQL table identifier.' );
		}
	}
}
