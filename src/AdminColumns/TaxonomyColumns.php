<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\AdminColumns;

defined( 'ABSPATH' ) || exit;

final class TaxonomyColumns {
	/** @var array<string,bool> */
	private static array $hooks = [];

	public static function attach( \WP_Screen $screen ): void {
		if ( ! SupportedScreen::is_supported( $screen ) ) {
			return;
		}
		$post_type = (string) $screen->post_type;
		$hook = 'manage_taxonomies_for_' . $post_type . '_columns';
		if ( isset( self::$hooks[ $hook ] ) ) {
			return;
		}
		self::$hooks[ $hook ] = true;
		add_filter( $hook, [ self::class, 'filter_taxonomies' ], 20, 2 );
	}

	/** @param string[] $taxonomies @return string[] */
	public static function filter_taxonomies( array $taxonomies, string $post_type ): array {
		$policy = PolicyRepository::screen( 'edit-' . $post_type );
		if ( null === $policy ) {
			return $taxonomies;
		}
		$catalog = self::catalog( $post_type );
		foreach ( $policy['taxonomies'] as $taxonomy ) {
			if ( isset( $catalog[ $taxonomy ] ) && ! in_array( $taxonomy, $taxonomies, true ) ) {
				$taxonomies[] = $taxonomy;
			}
		}
		return $taxonomies;
	}

	/** @return array<string,array{label:string,column_id:string}> */
	public static function catalog( string $post_type ): array {
		$objects = get_object_taxonomies( $post_type, 'objects' );
		$catalog = [];
		foreach ( $objects as $taxonomy => $object ) {
			if ( ! is_string( $taxonomy ) || ! $object instanceof \WP_Taxonomy ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) ( $object->labels->singular_name ?? $object->label ?? $taxonomy ), true ) );
			$catalog[ $taxonomy ] = [
				'label'     => '' !== $label ? $label : $taxonomy,
				'column_id' => self::column_id( $taxonomy ),
			];
		}
		ksort( $catalog, SORT_STRING );
		return $catalog;
	}

	public static function column_id( string $taxonomy ): string {
		return match ( $taxonomy ) {
			'category' => 'categories',
			'post_tag' => 'tags',
			default    => 'taxonomy-' . $taxonomy,
		};
	}

	/** @internal */
	public static function _reset_for_testing(): void {
		foreach ( array_keys( self::$hooks ) as $hook ) {
			remove_filter( $hook, [ self::class, 'filter_taxonomies' ], 20 );
		}
		self::$hooks = [];
	}

	private function __construct() {}
}
