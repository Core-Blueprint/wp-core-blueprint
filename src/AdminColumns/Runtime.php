<?php
declare(strict_types=1);
/**
 * Native WordPress runtime integration for Admin Columns Governance.
 *
 * Core Blueprint receives the completed WordPress/plugin column map at the
 * official manage_{$screen->id}_columns boundary. It never replaces or renders
 * the list table itself.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\AdminColumns;

defined( 'ABSPATH' ) || exit;

final class Runtime {
	private const COLUMN_FILTER_PRIORITY = PHP_INT_MAX;
	private const PROTECTED_VISIBILITY_COLUMNS = [ 'cb', 'title' ];

	/** @var array<string,string> screen id => dynamic filter hook */
	private static array $hooks = [];

	/** @var array<string,array<string,mixed>> Request-local pre-governance discovery. */
	private static array $discovered = [];

	public static function attach( \WP_Screen $screen ): void {
		if ( ! SupportedScreen::is_supported( $screen ) ) {
			return;
		}

		$screen_id = (string) $screen->id;
		if ( isset( self::$hooks[ $screen_id ] ) ) {
			return;
		}

		$hook = 'manage_' . $screen_id . '_columns';
		self::$hooks[ $screen_id ] = $hook;
		add_filter( $hook, [ self::class, 'filter_columns' ], self::COLUMN_FILTER_PRIORITY );
	}

	/** @param array<string,mixed> $columns @return array<string,mixed> */
	public static function filter_columns( array $columns ): array {
		$screen_id = array_search( current_filter(), self::$hooks, true );
		if ( false === $screen_id ) {
			return $columns;
		}

		// Discovery is deliberately request-local and captures the complete map
		// received from WordPress/plugins before site-wide governance is applied.
		self::$discovered[ $screen_id ] = $columns;

		$screen_policy = PolicyRepository::screen( $screen_id );
		if ( null === $screen_policy ) {
			return $columns;
		}

		return self::apply_policy( $columns, $screen_policy );
	}

	/** @return array<string,mixed> */
	public static function discovered_columns( string $screen_id ): array {
		return self::$discovered[ $screen_id ] ?? [];
	}

	/**
	 * Apply one normalized screen policy to an already-complete column map.
	 *
	 * Unknown columns are anchors. Only governed identities that currently exist
	 * can be hidden or reordered. Dormant policy identities never create columns.
	 *
	 * @param array<string,mixed> $columns
	 * @param array{order:list<string>,hidden:list<string>} $policy
	 * @return array<string,mixed>
	 * @internal
	 */
	public static function apply_policy( array $columns, array $policy ): array {
		$order = is_array( $policy['order'] ?? null ) ? array_values( $policy['order'] ) : [];
		$hidden = is_array( $policy['hidden'] ?? null ) ? array_values( $policy['hidden'] ) : [];
		if ( [] === $order && [] === $hidden ) {
			return $columns;
		}

		$governed = array_fill_keys( array_filter( $order, 'is_string' ), true );
		$hidden_lookup = array_fill_keys( array_filter( $hidden, 'is_string' ), true );
		$remaining = [];
		foreach ( $columns as $column_id => $label ) {
			$identity = (string) $column_id;
			$protected = in_array( $identity, self::PROTECTED_VISIBILITY_COLUMNS, true );
			if ( isset( $governed[ $identity ], $hidden_lookup[ $identity ] ) && ! $protected ) {
				continue;
			}
			$remaining[ $column_id ] = $label;
		}

		$present_governed = [];
		foreach ( $order as $column_id ) {
			if ( ! is_string( $column_id ) || ! array_key_exists( $column_id, $remaining ) ) {
				continue;
			}
			$present_governed[] = $column_id;
		}

		if ( [] !== $present_governed ) {
			$present_lookup = array_fill_keys( $present_governed, true );
			$next = 0;
			$reordered = [];
			foreach ( $remaining as $column_id => $label ) {
				$identity = (string) $column_id;
				if ( isset( $present_lookup[ $identity ] ) ) {
					$target = $present_governed[ $next++ ];
					$reordered[ $target ] = $remaining[ $target ];
					continue;
				}
				$reordered[ $column_id ] = $label;
			}
			$remaining = $reordered;
		}

		// Structural protection never synthesizes cb; it only keeps a received
		// checkbox column first when one survived the upstream WordPress/plugin map.
		if ( array_key_exists( 'cb', $remaining ) ) {
			$cb = $remaining['cb'];
			unset( $remaining['cb'] );
			$remaining = [ 'cb' => $cb ] + $remaining;
		}

		return $remaining;
	}

	/** @internal Integration tests only. */
	public static function _reset_for_testing(): void {
		foreach ( self::$hooks as $hook ) {
			remove_filter( $hook, [ self::class, 'filter_columns' ], self::COLUMN_FILTER_PRIORITY );
		}
		self::$hooks = [];
		self::$discovered = [];
	}

	private function __construct() {}
}
