<?php
declare(strict_types=1);
/**
 * UpdateBuilder - fluent UPDATE construction with required WHERE.
 *
 * Wraps `$wpdb->update()` with a chainable API, strict column validation,
 * and - crucially - a hard requirement that either a WHERE clause or an
 * explicit `match_all()` call has been made before `execute()`. This
 * closes the classical "forgot the WHERE" bug category where an
 * accidental full-table update wipes production data.
 *
 * Consumes the shared {@see WhereClauses} trait for its filter builders,
 * so the API surface matches {@see QueryBuilder} for consistency.
 *
 * Usage (targeted update):
 *
 *     $rows = ( new UpdateBuilder( Sites::table() ) )
 *         ->set( [ 'status' => 'inactive' ] )
 *         ->int_equals_if_set( 'id', $site_id )
 *         ->execute();
 *
 * Usage (catch-all - explicit escape for the rare legitimate case):
 *
 *     $rows = ( new UpdateBuilder( Cache::table() ) )
 *         ->set( [ 'expired' => 1 ] )
 *         ->match_all()
 *         ->execute();
 *
 * Return of `execute()` is the number of rows affected, or 0 on failure -
 * matching `$wpdb->update` semantics.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DB;

defined( 'ABSPATH' ) || exit;

final class UpdateBuilder {

	use WhereClauses;

	private string $table;

	/** @var array<string, mixed> */
	private array $set_values = [];

	/** @var string[] WHERE clauses joined with AND. Consumed by the trait. */
	private array $where = [];

	/** @var string[] Column identifier per $where entry (parallel to $where, tracked by trait). */
	private array $where_columns = [];

	/** @var mixed[] Positional parameters matched to `$wpdb->prepare()` placeholders. Consumed by the trait. */
	private array $params = [];

	/** Explicit opt-in for unscoped updates. Prevents accidental full-table writes. */
	private bool $match_all_acknowledged = false;

	public function __construct( string $table ) {
		$this->validate_table( $table );
		$this->table = $table;
	}

	/**
	 * Columns to update. Like INSERT's {@see InsertBuilder::values()},
	 * each column key is validated as a bare identifier. Values are
	 * passed through `$wpdb` with its own format detection.
	 *
	 * @param array<string, mixed> $values Column → new-value map.
	 */
	public function set( array $values ): self {
		foreach ( $values as $column => $_v ) {
			$this->validate_bare_column( (string) $column );
		}
		$this->set_values = array_merge( $this->set_values, $values );
		return $this;
	}

	/**
	 * Explicit acknowledgment that this UPDATE is intended to affect
	 * every row in the table. Required if no WHERE helpers are called -
	 * without it, `execute()` refuses to run.
	 */
	public function match_all(): self {
		$this->match_all_acknowledged = true;
		return $this;
	}

	/**
	 * Execute the UPDATE. Returns the number of rows affected, or 0 on
	 * failure. Fails closed:
	 *
	 *   - No `set()` called → _doing_it_wrong + return 0.
	 *   - No WHERE clauses AND no `match_all()` → _doing_it_wrong + return 0.
	 *     This is the rock-solid safeguard: a forgotten WHERE never
	 *     produces a full-table overwrite.
	 */
	public function execute(): int {
		global $wpdb;

		if ( empty( $this->set_values ) ) {
			_doing_it_wrong( __METHOD__, 'UpdateBuilder::execute() called with no SET values', '1.0.16' );
			return 0;
		}

		if ( empty( $this->where ) && ! $this->match_all_acknowledged ) {
			_doing_it_wrong(
				__METHOD__,
				'UpdateBuilder::execute() refused: no WHERE clause registered. Call match_all() to confirm an unscoped update.',
				'1.0.16'
			);
			return 0;
		}

		// Single-row/single-WHERE cases can use $wpdb->update() with its
		// auto-format detection. Multi-clause WHEREs need the manual
		// compose path - we always take that path for predictability.
		$set_parts = [];
		$set_vals  = [];
		foreach ( $this->set_values as $column => $value ) {
			$set_parts[] = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
			$set_vals[]  = $value;
		}

		$where_sql = empty( $this->where )
			? '1=1'  // match_all() case - verified above.
			: implode( ' AND ', $this->where );

		$sql    = "UPDATE {$this->table} SET " . implode( ', ', $set_parts ) . " WHERE {$where_sql}";
		$params = array_merge( $set_vals, $this->params );

		$result = empty( $params )
			? $wpdb->query( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Accept only bare column identifiers for SET - no dots, no aliases.
	 * UPDATE writes into a single table; `t.column` would be invalid SQL.
	 */
	private function validate_bare_column( string $column ): void {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $column ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid column identifier in UPDATE SET: ' . esc_html( $column ), '1.0.16' );
			throw new \InvalidArgumentException( 'Invalid SQL column identifier.' );
		}
	}

	private function validate_table( string $table ): void {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid UPDATE table identifier: ' . esc_html( $table ), '1.0.0' );
			throw new \InvalidArgumentException( 'Invalid SQL table identifier.' );
		}
	}
}
