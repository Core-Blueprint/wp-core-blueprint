<?php
declare(strict_types=1);
/**
 * Canonical bounded storage contract for Admin Navigation Governance.
 *
 * WordPress remains the navigation registry and authorization source of truth.
 * This policy stores only presentation overrides against WordPress identities.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\AdminNavigation;

defined( 'ABSPATH' ) || exit;

final class Policy {

	public const OPTION = 'cb_core_admin_navigation_policy';
	public const VERSION = 1;

	private const MAX_ORDER_ITEMS = 250;
	private const MAX_RULES = 250;
	private const MAX_IDENTITY_BYTES = 191;
	private const MAX_LABEL_BYTES = 120;

	/** @return array{version:int,menu:array{order:list<string>,hidden:list<array>},toolbar:array{hidden:list<array>,renamed:list<array>}} */
	public static function defaults(): array {
		return [
			'version' => self::VERSION,
			'menu'    => [
				'order'  => [],
				'hidden' => [],
			],
			'toolbar' => [
				'hidden'  => [],
				'renamed' => [],
			],
		];
	}

	/** Return a valid policy. Corrupt/foreign storage fails open to no overrides. */
	public static function get(): array {
		$stored = get_option( self::OPTION, null );
		if ( null === $stored || [] === $stored ) {
			return self::defaults();
		}
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		try {
			return self::normalize( $stored );
		} catch ( \InvalidArgumentException ) {
			return self::defaults();
		}
	}

	/** Replace the complete policy with one validated canonical document. */
	public static function replace( array $candidate ): bool {
		$normalized = self::normalize( $candidate );
		if ( self::defaults() === $normalized ) {
			if ( null === get_option( self::OPTION, null ) ) {
				return true;
			}
			return delete_option( self::OPTION );
		}

		if ( $normalized === self::get() && null !== get_option( self::OPTION, null ) ) {
			return true;
		}

		return update_option( self::OPTION, $normalized, false );
	}

	public static function reset(): bool {
		return null === get_option( self::OPTION, null ) || delete_option( self::OPTION );
	}

	/** @return array{version:int,menu:array{order:list<string>,hidden:list<array>},toolbar:array{hidden:list<array>,renamed:list<array>}} */
	public static function normalize( array $candidate ): array {
		self::assert_exact_keys( $candidate, [ 'version', 'menu', 'toolbar' ], 'policy' );
		if ( ! isset( $candidate['version'] ) || ! is_int( $candidate['version'] ) || self::VERSION !== $candidate['version'] ) {
			throw new \InvalidArgumentException( 'Admin Navigation policy version is not supported.' );
		}

		$menu = self::object( $candidate['menu'] ?? null, 'menu' );
		self::assert_exact_keys( $menu, [ 'order', 'hidden' ], 'menu' );
		$toolbar = self::object( $candidate['toolbar'] ?? null, 'toolbar' );
		self::assert_exact_keys( $toolbar, [ 'hidden', 'renamed' ], 'toolbar' );

		return [
			'version' => self::VERSION,
			'menu'    => [
				'order'  => self::normalize_order( $menu['order'] ?? null ),
				'hidden' => self::normalize_rules( $menu['hidden'] ?? null, false ),
			],
			'toolbar' => [
				'hidden'  => self::normalize_rules( $toolbar['hidden'] ?? null, false ),
				'renamed' => self::normalize_rules( $toolbar['renamed'] ?? null, true ),
			],
		];
	}

	public static function has_menu_order(): bool {
		return [] !== self::get()['menu']['order'];
	}

	/** Menu identities referenced by persisted overrides, in stable policy order. */
	public static function referenced_menu_identities(): array {
		$policy = self::get();
		$ids = $policy['menu']['order'];
		foreach ( $policy['menu']['hidden'] as $rule ) {
			$ids[] = $rule['id'];
		}
		return self::unique_identities( $ids );
	}

	/** Toolbar node IDs referenced by persisted overrides, in stable policy order. */
	public static function referenced_toolbar_identities(): array {
		$policy = self::get();
		$ids = [];
		foreach ( $policy['toolbar']['hidden'] as $rule ) {
			$ids[] = $rule['id'];
		}
		foreach ( $policy['toolbar']['renamed'] as $rule ) {
			$ids[] = $rule['id'];
		}
		return self::unique_identities( $ids );
	}

	/** Whether a WordPress menu/node identity is safe to persist verbatim. */
	public static function is_valid_identity( mixed $identity ): bool {
		if ( ! is_string( $identity ) ) {
			return false;
		}
		if ( '' === $identity || trim( $identity ) !== $identity || strlen( $identity ) > self::MAX_IDENTITY_BYTES ) {
			return false;
		}
		if ( wp_check_invalid_utf8( $identity ) !== $identity ) {
			return false;
		}
		return 1 !== preg_match( '/[\x00-\x1F\x7F]/', $identity );
	}

	/** @return list<string> */
	private static function normalize_order( mixed $order ): array {
		if ( ! is_array( $order ) || ! array_is_list( $order ) || count( $order ) > self::MAX_ORDER_ITEMS ) {
			throw new \InvalidArgumentException( 'Admin Navigation menu order is invalid.' );
		}
		return self::unique_identities( $order, true );
	}

	/** @return list<array<string,mixed>> */
	private static function normalize_rules( mixed $rules, bool $rename ): array {
		if ( ! is_array( $rules ) || ! array_is_list( $rules ) || count( $rules ) > self::MAX_RULES ) {
			throw new \InvalidArgumentException( 'Admin Navigation rule list is invalid.' );
		}

		$normalized = [];
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || array_is_list( $rule ) ) {
				throw new \InvalidArgumentException( 'Admin Navigation rule is invalid.' );
			}
			$keys = $rename ? [ 'id', 'label', 'audience' ] : [ 'id', 'audience' ];
			self::assert_exact_keys( $rule, $keys, 'rule' );
			$id = self::identity( $rule['id'] ?? null );
			$entry = [
				'id'       => $id,
				'audience' => Audience::normalize( self::object( $rule['audience'] ?? null, 'audience' ) ),
			];
			if ( $rename ) {
				$entry = [
					'id'       => $id,
					'label'    => self::label( $rule['label'] ?? null ),
					'audience' => $entry['audience'],
				];
			}
			$normalized[] = $entry;
		}
		return $normalized;
	}

	private static function identity( mixed $identity ): string {
		if ( ! self::is_valid_identity( $identity ) ) {
			throw new \InvalidArgumentException( 'Admin Navigation contains an invalid WordPress navigation identity.' );
		}
		return $identity;
	}

	private static function label( mixed $label ): string {
		if ( ! is_string( $label ) ) {
			throw new \InvalidArgumentException( 'Admin Navigation toolbar rename label is invalid.' );
		}
		$label = trim( sanitize_text_field( $label ) );
		if ( '' === $label || strlen( $label ) > self::MAX_LABEL_BYTES ) {
			throw new \InvalidArgumentException( 'Admin Navigation toolbar rename label is invalid.' );
		}
		return $label;
	}

	private static function object( mixed $value, string $name ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'Admin Navigation %s must be an object.', $name ) );
		}
		return $value;
	}

	private static function assert_exact_keys( array $value, array $allowed, string $name ): void {
		$actual = array_map( 'strval', array_keys( $value ) );
		$expected = array_values( array_map( 'strval', $allowed ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new \InvalidArgumentException( sprintf( 'Admin Navigation %s has an invalid schema.', $name ) );
		}
	}

	/** @return list<string> */
	private static function unique_identities( array $identities, bool $strict = false ): array {
		$unique = [];
		foreach ( $identities as $identity ) {
			if ( ! self::is_valid_identity( $identity ) ) {
				if ( $strict ) {
					throw new \InvalidArgumentException( 'Admin Navigation contains an invalid WordPress navigation identity.' );
				}
				continue;
			}
			$unique[ $identity ] = $identity;
		}
		return array_values( $unique );
	}

	private function __construct() {}
}
