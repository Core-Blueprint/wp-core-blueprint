<?php
declare(strict_types=1);
/**
 * SectionTypeRegistry - controlled HUD section presentation contracts.
 *
 * Section types are declarative. Base owns placement, rendering, escaping and
 * interaction behaviour; extensions can only select supported presentation
 * primitives and item shapes. Arbitrary renderer callbacks/markup are not a
 * public HUD contract.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\HUD;

defined( 'ABSPATH' ) || exit;

final class SectionTypeRegistry {

	private const BUILTIN_IDS = [ 'navigation', 'quick-actions', 'status' ];
	private const PRESENTATIONS = [ 'list', 'metrics', 'status-strip' ];
	private const ITEM_TYPES = [ 'link', 'stat' ];

	/** @var array<string, array<string, mixed>> */
	private static array $types = [];

	/** Register the canonical Base-owned section types. Idempotent. */
	public static function register_builtins(): void {
		self::register_builtin( [
			'id'                  => 'navigation',
			'presentation'        => 'list',
			'placement'           => 'body',
			'item_types'          => [ 'link' ],
			'capability'          => '',
			'manageable'          => true,
			'default_columns'     => 2,
			'default_collapsible' => true,
			'max_items'           => 0,
		] );
		self::register_builtin( [
			'id'                  => 'quick-actions',
			'presentation'        => 'list',
			'placement'           => 'body',
			'item_types'          => [ 'link' ],
			'capability'          => '',
			'manageable'          => true,
			'default_columns'     => 1,
			'default_collapsible' => true,
			'max_items'           => 0,
			'recommended_max_items' => 4,
		] );
		self::register_builtin( [
			'id'                  => 'status',
			'presentation'        => 'status-strip',
			'placement'           => 'header',
			'item_types'          => [ 'stat' ],
			'capability'          => '',
			'manageable'          => false,
			'default_columns'     => 1,
			'default_collapsible' => false,
			'max_items'           => 0,
		] );
	}

	/**
	 * Register a custom namespaced HUD section type.
	 *
	 * Custom types are deliberately restricted to body placement and Base-owned
	 * list/metrics presentation primitives. The type capability is required and
	 * is enforced independently from any per-section or per-item capability.
	 *
	 * Shape:
	 * - id: namespaced `vendor/type` identifier;
	 * - presentation: `list` or `metrics`;
	 * - item_types: subset compatible with the presentation;
	 * - capability: WordPress capability required to use this type;
	 * - manageable: whether sections may be reordered/hidden in HUD Preferences;
	 * - default_columns: 1 or 2;
	 * - default_collapsible: bool;
	 * - max_items: 0 for unlimited, otherwise positive integer.
	 *
	 * @param array<string, mixed> $definition Type definition.
	 */
	public static function register( array $definition ): bool {
		$id = trim( (string) ( $definition['id'] ?? '' ) );
		if ( ! self::is_valid_custom_id( $id ) ) {
			self::diagnostic( __METHOD__, 'Custom HUD section type IDs must use canonical namespaced form, e.g. "vendor/metrics".' );
			return false;
		}
		if ( isset( self::$types[ $id ] ) || in_array( $id, self::BUILTIN_IDS, true ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section type "%s" is already registered or reserved by Base.', $id ) );
			return false;
		}

		foreach ( [ 'renderer', 'render', 'callback', 'html', 'markup', 'placement' ] as $forbidden_key ) {
			if ( array_key_exists( $forbidden_key, $definition ) ) {
				self::diagnostic( __METHOD__, sprintf( 'HUD section type "%s" attempted to control Base-owned rendering or placement.', $id ) );
				return false;
			}
		}

		$presentation = (string) ( $definition['presentation'] ?? '' );
		if ( ! in_array( $presentation, [ 'list', 'metrics' ], true ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section type "%s" requested an unsupported presentation primitive.', $id ) );
			return false;
		}

		$capability = trim( (string) ( $definition['capability'] ?? '' ) );
		if ( '' === $capability || sanitize_key( $capability ) !== $capability ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section type "%s" must declare a valid WordPress capability.', $id ) );
			return false;
		}

		$item_types = self::normalize_item_types( $definition['item_types'] ?? [], $presentation );
		if ( null === $item_types || [] === $item_types ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section type "%s" has no valid item types.', $id ) );
			return false;
		}

		$columns = (int) ( $definition['default_columns'] ?? 1 );
		if ( ! in_array( $columns, [ 1, 2 ], true ) ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section type "%s" must use default_columns 1 or 2.', $id ) );
			return false;
		}

		$max_items = (int) ( $definition['max_items'] ?? 0 );
		if ( $max_items < 0 ) {
			self::diagnostic( __METHOD__, sprintf( 'HUD section type "%s" cannot declare a negative max_items value.', $id ) );
			return false;
		}
		self::$types[ $id ] = [
			'id'                  => $id,
			'presentation'        => $presentation,
			'placement'           => 'body',
			'item_types'          => $item_types,
			'capability'          => $capability,
			'manageable'          => (bool) ( $definition['manageable'] ?? true ),
			'default_columns'     => $columns,
			'default_collapsible' => (bool) ( $definition['default_collapsible'] ?? false ),
			'max_items'           => $max_items,
		];
		return true;
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		return self::$types[ $id ] ?? null;
	}

	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		return self::$types;
	}

	public static function item_type_allowed( string $type_id, string $item_type ): bool {
		$type = self::get( $type_id );
		return is_array( $type ) && in_array( $item_type, (array) ( $type['item_types'] ?? [] ), true );
	}

	/** Reset request-local registry state. Internal test support, not public API. */
	public static function reset(): void {
		self::$types = [];
	}

	/** @param array<string, mixed> $definition */
	private static function register_builtin( array $definition ): void {
		$id = (string) ( $definition['id'] ?? '' );
		if ( '' === $id || isset( self::$types[ $id ] ) ) {
			return;
		}
		self::$types[ $id ] = $definition;
	}

	private static function is_valid_custom_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*\/[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
	}

	/** @return array<int, string>|null */
	private static function normalize_item_types( mixed $value, string $presentation ): ?array {
		if ( ! is_array( $value ) || [] === $value ) {
			return null;
		}
		$allowed_by_presentation = 'metrics' === $presentation ? [ 'stat' ] : [ 'link' ];
		$clean = [];
		foreach ( $value as $item_type ) {
			if ( ! is_string( $item_type ) || ! in_array( $item_type, self::ITEM_TYPES, true ) || ! in_array( $item_type, $allowed_by_presentation, true ) ) {
				return null;
			}
			$clean[] = $item_type;
		}
		return array_values( array_unique( $clean ) );
	}

	private static function diagnostic( string $method, string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( esc_html( $method ), esc_html( $message ), '1.0.0' );
		}
	}
}
