<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\AdminColumns;

use CoreBlueprint\Core\ContentModels\FieldTypes;
use CoreBlueprint\Core\ContentModels\LocationMatcher;
use CoreBlueprint\Core\ContentModels\Repository;

defined( 'ABSPATH' ) || exit;

final class RegisteredMetaColumns {
	private const COLUMN_FILTER_PRIORITY = PHP_INT_MAX - 100;
	private const DISPLAY_MAX_CHARS = 200;
	private const SCALAR_TYPES = [ 'string', 'integer', 'number', 'boolean' ];

	/** @var array<string,string> */
	private static array $column_hooks = [];
	/** @var array<string,string> */
	private static array $render_hooks = [];
	/** @var array<string,array<string,string>> */
	private static array $active_columns = [];
	/** @var array<string,array<string,array<string,mixed>>> */
	private static array $catalog_cache = [];

	public static function attach( \WP_Screen $screen ): void {
		if ( ! SupportedScreen::is_supported( $screen ) ) {
			return;
		}
		$post_type = (string) $screen->post_type;
		$screen_id = (string) $screen->id;
		$column_hook = 'manage_' . $screen_id . '_columns';
		if ( ! isset( self::$column_hooks[ $column_hook ] ) ) {
			self::$column_hooks[ $column_hook ] = $post_type;
			add_filter( $column_hook, [ self::class, 'filter_columns' ], self::COLUMN_FILTER_PRIORITY );
		}

		$render_hook = 'manage_' . $post_type . '_posts_custom_column';
		if ( ! isset( self::$render_hooks[ $render_hook ] ) ) {
			self::$render_hooks[ $render_hook ] = $post_type;
			add_action( $render_hook, [ self::class, 'render_column' ], 10, 2 );
		}
	}

	/** @param array<string,mixed> $columns @return array<string,mixed> */
	public static function filter_columns( array $columns ): array {
		$post_type = self::$column_hooks[ current_filter() ] ?? '';
		if ( '' === $post_type ) {
			return $columns;
		}
		$policy = PolicyRepository::screen( 'edit-' . $post_type );
		if ( null === $policy || [] === $policy['meta'] ) {
			return $columns;
		}
		$catalog = self::catalog( $post_type );
		foreach ( $policy['meta'] as $meta_key ) {
			if ( ! isset( $catalog[ $meta_key ] ) ) {
				continue;
			}
			$column_id = $catalog[ $meta_key ]['column_id'];
			if ( ! array_key_exists( $column_id, $columns ) ) {
				$columns[ $column_id ] = $catalog[ $meta_key ]['label'];
				self::$active_columns[ $post_type ][ $column_id ] = $meta_key;
			}
		}
		return $columns;
	}

	public static function render_column( string $column_id, int $post_id ): void {
		$post_type = self::$render_hooks[ current_filter() ] ?? '';
		if ( '' === $post_type ) {
			return;
		}
		$meta_key = self::$active_columns[ $post_type ][ $column_id ] ?? null;
		if ( ! is_string( $meta_key ) ) {
			return;
		}
		$entry = self::catalog( $post_type )[ $meta_key ] ?? null;
		if ( ! is_array( $entry ) || ! current_user_can( 'edit_post_meta', $post_id, $meta_key ) ) {
			return;
		}
		$value = get_registered_metadata( 'post', $post_id, $meta_key );
		$display = self::display_value( $value, $entry['type'] );
		if ( null !== $display ) {
			echo esc_html( $display );
		}
	}

	/**
	 * @return array<string,array{meta_key:string,column_id:string,label:string,type:string,content_model:bool}>
	 */
	public static function catalog( string $post_type ): array {
		if ( isset( self::$catalog_cache[ $post_type ] ) ) {
			return self::$catalog_cache[ $post_type ];
		}
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return [];
		}
		$generic = get_registered_meta_keys( 'post' );
		$specific = get_registered_meta_keys( 'post', $post_type );
		$registrations = array_replace(
			is_array( $generic ) ? $generic : [],
			is_array( $specific ) ? $specific : []
		);
		$content_models = self::content_model_fields( $post_type );
		$catalog = [];

		foreach ( $registrations as $meta_key => $args ) {
			if ( ! is_string( $meta_key ) || ! is_array( $args ) || '' === $meta_key ) {
				continue;
			}
			$type = (string) ( $args['type'] ?? '' );
			if (
				true !== ( $args['single'] ?? false )
				|| ! in_array( $type, self::SCALAR_TYPES, true )
				|| is_protected_meta( $meta_key, 'post' )
				|| wp_check_invalid_utf8( $meta_key ) !== $meta_key
			) {
				continue;
			}

			$content_model = $content_models[ $meta_key ] ?? null;
			if ( ! is_array( $content_model ) || ! self::is_content_model_registration( $args, $content_model ) ) {
				$content_model = null;
			}
			$label = isset( $args['label'] ) && is_scalar( $args['label'] )
				? trim( wp_strip_all_tags( (string) $args['label'], true ) )
				: '';
			if ( '' === $label && is_array( $content_model ) ) {
				$label = trim( wp_strip_all_tags( (string) ( $content_model['label'] ?? '' ), true ) );
			}
			if ( '' === $label ) {
				$label = $meta_key;
			}

			$catalog[ $meta_key ] = [
				'meta_key'      => $meta_key,
				'column_id'     => self::column_id( $meta_key ),
				'label'         => $label,
				'type'          => $type,
				'content_model' => is_array( $content_model ),
			];
		}
		ksort( $catalog, SORT_STRING );
		self::$catalog_cache[ $post_type ] = $catalog;
		return $catalog;
	}

	public static function column_id( string $meta_key ): string {
		return 'cb_admin_meta_' . hash( 'sha256', $meta_key );
	}

	private static function is_content_model_registration( array $args, array $field ): bool {
		$expected = FieldTypes::meta_args( $field );
		if ( (string) ( $args['type'] ?? '' ) !== (string) ( $expected['type'] ?? '' ) || true !== ( $args['single'] ?? false ) ) {
			return false;
		}
		$foundation = realpath( CB_CORE_DIR . 'src/ContentModels/FieldTypes.php' );
		if ( false === $foundation ) {
			return false;
		}
		foreach ( [ 'sanitize_callback', 'auth_callback' ] as $callback_key ) {
			$callback = $args[ $callback_key ] ?? null;
			if ( ! $callback instanceof \Closure ) {
				return false;
			}
			$file = ( new \ReflectionFunction( $callback ) )->getFileName();
			if ( false === $file || realpath( $file ) !== $foundation ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,array<string,mixed>> */
	private static function content_model_fields( string $post_type ): array {
		$fields = [];
		foreach ( Repository::field_groups() as $group ) {
			if ( ! is_array( $group ) || ! LocationMatcher::matches_post_type( $group, $post_type ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				if ( '' !== $name ) {
					$fields[ $name ] = $field;
				}
			}
		}
		return $fields;
	}

	private static function display_value( mixed $value, string $type ): ?string {
		if ( 'boolean' === $type ) {
			return (bool) $value ? __( 'Yes', 'core-blueprint' ) : __( 'No', 'core-blueprint' );
		}
		if ( in_array( $type, [ 'integer', 'number' ], true ) ) {
			return is_numeric( $value ) ? substr( (string) $value, 0, self::DISPLAY_MAX_CHARS ) : null;
		}
		if ( 'string' !== $type || ! is_scalar( $value ) ) {
			return null;
		}
		$plain = wp_check_invalid_utf8( (string) $value );
		$plain = trim( wp_strip_all_tags( $plain, true ) );
		return wp_html_excerpt( $plain, self::DISPLAY_MAX_CHARS, '…' );
	}

	/** @internal */
	public static function _reset_for_testing(): void {
		foreach ( self::$column_hooks as $hook => $_post_type ) {
			remove_filter( $hook, [ self::class, 'filter_columns' ], self::COLUMN_FILTER_PRIORITY );
		}
		foreach ( self::$render_hooks as $hook => $_post_type ) {
			remove_action( $hook, [ self::class, 'render_column' ], 10 );
		}
		self::$column_hooks = [];
		self::$render_hooks = [];
		self::$active_columns = [];
		self::$catalog_cache = [];
	}

	private function __construct() {}
}
