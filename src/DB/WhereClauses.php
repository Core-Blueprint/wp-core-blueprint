<?php
declare(strict_types=1);
/**
 * WhereClauses - shared WHERE-clause builders for every fluent SQL builder.
 *
 * Consumed by {@see QueryBuilder}, {@see UpdateBuilder}, and
 * {@see DeleteBuilder}. The three builders each compose SQL with a WHERE
 * tail, and every builder shares the same guarantees:
 *
 *   1. Every value is parameterised via `$wpdb->prepare()` - never
 *      interpolated into the SQL string.
 *   2. Every column reference is validated against a strict identifier
 *      pattern that accepts bare identifiers (`column`) and single-level
 *      aliased references (`t.column`). Anything else is rejected with
 *      `_doing_it_wrong()`.
 *   3. The `*_if_set` helpers treat empty strings / zero / empty arrays
 *      as "not set" and skip the clause entirely. This lets callers pass
 *      request parameters straight in without guard wrappers.
 *
 * Fluent chaining is enforced by every helper returning `$this`. Internal
 * state (the `$where` list, `$params` list, and `$where_columns` column
 * metadata) is declared on the consuming class and read/written here via
 * `$this->`.
 *
 * `$where_columns` tracks the column identifier used in each $where entry
 * so that {@see QueryBuilder::rewrite_where_for_outer_main_scope()} can
 * prefix unqualified columns with `main.` when compiling the outer WHERE
 * of a latest_per_group query - where both the primary table and the
 * subquery-alias share the same column names and unqualified references
 * would be ambiguous to MySQL.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DB;

defined( 'ABSPATH' ) || exit;

trait WhereClauses {

	/**
	 * Internal helper: record a WHERE clause, its params, and the column
	 * it filters on. Every public helper in this trait ends up here.
	 *
	 * Centralising this also means that if we ever need to extend the
	 * metadata stored per clause (for e.g. EXPLAIN instrumentation), we
	 * change one method rather than 16.
	 *
	 * @param string $column  Column identifier (bare or `alias.column`).
	 * @param string $clause  Rendered SQL fragment (e.g. `status = %s`).
	 * @param mixed  ...$params Values to bind to placeholders in $clause.
	 */
	protected function record_where( string $column, string $clause, ...$params ): void {
		$this->where[]         = $clause;
		$this->where_columns[] = $column;
		foreach ( $params as $p ) {
			$this->params[] = $p;
		}
	}

	/**
	 * `column = value` when `$value` is a non-empty string. Optional
	 * sanitizer function name (e.g. 'sanitize_key') is applied first -
	 * if sanitization reduces the value to empty, the clause is skipped.
	 *
	 * @param string        $column    Identifier or `alias.column`.
	 * @param mixed         $value     Scalar; coerced to string.
	 * @param string|null   $sanitizer Callable name (like `sanitize_key`), optional.
	 * @return static
	 */
	public function equals_if_set( string $column, $value, ?string $sanitizer = null ): static {
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		if ( null !== $sanitizer && is_callable( $sanitizer ) ) {
			$value = (string) call_user_func( $sanitizer, $value );
			if ( '' === $value ) {
				return $this;
			}
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} = %s", $value );
		return $this;
	}

	/**
	 * `column = value` when `$value` is a non-empty string AND in the
	 * allowlist. Silently skips mismatches so a stale/spoofed filter value
	 * from a user input simply doesn't restrict the query.
	 */
	public function equals_enum_if_set( string $column, $value, array $allowed ): static {
		$value = (string) $value;
		if ( '' === $value || ! in_array( $value, $allowed, true ) ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} = %s", $value );
		return $this;
	}

	/**
	 * `column = value` as an integer comparison when `$value > 0`. Zero is
	 * treated as "not set" so callers can pass `$args['user_id']` directly
	 * without guarding empty input.
	 */
	public function int_equals_if_set( string $column, $value ): static {
		$value = (int) $value;
		if ( $value <= 0 ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} = %d", $value );
		return $this;
	}

	/**
	 * `column LIKE '%value%'` when `$value` is a non-empty string. The
	 * value is escaped via `$wpdb->esc_like()` before wildcards are added,
	 * so user input containing `%` or `_` doesn't become an injection vector
	 * into the LIKE pattern.
	 */
	public function like_if_set( string $column, $value ): static {
		global $wpdb;
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} LIKE %s", '%' . $wpdb->esc_like( $value ) . '%' );
		return $this;
	}

	/**
	 * `column LIKE 'value%'` - matches rows whose column STARTS with `$value`.
	 * Used for prefix filters (e.g. `event_prefix = 'system.'` style).
	 */
	public function starts_with_if_set( string $column, $value ): static {
		global $wpdb;
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} LIKE %s", $wpdb->esc_like( $value ) . '%' );
		return $this;
	}

	/**
	 * `column NOT LIKE 'value%'` - excludes rows whose column starts with
	 * `$value`. Inverse of `starts_with_if_set`.
	 */
	public function not_starts_with_if_set( string $column, $value ): static {
		global $wpdb;
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} NOT LIKE %s", $wpdb->esc_like( $value ) . '%' );
		return $this;
	}

	/**
	 * `column >= value` when `$value` is a non-empty string (typically a
	 * MySQL datetime). Used for `since` filters.
	 */
	public function gte_if_set( string $column, $value ): static {
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} >= %s", $value );
		return $this;
	}

	/**
	 * `column <= value` when `$value` is a non-empty string. Used for
	 * `until` filters.
	 */
	public function lte_if_set( string $column, $value ): static {
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} <= %s", $value );
		return $this;
	}

	/**
	 * `column != value` when `$value` is a non-empty string. Mirror of
	 * {@see equals_if_set()} for the negated case. Optional sanitizer
	 * applied first (same contract).
	 */
	public function not_equals_if_set( string $column, $value, ?string $sanitizer = null ): static {
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		if ( null !== $sanitizer && is_callable( $sanitizer ) ) {
			$value = (string) call_user_func( $sanitizer, $value );
			if ( '' === $value ) {
				return $this;
			}
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} != %s", $value );
		return $this;
	}

	/**
	 * `column > value` (strict) when `$value` is a non-empty string.
	 * Distinct from {@see gte_if_set()} when exact-boundary inclusion
	 * matters (e.g. "newer than X" vs "X or newer").
	 */
	public function gt_if_set( string $column, $value ): static {
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} > %s", $value );
		return $this;
	}

	/**
	 * `column < value` (strict) when `$value` is a non-empty string.
	 */
	public function lt_if_set( string $column, $value ): static {
		$value = (string) $value;
		if ( '' === $value ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} < %s", $value );
		return $this;
	}

	/**
	 * `column > value` (strict, integer) when `$value > 0`.
	 */
	public function int_gt_if_set( string $column, $value ): static {
		$value = (int) $value;
		if ( $value <= 0 ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} > %d", $value );
		return $this;
	}

	/**
	 * `column < value` (strict, integer) when `$value > 0`.
	 */
	public function int_lt_if_set( string $column, $value ): static {
		$value = (int) $value;
		if ( $value <= 0 ) {
			return $this;
		}
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} < %d", $value );
		return $this;
	}

	/**
	 * `column IN (v1, v2, …)` when `$values` is a non-empty array.
	 *
	 * Each value is coerced to the declared type (`'int'` or `'string'`)
	 * and parameterised via `$wpdb->prepare()`. Empty arrays skip the
	 * clause - caller can pass `$args['site_ids']` directly without
	 * guarding empty input.
	 *
	 * Performance note: if you pass > 1000 values you're likely better
	 * served by a temp-table JOIN. MySQL's IN performance degrades
	 * noticeably past that threshold. The builder accepts arbitrary
	 * length; sizing is the caller's concern.
	 *
	 * @param string $column Identifier or `alias.column`.
	 * @param array  $values Scalar values; non-empty to activate.
	 * @param string $type   `'int'` or `'string'`. Default `'int'`.
	 */
	public function in_if_set( string $column, array $values, string $type = 'int' ): static {
		if ( empty( $values ) ) {
			return $this;
		}
		if ( ! in_array( $type, [ 'int', 'string' ], true ) ) {
			_doing_it_wrong( __METHOD__, 'IN value type must be "int" or "string", got: ' . esc_html( $type ), '1.0.16' );
			return $this;
		}
		$this->validate_column( $column );

		$placeholder = 'int' === $type ? '%d' : '%s';
		$casted      = 'int' === $type
			? array_values( array_map( 'intval', $values ) )
			: array_values( array_map( 'strval', $values ) );

		$placeholders = implode( ', ', array_fill( 0, count( $casted ), $placeholder ) );
		$this->record_where( $column, "{$column} IN ({$placeholders})", ...$casted );
		return $this;
	}

	/**
	 * `column IS NOT NULL` - filters rows whose column has any value.
	 *
	 * Unlike the `*_if_set` helpers, this one is unconditional: calling it
	 * always adds the clause. There's no "not set" variant because there's
	 * no value to leave unset - it's a pure column-existence predicate.
	 *
	 * No parameterisation needed (no value), just column validation.
	 */
	public function where_not_null( string $column ): static {
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} IS NOT NULL" );
		return $this;
	}

	/**
	 * `column IS NULL` - filters rows whose column is unset.
	 */
	public function where_null( string $column ): static {
		$this->validate_column( $column );
		$this->record_where( $column, "{$column} IS NULL" );
		return $this;
	}

	/**
	 * Reject column names that could be used for SQL injection.
	 *
	 * Columns are never parameterised by `$wpdb->prepare()` - they're
	 * interpolated into the query text - so the builder accepts a strict
	 * identifier shape only:
	 *
	 *   - Bare identifier: `column_name` (alphanumerics + underscore,
	 *     not starting with a digit).
	 *   - Aliased identifier: `t.column_name` (one table alias prefix,
	 *     same rules on both sides, exactly one dot).
	 *
	 * Everything else is rejected with `_doing_it_wrong()` and an
	 * `InvalidArgumentException`. Invalid identifiers must never fall through
	 * into SQL assembly.
	 */
	protected function validate_column( string $column ): void {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid column identifier: ' . esc_html( $column ), '1.0.16' );
			throw new \InvalidArgumentException( 'Invalid SQL column identifier.' );
		}
	}
}
