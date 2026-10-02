<?php
declare(strict_types=1);
/**
 * QueryBuilder - fluent SELECT construction for Core Blueprint's tables.
 *
 * v1.1 (1.0.16) - adds subquery-WHERE support inside `latest_per_group()`,
 * aggregate-free projection helpers (`select_coalesce`, `select_literal`,
 * `select_as`), and changes `select()` from replace- to append-semantics
 * for consistency with the aggregate-projection helpers. See CHANGELOG
 * for full details.
 *
 * v1 (1.0.15) - first production-grade release. The earlier revisions
 * (single-table filter + pagination) are considered beta. v1 adds:
 *
 *   - INNER / LEFT JOINs with strict equality-only ON conditions.
 *   - Custom SELECT column lists + table aliases.
 *   - IN-clauses via the shared {@see WhereClauses::in_if_set()} trait.
 *   - GROUP BY + aggregate terminals (count/sum/avg/max/min).
 *   - A targeted `latest_per_group()` helper that safely generates the
 *     "greatest-n-per-group" subquery + self-JOIN pattern without exposing
 *     raw-subquery support.
 *
 * Security posture - fail-closed throughout:
 *   - Every value goes through `$wpdb->prepare()`; no user data is
 *     interpolated into SQL.
 *   - Column identifiers are validated against a strict regex accepting
 *     either `column` or `alias.column`.
 *   - JOIN ON conditions are validated as `{alias.col} = {alias.col}` only;
 *     compound conditions (AND, OR) are rejected.
 *   - `SELECT *` is always safe; custom column lists validate each
 *     identifier (aliases allowed, functions rejected).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DB;

defined( 'ABSPATH' ) || exit;

final class QueryBuilder {

	use WhereClauses;

	/** Fully-prefixed table name. The WP prefix is the caller's responsibility. */
	private string $table;

	/** Optional alias for the primary table (for JOINs that reference `t.col`). */
	private ?string $alias = null;

	/** @var string[] SELECT column list. Empty = `*`. */
	private array $select_columns = [];

	/** @var array<int, array<string, mixed>> JOINs, each with type/table/alias/on info. */
	private array $joins = [];

	/**
	 * When set, the builder is in "latest per group" mode: compile_select
	 * generates a subquery-with-self-JOIN from {@see latest_per_group()}'s
	 * metadata at SQL-assembly time, so WHERE clauses added AFTER that
	 * call participate in both the inner MAX computation and the outer
	 * row selection.
	 *
	 * @var array{max_column: string, group_columns: string[]}|null
	 */
	private ?array $latest_per_group_spec = null;

	/** @var string[] WHERE clauses joined with AND. Consumed by the trait. */
	private array $where = [ '1=1' ];

	/**
	 * @var string[] Column identifier for each $where entry - parallel array,
	 * tracked by the trait's {@see WhereClauses::record_where()}. Used by
	 * {@see rewrite_where_for_outer_main_scope()} to decide whether to
	 * prefix a clause with `main.` in latest_per_group mode. The leading
	 * entry is an empty string matching the `'1=1'` sentinel in $where.
	 */
	private array $where_columns = [ '' ];

	/** @var mixed[] Positional parameters matched to `$wpdb->prepare()` placeholders. Consumed by the trait. */
	private array $params = [];

	/** @var string[] GROUP BY columns. */
	private array $group_by = [];

	private string $order_column    = 'id';
	private string $order_direction = 'DESC';

	private ?int $limit  = null;
	private ?int $offset = null;

	/**
	 * @param string      $table Fully-prefixed table name.
	 * @param string|null $alias Optional alias for use in SELECT/JOIN/WHERE.
	 */
	public function __construct( string $table, ?string $alias = null ) {
		$this->validate_table( $table );
		$this->table = $table;
		if ( null !== $alias ) {
			$this->validate_alias( $alias );
			$this->alias = $alias;
		}
	}

	// ─── SELECT projection ────────────────────────────────────────────────────

	/**
	 * Append columns to the SELECT list. Each entry is validated via
	 * the column identifier rules (`col` or `alias.col`). Functions and
	 * raw expressions are rejected in this method - use the
	 * `select_count()` / `select_sum()` / `select_avg()` / `select_max()`
	 * / `select_min()` / `select_coalesce()` / `select_literal()`
	 * helpers below for aggregate, fallback, and literal projections.
	 *
	 * Multiple calls extend rather than replace - this is consistent
	 * with how the aggregate-projection helpers behave, so you can
	 * freely mix `->select([...])->select_count(...)->select([...])`
	 * and have all three contribute to the final SELECT list.
	 *
	 * When no projection helpers are called at all, the builder
	 * implicitly uses `SELECT *` (or `SELECT alias.*` if an alias was
	 * set on the primary table).
	 */
	public function select( array $columns ): self {
		foreach ( $columns as $column ) {
			$this->validate_column( (string) $column );
		}
		foreach ( $columns as $column ) {
			$this->select_columns[] = (string) $column;
		}
		return $this;
	}

	// ─── Aggregate projected columns ──────────────────────────────────────────
	//
	// These helpers add aggregate expressions to the SELECT list - unlike
	// the `count()` / `sum()` / `avg()` / `max()` / `min()` TERMINALS above,
	// which execute the query and return a single scalar, these build up
	// an aggregate-as-column pattern for use alongside regular columns:
	//
	//     $qb->select( [ 't.id', 't.name' ] )
	//        ->select_count( 'st.site_id', 'site_count' )
	//        ->left_join( 'site_tags', 'st', 'st.tag_id', 't.id' )
	//        ->group_by( 't.id' )
	//        ->get_rows();
	//
	// Column and alias are both validated via the strict identifier regex
	// - no room for raw SQL in either slot.

	/** Append `COUNT(column) AS alias` to the SELECT list. */
	public function select_count( string $column, string $alias ): self {
		return $this->add_aggregate_select( 'COUNT', $column, $alias );
	}

	/** Append `SUM(column) AS alias` to the SELECT list. */
	public function select_sum( string $column, string $alias ): self {
		return $this->add_aggregate_select( 'SUM', $column, $alias );
	}

	/** Append `AVG(column) AS alias` to the SELECT list. */
	public function select_avg( string $column, string $alias ): self {
		return $this->add_aggregate_select( 'AVG', $column, $alias );
	}

	/** Append `MAX(column) AS alias` to the SELECT list. */
	public function select_max( string $column, string $alias ): self {
		return $this->add_aggregate_select( 'MAX', $column, $alias );
	}

	/** Append `MIN(column) AS alias` to the SELECT list. */
	public function select_min( string $column, string $alias ): self {
		return $this->add_aggregate_select( 'MIN', $column, $alias );
	}

	/**
	 * Internal: add an aggregate projection. Aggregate function name is
	 * a fixed literal (never user input) so no validation needed there;
	 * column and alias both get the strict identifier treatment.
	 */
	private function add_aggregate_select( string $fn, string $column, string $alias ): self {
		$this->validate_column( $column );
		$this->validate_alias( $alias );
		$this->select_columns[] = "{$fn}({$column}) AS {$alias}";
		return $this;
	}

	/**
	 * Append `COALESCE(col1, col2, …) AS alias` to the SELECT list.
	 *
	 * Used when a column should fall back to another when NULL - Hub's
	 * Activity feed uses this to surface `finished_at` if present,
	 * `started_at` otherwise, so a row that's still running shows up
	 * in the timeline at its start time. Each column goes through the
	 * strict identifier validator; alias likewise.
	 *
	 * @param string[] $columns At least two columns; order matters.
	 * @param string   $alias
	 */
	public function select_coalesce( array $columns, string $alias ): self {
		if ( count( $columns ) < 2 ) {
			_doing_it_wrong( __METHOD__, 'select_coalesce() requires at least two columns', '1.0.16' );
			return $this;
		}
		foreach ( $columns as $column ) {
			$this->validate_column( (string) $column );
		}
		$this->validate_alias( $alias );
		$this->select_columns[] = 'COALESCE(' . implode( ', ', $columns ) . ") AS {$alias}";
		return $this;
	}

	/**
	 * Append `column AS alias` to the SELECT list. Used when a column
	 * should be exposed under a different name - typically to line up
	 * column shapes across PHP-merged result sets (Hub's Activity feed
	 * merges backup-log rows + notification-log rows, and needs both
	 * shapes to expose the same property names).
	 *
	 * Both sides validated via the strict identifier rules: column as
	 * `col` or `alias.col`, alias as a bare identifier.
	 */
	public function select_as( string $column, string $alias ): self {
		$this->validate_column( $column );
		$this->validate_alias( $alias );
		$this->select_columns[] = "{$column} AS {$alias}";
		return $this;
	}

	/**
	 * Append a constant value as a named SELECT column.
	 *
	 * Used to discriminate rows coming from UNIONed / merged sources -
	 * Hub's Activity feed tags each row as either `'backup'` or
	 * `'notification'` via this pattern so the PHP-side consumer knows
	 * which kind of row it's looking at.
	 *
	 * Pass `null` to render `NULL AS alias` (useful for matching column
	 * counts across UNIONs - Hub's notification-rows use NULL for the
	 * columns they don't have, so the two SELECTs line up shape-wise).
	 *
	 * Literal string values are restricted to a conservative character
	 * whitelist (alphanumerics, underscore, dash, dot, space) - anything
	 * outside that set is rejected. This is fail-closed by design: the
	 * builder is not a SQL-injection playground, and every legitimate
	 * use-case inside the Suite involves short technical discriminators.
	 * If a longer / richer literal ever becomes necessary, the right
	 * pattern is parameterising via a placeholder, not widening the
	 * whitelist here.
	 *
	 * @param string|null $value Literal value to emit. NULL renders as SQL NULL.
	 * @param string      $alias Column alias.
	 */
	public function select_literal( ?string $value, string $alias ): self {
		$this->validate_alias( $alias );

		if ( null === $value ) {
			$this->select_columns[] = "NULL AS {$alias}";
			return $this;
		}

		if ( ! preg_match( '/^[A-Za-z0-9_\-. ]*$/', $value ) ) {
			_doing_it_wrong( __METHOD__, 'select_literal() value contains disallowed characters: ' . esc_html( $value ), '1.0.16' );
			return $this;
		}

		$this->select_columns[] = "'{$value}' AS {$alias}";
		return $this;
	}

	// ─── JOINs ────────────────────────────────────────────────────────────────

	/**
	 * Register an INNER JOIN.
	 *
	 * The ON condition is enforced as `{alias.col} = {alias.col}`. No
	 * compound AND/OR conditions, no literal values, no functions. If
	 * additional filtering is needed on joined rows, add it via the
	 * WHERE helpers.
	 *
	 * @param string $table    Fully-prefixed table to join.
	 * @param string $alias    Alias assigned to the joined table.
	 * @param string $on_left  Left side of the ON equality (e.g. `j.parent_id`).
	 * @param string $on_right Right side of the ON equality (e.g. `t.id`).
	 */
	public function join( string $table, string $alias, string $on_left, string $on_right ): self {
		return $this->add_join( 'INNER JOIN', $table, $alias, $on_left, $on_right );
	}

	/**
	 * Register a LEFT JOIN. Semantics match {@see join()}; use when the
	 * joined table may have no matching rows and you need NULLs back.
	 */
	public function left_join( string $table, string $alias, string $on_left, string $on_right ): self {
		return $this->add_join( 'LEFT JOIN', $table, $alias, $on_left, $on_right );
	}

	private function add_join( string $type, string $table, string $alias, string $on_left, string $on_right ): self {
		$this->validate_table( $table );
		$this->validate_alias( $alias );
		$this->validate_qualified_column( $on_left );
		$this->validate_qualified_column( $on_right );

		$this->joins[] = [
			'type'     => $type,
			'table'    => $table,
			'alias'    => $alias,
			'on_left'  => $on_left,
			'on_right' => $on_right,
		];
		return $this;
	}

	// ─── GROUP BY ─────────────────────────────────────────────────────────────

	/**
	 * Append columns to the GROUP BY list. Callable multiple times; each
	 * call extends rather than replaces.
	 */
	public function group_by( string ...$columns ): self {
		foreach ( $columns as $column ) {
			$this->validate_column( $column );
			$this->group_by[] = $column;
		}
		return $this;
	}

	// ─── ORDER + LIMIT ────────────────────────────────────────────────────────

	public function order_by_desc( string $column ): self {
		$this->validate_column( $column );
		$this->order_column    = $column;
		$this->order_direction = 'DESC';
		return $this;
	}

	public function order_by_asc( string $column ): self {
		$this->validate_column( $column );
		$this->order_column    = $column;
		$this->order_direction = 'ASC';
		return $this;
	}

	/**
	 * Set an explicit limit + offset. Prefer {@see paginate()} for page/
	 * per-page pagination - that caps per-page at a sane maximum.
	 */
	public function limit( int $limit, int $offset = 0 ): self {
		$this->limit  = max( 1, $limit );
		$this->offset = max( 0, $offset );
		return $this;
	}

	/**
	 * Page-based pagination. Caps per_page between 1 and `$max_per_page`
	 * (default 500). Log queries should never return unbounded rowsets.
	 */
	public function paginate( int $page, int $per_page, int $max_per_page = 500 ): self {
		$per_page     = max( 1, min( $max_per_page, $per_page ) );
		$page         = max( 1, $page );
		$this->limit  = $per_page;
		$this->offset = ( $page - 1 ) * $per_page;
		return $this;
	}

	// ─── latest_per_group helper ──────────────────────────────────────────────

	/**
	 * Restrict results to the "latest row per group" - the classical
	 * greatest-n-per-group pattern.
	 *
	 * At SQL-assembly time this generates an INNER JOIN against a
	 * subquery that computes the MAX of `$max_column` per
	 * `$group_columns` combination, then matches the outer row on both
	 * the group columns and the MAX. The subquery is entirely
	 * builder-generated - no raw SQL input.
	 *
	 * Since v1.1: WHERE clauses added to the builder apply to **both**
	 * the inner MAX computation and the outer row selection. For
	 * example, adding `->equals_if_set('status', 'done')` means the MAX
	 * is taken over done-rows only, and only done-rows are returned -
	 * the two halves stay semantically consistent. This closes the
	 * v1.0 gap where Hub's `get_last_backups` had to stay on native
	 * `$wpdb` because the subquery couldn't participate in the filter.
	 *
	 * After this call the builder's alias becomes `main` internally. Do
	 * not combine with an explicit alias on construction; use a fresh
	 * builder for latest-per-group queries.
	 *
	 * @param string   $max_column    Column whose MAX identifies the latest row (e.g. `finished_at`).
	 * @param string[] $group_columns Columns defining the group (e.g. `[ 'site_id', 'provider' ]`).
	 */
	public function latest_per_group( string $max_column, array $group_columns ): self {
		if ( empty( $group_columns ) ) {
			_doing_it_wrong( __METHOD__, 'latest_per_group() requires at least one group column', '1.0.16' );
			return $this;
		}
		if ( null !== $this->alias ) {
			_doing_it_wrong( __METHOD__, 'latest_per_group() cannot be combined with a table alias set on construction', '1.0.16' );
			return $this;
		}
		if ( null !== $this->latest_per_group_spec ) {
			_doing_it_wrong( __METHOD__, 'latest_per_group() called twice on the same builder', '1.0.16' );
			return $this;
		}

		$this->validate_column( $max_column );
		$normalised_groups = [];
		foreach ( $group_columns as $col ) {
			$col = (string) $col;
			$this->validate_column( $col );
			$normalised_groups[] = $col;
		}

		// Primary table becomes "main"; the subquery will be aliased "latest"
		// at compile time. Store metadata only - actual SQL is built later
		// so WHERE clauses registered AFTER this call still reach the inner
		// subquery.
		$this->alias                 = 'main';
		$this->latest_per_group_spec = [
			'max_column'    => $max_column,
			'group_columns' => $normalised_groups,
		];

		return $this;
	}

	// ─── Terminals ────────────────────────────────────────────────────────────

	/**
	 * SELECT COUNT(*) against the compiled WHERE + JOINs. Ignores GROUP BY,
	 * LIMIT, and OFFSET - returns the total match count for pagination
	 * metadata.
	 */
	public function count(): int {
		return (int) $this->run_scalar( 'SELECT COUNT(*)' );
	}

	/** SELECT SUM(column) - returns 0.0 when no matching rows. */
	public function sum( string $column ): float {
		$this->validate_column( $column );
		return (float) $this->run_scalar( "SELECT SUM({$column})" );
	}

	/** SELECT AVG(column) - returns 0.0 when no matching rows. */
	public function avg( string $column ): float {
		$this->validate_column( $column );
		return (float) $this->run_scalar( "SELECT AVG({$column})" );
	}

	/**
	 * SELECT MAX(column). Returns null when no matching rows - use the
	 * return type to distinguish "no data" from "0" in integer columns.
	 */
	public function max( string $column ): ?string {
		$this->validate_column( $column );
		$result = $this->run_scalar( "SELECT MAX({$column})" );
		return null === $result ? null : (string) $result;
	}

	/** SELECT MIN(column). Returns null when no matching rows. */
	public function min( string $column ): ?string {
		$this->validate_column( $column );
		$result = $this->run_scalar( "SELECT MIN({$column})" );
		return null === $result ? null : (string) $result;
	}

	/**
	 * SELECT against the compiled query.
	 *
	 * @param string $output OBJECT, OBJECT_K, ARRAY_A, or ARRAY_N.
	 * @return array<int, mixed>
	 */
	public function get_rows( string $output = OBJECT ): array {
		global $wpdb;

		$sql = $this->compile_select();

		$params = $this->effective_params();
		if ( empty( $params ) && null === $this->limit ) {
			$rows = $wpdb->get_results( $sql, $output ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			if ( null !== $this->limit ) {
				$sql     .= ' LIMIT %d OFFSET %d';
				$params[] = $this->limit;
				$params[] = (int) ( $this->offset ?? 0 );
			}
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), $output ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Convenience: count + rows + pagination envelope in one call.
	 *
	 * @return array{rows: array, total: int, page: int, per_page: int}
	 */
	public function get_paginated( int $page, int $per_page, string $output = OBJECT ): array {
		$per_page = max( 1, min( 500, $per_page ) );
		$page     = max( 1, $page );

		$total = $this->count();
		$rows  = $this->get_rows( $output );

		return [
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Return a single column as a flat array.
	 */
	public function get_col( string $column ): array {
		$this->validate_column( $column );
		global $wpdb;

		$original_select      = $this->select_columns;
		$this->select_columns = [ $column ];
		$sql                  = $this->compile_select();
		$this->select_columns = $original_select;

		$params = $this->effective_params();
		if ( empty( $params ) ) {
			$col = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$col = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return is_array( $col ) ? $col : [];
	}

	/**
	 * Return the first matching row only, or null when no match.
	 *
	 * Forces `limit = 1` so the query never returns more than one row.
	 * Does NOT reset offset - if the caller set one via {@see limit()}
	 * to skip past earlier rows (e.g. "one row, offset N" for look-back
	 * windows), the offset is preserved.
	 */
	public function get_row( string $output = OBJECT ): ?object {
		$this->limit = 1;
		$rows        = $this->get_rows( $output );
		return $rows[0] ?? null;
	}

	// ─── SQL assembly ─────────────────────────────────────────────────────────

	/**
	 * Compose the final SELECT SQL (without LIMIT/OFFSET - those are
	 * appended by the caller alongside their parameters).
	 */
	private function compile_select(): string {
		$columns = empty( $this->select_columns )
			? ( null !== $this->alias ? "{$this->alias}.*" : '*' )
			: implode( ', ', $this->select_columns );

		$from = null !== $this->alias
			? "{$this->table} AS {$this->alias}"
			: $this->table;

		$sql = "SELECT {$columns} FROM {$from}" . $this->compile_joins()
			 . ' WHERE ' . $this->compile_outer_where();

		if ( ! empty( $this->group_by ) ) {
			$sql .= ' GROUP BY ' . implode( ', ', $this->group_by );
		}

		$sql .= " ORDER BY {$this->order_column} {$this->order_direction}";
		return $sql;
	}

	/**
	 * WHERE fragment for the outer query.
	 *
	 * In normal single-table mode, this is just `implode( ' AND ', $this->where )`.
	 *
	 * In latest_per_group mode the primary table is aliased `main` and the
	 * subquery is aliased `latest`, both in scope. Unqualified column
	 * references in WHERE clauses would be ambiguous to MySQL (both
	 * `main` and `latest` expose the same column names). We rewrite each
	 * clause so that columns without an `alias.` prefix get `main.` -
	 * columns the caller already qualified (`j.foo`, `main.bar`) are left
	 * alone.
	 */
	private function compile_outer_where(): string {
		if ( null === $this->latest_per_group_spec ) {
			return implode( ' AND ', $this->where );
		}
		return implode( ' AND ', $this->rewrite_where_for_main_scope() );
	}

	/**
	 * Rewrite WHERE clauses to prefix unqualified columns with `main.`.
	 *
	 * Used for the outer query in latest_per_group mode (the inner subquery
	 * keeps the raw WHERE because it runs against a single table with no
	 * alias in scope - unqualified columns there are unambiguous).
	 *
	 * Rewrite is deterministic: we work from structured metadata
	 * ($this->where_columns, populated by {@see WhereClauses::record_where()}),
	 * not regex over generated SQL. An empty column (sentinel for the
	 * `'1=1'` bootstrap clause) or an already-qualified column
	 * (contains '.') is left alone.
	 *
	 * @return string[]
	 */
	private function rewrite_where_for_main_scope(): array {
		$out = [];
		foreach ( $this->where as $i => $clause ) {
			$col = $this->where_columns[ $i ] ?? '';
			if ( '' === $col || str_contains( $col, '.' ) ) {
				$out[] = $clause;
				continue;
			}
			// Column is always the first token of the clause (our helpers
			// all emit "{column} OP …"). Replace only the leading
			// occurrence - a value in the clause that happens to match
			// the column name must not be touched.
			if ( 0 === strpos( $clause, $col ) ) {
				$out[] = 'main.' . $clause;
			} else {
				// Defensive fallback: shape we didn't anticipate. Leave
				// untouched rather than guess. Callers would see an
				// ambiguity error and we'd know to look here.
				$out[] = $clause;
			}
		}
		return $out;
	}

	/**
	 * Rewrite WHERE clauses for the inner subquery scope in latest_per_group
	 * mode. The inner FROM has no alias in scope, so `main.col` references
	 * would break. This helper strips any `main.` prefix so the clause is
	 * re-usable inside the subquery.
	 *
	 * @return string[]
	 */
	private function rewrite_where_for_inner_scope(): array {
		$out = [];
		foreach ( $this->where as $i => $clause ) {
			$col = $this->where_columns[ $i ] ?? '';
			if ( '' === $col || ! str_starts_with( $col, 'main.' ) ) {
				$out[] = $clause;
				continue;
			}
			$stripped_col = substr( $col, 5 ); // 'main.'|length 5
			if ( 0 === strpos( $clause, $col ) ) {
				$out[] = $stripped_col . substr( $clause, strlen( $col ) );
			} else {
				$out[] = $clause;
			}
		}
		return $out;
	}

	/**
	 * JOIN SQL fragment - empty string when no joins are registered.
	 *
	 * Also responsible for emitting the latest_per_group subquery-JOIN
	 * when the builder is in that mode. The inner subquery inherits the
	 * outer WHERE clauses so the MAX is computed over the same filtered
	 * row-set that the outer query returns - see {@see effective_params}
	 * which doubles the param list for this case.
	 */
	private function compile_joins(): string {
		$parts = [];

		// latest_per_group mode: prepend the generated subquery-JOIN.
		if ( null !== $this->latest_per_group_spec ) {
			$parts[] = $this->compile_latest_per_group_join();
		}

		foreach ( $this->joins as $join ) {
			$parts[] = " {$join['type']} {$join['table']} AS {$join['alias']} "
					 . "ON {$join['on_left']} = {$join['on_right']}";
		}
		return implode( '', $parts );
	}

	/**
	 * Build the INNER JOIN fragment for latest_per_group mode.
	 *
	 * The inner subquery FROMs a single unaliased table - its scope is just
	 * $this->table, no `main` alias. So WHERE clauses that reference
	 * `main.col` (whether because the caller wrote it that way, or because
	 * {@see rewrite_where_for_main_scope()} added it for the outer query)
	 * need the `main.` prefix stripped for inner consumption. Qualified
	 * references to OTHER aliases (`j.col`) would still be invalid in the
	 * inner scope, but our helpers don't produce those in latest_per_group
	 * queries - we only tolerate `main.` and bare.
	 *
	 * Inner subquery shape:
	 *
	 *     INNER JOIN (
	 *         SELECT {group_cols}, MAX({max_col}) AS cb_max_val
	 *         FROM {table}
	 *         WHERE {same WHERE as outer, un-prefixed}
	 *         GROUP BY {group_cols}
	 *     ) AS latest ON main.{group_col_1} = latest.{group_col_1}
	 *                AND …
	 *                AND main.{max_col} = latest.cb_max_val
	 */
	private function compile_latest_per_group_join(): string {
		$spec        = $this->latest_per_group_spec;
		$max_col     = $spec['max_column'];
		$group_cols  = $spec['group_columns'];
		$group_sql   = implode( ', ', $group_cols );
		$inner_where = implode( ' AND ', $this->rewrite_where_for_inner_scope() );

		$inner_sql = "( SELECT {$group_sql}, MAX({$max_col}) AS cb_max_val"
				   . " FROM {$this->table}"
				   . " WHERE {$inner_where}"
				   . " GROUP BY {$group_sql} )";

		$on_parts = [];
		foreach ( $group_cols as $c ) {
			$on_parts[] = "main.{$c} = latest.{$c}";
		}
		$on_parts[] = "main.{$max_col} = latest.cb_max_val";

		return " INNER JOIN {$inner_sql} AS latest ON " . implode( ' AND ', $on_parts );
	}

	/**
	 * Parameter list to feed `$wpdb->prepare()`.
	 *
	 * For normal queries: the accumulated $this->params.
	 *
	 * For latest_per_group mode: the params are duplicated because the
	 * same WHERE clauses appear TWICE in the SQL (once in the inner
	 * subquery, once in the outer query). $wpdb->prepare substitutes
	 * placeholders positionally left-to-right, and the inner WHERE
	 * placeholders come before the outer ones in the assembled SQL, so
	 * inner params come first in the prepared array.
	 *
	 * @return mixed[]
	 */
	private function effective_params(): array {
		if ( null === $this->latest_per_group_spec ) {
			return $this->params;
		}
		// Inner WHERE params, then outer WHERE params - same values, same order.
		return array_merge( $this->params, $this->params );
	}

	/**
	 * Execute a scalar SELECT built on top of the current WHERE/JOIN/GROUP
	 * state. Used by count/sum/avg/max/min.
	 */
	private function run_scalar( string $select_clause ): mixed {
		global $wpdb;

		$from = null !== $this->alias
			? "{$this->table} AS {$this->alias}"
			: $this->table;

		$sql = "{$select_clause} FROM {$from}" . $this->compile_joins()
			 . ' WHERE ' . $this->compile_outer_where();

		if ( ! empty( $this->group_by ) ) {
			$sql .= ' GROUP BY ' . implode( ', ', $this->group_by );
		}

		$params = $this->effective_params();
		if ( empty( $params ) ) {
			return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ─── Validators ───────────────────────────────────────────────────────────

	/**
	 * Alias identifiers are stricter than columns: no dots allowed, since
	 * an alias is a single identifier that prefixes other identifiers.
	 */
	private function validate_alias( string $alias ): void {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $alias ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid table alias: ' . esc_html( $alias ), '1.0.16' );
			throw new \InvalidArgumentException( 'Invalid SQL alias identifier.' );
		}
	}

	/**
	 * JOIN ON conditions require the `alias.column` form on both sides -
	 * a bare column without an alias is ambiguous in a multi-table query.
	 */
	private function validate_qualified_column( string $column ): void {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $column ) ) {
			_doing_it_wrong( __METHOD__, 'JOIN ON columns must be qualified (alias.column): ' . esc_html( $column ), '1.0.16' );
			throw new \InvalidArgumentException( 'Invalid qualified SQL column identifier.' );
		}
	}

	private function validate_table( string $table ): void {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid query table identifier: ' . esc_html( $table ), '1.0.0' );
			throw new \InvalidArgumentException( 'Invalid SQL table identifier.' );
		}
	}
}
