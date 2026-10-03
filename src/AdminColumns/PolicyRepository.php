<?php
declare(strict_types=1);
/**
 * Canonical site-wide policy storage for Admin Columns Governance.
 *
 * The option stores governance intent only. Runtime discovery, labels and
 * per-user WordPress Screen Options are deliberately never persisted here.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\AdminColumns;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class PolicyRepository {
	public const OPTION = 'cb_core_admin_columns_policy';
	public const SCHEMA_VERSION = 1;

	private const MAX_SCREENS = 100;
	private const MAX_COLUMNS_PER_SCREEN = 250;
	private const MAX_COLUMN_ID_BYTES = 191;
	private const PROTECTED_VISIBILITY_COLUMNS = [ 'cb', 'title' ];

	/** @return array{schema_version:int,screens:array<string,array{order:list<string>,hidden:list<string>,taxonomies:list<string>,meta:list<string>}>} */
	public static function empty_policy(): array {
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'screens'        => [],
		];
	}

	/** @return array{schema_version:int,screens:array<string,array{order:list<string>,hidden:list<string>,taxonomies:list<string>,meta:list<string>}>} */
	public static function get(): array {
		$stored = get_option( self::OPTION, null );
		if ( null === $stored || false === $stored || ! is_array( $stored ) ) {
			return self::empty_policy();
		}
		try {
			return self::normalize( $stored );
		} catch ( \InvalidArgumentException ) {
			return self::empty_policy();
		}
	}

	/** @return array{order:list<string>,hidden:list<string>,taxonomies:list<string>,meta:list<string>}|null */
	public static function screen( string $screen_id ): ?array {
		$policy = self::get();
		return $policy['screens'][ $screen_id ] ?? null;
	}

	/** @return array{schema_version:int,screens:array<string,array{order:list<string>,hidden:list<string>,taxonomies:list<string>,meta:list<string>}>} */
	public static function normalize( array $policy ): array {
		self::assert_exact_keys( $policy, [ 'schema_version', 'screens' ] );
		if ( self::SCHEMA_VERSION !== ( $policy['schema_version'] ?? null ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns policy schema version is not supported.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ! is_array( $policy['screens'] ?? null ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns screens policy is invalid.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( count( $policy['screens'] ) > self::MAX_SCREENS ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns policy contains too many screens.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$screens = [];
		foreach ( $policy['screens'] as $screen_id => $screen_policy ) {
			if ( ! is_string( $screen_id ) || ! self::valid_screen_id( $screen_id ) || ! is_array( $screen_policy ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The Admin Columns screen policy is invalid.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$normalized = self::normalize_screen_policy( $screen_policy );
			if ( self::screen_policy_is_empty( $normalized ) ) {
				continue;
			}
			$screens[ $screen_id ] = $normalized;
		}

		ksort( $screens, SORT_STRING );
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'screens'        => $screens,
		];
	}

	/**
	 * Normalize one screen policy. Patch A documents are accepted without the
	 * additional-column keys and canonicalized to empty source lists.
	 *
	 * @return array{order:list<string>,hidden:list<string>,taxonomies:list<string>,meta:list<string>}
	 */
	public static function normalize_screen_policy( array $screen_policy ): array {
		$unknown = array_diff( array_keys( $screen_policy ), [ 'order', 'hidden', 'taxonomies', 'meta' ] );
		if ( [] !== $unknown || ! array_key_exists( 'order', $screen_policy ) || ! array_key_exists( 'hidden', $screen_policy ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns screen policy contains unsupported fields.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$hidden = self::normalize_column_ids( $screen_policy['hidden'] ?? null );
		$order = self::normalize_column_ids( $screen_policy['order'] ?? null );
		$taxonomies = self::normalize_taxonomies( $screen_policy['taxonomies'] ?? [] );
		$meta = self::normalize_meta_keys( $screen_policy['meta'] ?? [] );

		foreach ( $hidden as $column_id ) {
			if ( in_array( $column_id, self::PROTECTED_VISIBILITY_COLUMNS, true ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'Structural WordPress columns cannot be hidden by Admin Columns Governance.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( ! in_array( $column_id, $order, true ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'Hidden Admin Columns identities must also exist in the governed order.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		if ( in_array( 'cb', $order, true ) && 'cb' !== ( $order[0] ?? null ) ) {
			$order = array_values( array_filter( $order, static fn( string $id ): bool => 'cb' !== $id ) );
			array_unshift( $order, 'cb' );
		}

		$hidden_lookup = array_fill_keys( $hidden, true );
		$hidden = array_values( array_filter(
			$order,
			static fn( string $id ): bool => isset( $hidden_lookup[ $id ] )
		) );

		return [
			'order'      => $order,
			'hidden'     => $hidden,
			'taxonomies' => $taxonomies,
			'meta'       => $meta,
		];
	}

	/**
	 * Canonically replace the complete policy.
	 *
	 * This is the only production writer for the Base-owned option.
	 */
	public static function replace( array $policy, string $actor ): bool {
		$next = self::normalize( $policy );
		$current = self::get();
		if ( $current === $next ) {
			return false;
		}

		$changed_screens = self::changed_screen_ids( $current, $next );
		if ( [] === $next['screens'] ) {
			if ( ! delete_option( self::OPTION ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \RuntimeException( __( 'The Admin Columns policy could not be reset.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		} elseif ( ! update_option( self::OPTION, $next, false ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'The Admin Columns policy could not be saved.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		AuditLog::log( 'settings.changed', 'notice', [
			'key'                => 'admin_columns_policy',
			'actor'              => substr( sanitize_text_field( $actor ), 0, 191 ),
			'changed_screens'    => $changed_screens,
			'changed_count'      => count( $changed_screens ),
			'before_screen_count'=> count( $current['screens'] ),
			'after_screen_count' => count( $next['screens'] ),
			'before_fingerprint' => self::fingerprint( $current ),
			'after_fingerprint'  => self::fingerprint( $next ),
			'mutation_id'        => wp_generate_uuid4(),
		] );
		return true;
	}

	/** @param array{order:list<string>,hidden:list<string>,taxonomies?:list<string>,meta?:list<string>} $screen_policy */
	public static function replace_screen( string $screen_id, array $screen_policy, string $actor ): bool {
		if ( ! self::valid_screen_id( $screen_id ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns screen identity is invalid.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$normalized = self::normalize_screen_policy( $screen_policy );
		$policy = self::get();
		if ( self::screen_policy_is_empty( $normalized ) ) {
			unset( $policy['screens'][ $screen_id ] );
		} else {
			$policy['screens'][ $screen_id ] = $normalized;
		}
		return self::replace( $policy, $actor );
	}

	public static function reset_screen( string $screen_id, string $actor ): bool {
		if ( ! self::valid_screen_id( $screen_id ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns screen identity is invalid.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$policy = self::get();
		unset( $policy['screens'][ $screen_id ] );
		return self::replace( $policy, $actor );
	}

	public static function reset( string $actor ): bool {
		return self::replace( self::empty_policy(), $actor );
	}

	private static function valid_screen_id( string $screen_id ): bool {
		return 1 === preg_match( '/^edit-[a-z0-9_-]{1,20}$/', $screen_id );
	}

	/** @return list<string> */
	private static function normalize_column_ids( mixed $value ): array {
		return self::normalize_byte_id_list( $value, __( 'The Admin Columns identity list is invalid.', 'core-blueprint' ) );
	}

	/** @return list<string> */
	private static function normalize_meta_keys( mixed $value ): array {
		return self::normalize_byte_id_list( $value, __( 'The Admin Columns meta source list is invalid.', 'core-blueprint' ) );
	}

	/** @return list<string> */
	private static function normalize_byte_id_list( mixed $value, string $error ): array {
		if ( ! is_array( $value ) || ( [] !== $value && ! array_is_list( $value ) ) || count( $value ) > self::MAX_COLUMNS_PER_SCREEN ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( $error );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$normalized = [];
		foreach ( $value as $id ) {
			if (
				! is_string( $id )
				|| '' === $id
				|| strlen( $id ) > self::MAX_COLUMN_ID_BYTES
				|| wp_check_invalid_utf8( $id ) !== $id
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $id )
				|| in_array( $id, $normalized, true )
			) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( $error );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$normalized[] = $id;
		}
		return $normalized;
	}

	/** @return list<string> */
	private static function normalize_taxonomies( mixed $value ): array {
		if ( ! is_array( $value ) || ( [] !== $value && ! array_is_list( $value ) ) || count( $value ) > self::MAX_COLUMNS_PER_SCREEN ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns taxonomy source list is invalid.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$normalized = [];
		foreach ( $value as $taxonomy ) {
			if (
				! is_string( $taxonomy )
				|| '' === $taxonomy
				|| strlen( $taxonomy ) > 32
				|| sanitize_key( $taxonomy ) !== $taxonomy
				|| in_array( $taxonomy, $normalized, true )
			) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The Admin Columns taxonomy source list is invalid.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$normalized[] = $taxonomy;
		}
		return $normalized;
	}

	/** @param array{order:list<string>,hidden:list<string>,taxonomies:list<string>,meta:list<string>} $policy */
	private static function screen_policy_is_empty( array $policy ): bool {
		return [] === $policy['order'] && [] === $policy['hidden'] && [] === $policy['taxonomies'] && [] === $policy['meta'];
	}

	/** @return list<string> */
	private static function changed_screen_ids( array $before, array $after ): array {
		$ids = array_values( array_unique( array_merge(
			array_keys( $before['screens'] ?? [] ),
			array_keys( $after['screens'] ?? [] )
		) ) );
		$changed = [];
		foreach ( $ids as $id ) {
			if ( ( $before['screens'][ $id ] ?? null ) !== ( $after['screens'][ $id ] ?? null ) ) {
				$changed[] = (string) $id;
			}
		}
		sort( $changed, SORT_STRING );
		return $changed;
	}

	private static function fingerprint( array $policy ): string {
		$json = wp_json_encode( $policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	/** @param list<string> $expected */
	private static function assert_exact_keys( array $value, array $expected ): void {
		$actual = array_map( 'strval', array_keys( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The Admin Columns policy contains unsupported fields.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private function __construct() {}
}
