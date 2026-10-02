<?php
declare(strict_types=1);
/**
 * DeleteBuilder - fluent DELETE construction with required WHERE.
 *
 * Same safety posture as {@see UpdateBuilder}: either WHERE clauses are
 * registered, or `match_all()` is explicitly called. A DELETE without
 * either is a DROP-TABLE-adjacent mistake; the builder refuses to run.
 *
 * Consumes {@see WhereClauses} for filter composition.
 *
 * Usage (targeted delete):
 *
 *     $rows = ( new DeleteBuilder( AuditLog::table() ) )
 *         ->lte_if_set( 'created_at', $retention_cutoff )
 *         ->execute();
 *
 * Usage (catch-all - explicit escape):
 *
 *     $rows = ( new DeleteBuilder( TempTable::name() ) )
 *         ->match_all()
 *         ->execute();
 *
 * Return of `execute()` is the number of rows deleted, or 0 on failure.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DB;

defined( 'ABSPATH' ) || exit;

final class DeleteBuilder {

	use WhereClauses;

	private string $table;

	/** @var string[] WHERE clauses joined with AND. Consumed by the trait. */
	private array $where = [];

	/** @var string[] Column identifier per $where entry (parallel to $where, tracked by trait). */
	private array $where_columns = [];

	/** @var mixed[] Positional parameters matched to `$wpdb->prepare()` placeholders. Consumed by the trait. */
	private array $params = [];

	/** Explicit opt-in for unscoped deletes. Prevents accidental full-table purges. */
	private bool $match_all_acknowledged = false;

	public function __construct( string $table ) {
		$this->validate_table( $table );
		$this->table = $table;
	}

	/**
	 * Explicit acknowledgment that this DELETE is intended to purge
	 * every row. Required if no WHERE helpers are called.
	 */
	public function match_all(): self {
		$this->match_all_acknowledged = true;
		return $this;
	}

	/**
	 * Execute the DELETE. Returns the number of rows affected, or 0 on
	 * failure. Fails closed on missing WHERE clause (like UpdateBuilder).
	 */
	public function execute(): int {
		global $wpdb;

		if ( empty( $this->where ) && ! $this->match_all_acknowledged ) {
			_doing_it_wrong(
				__METHOD__,
				'DeleteBuilder::execute() refused: no WHERE clause registered. Call match_all() to confirm a full-table purge.',
				'1.0.16'
			);
			return 0;
		}

		$where_sql = empty( $this->where )
			? '1=1'
			: implode( ' AND ', $this->where );

		$sql = "DELETE FROM {$this->table} WHERE {$where_sql}";

		$result = empty( $this->params )
			? $wpdb->query( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->query( $wpdb->prepare( $sql, $this->params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false === $result ? 0 : (int) $result;
	}

	private function validate_table( string $table ): void {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid DELETE table identifier: ' . esc_html( $table ), '1.0.0' );
			throw new \InvalidArgumentException( 'Invalid SQL table identifier.' );
		}
	}
}
