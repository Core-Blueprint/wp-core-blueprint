<?php
declare(strict_types=1);
/**
 * Collision preflight for Clean Archive URLs.
 *
 * The preflight is intentionally conservative. A known collision blocks
 * activation; uncertainty remains visible as a warning instead of being hidden.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Routing;

use CB\Core\Setup\Fingerprint;
use WP_Post_Type;
use WP_Taxonomy;

defined( 'ABSPATH' ) || exit;

final class Preflight {

	private const RESERVED_ROOT_SEGMENTS = [
		'author',
		'comments',
		'embed',
		'feed',
		'page',
		'search',
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
	];

	/**
	 * @return array{
	 *   ready:bool,
	 *   fingerprint:string,
	 *   category_count:int,
	 *   blockers:string[],
	 *   warnings:string[],
	 *   checked_at:int
	 * }
	 */
	public static function run(): array {
		$blockers = [];
		$warnings = [];
		$routes   = CategoryRoutes::all();
		$cpt      = self::public_post_type_archive_paths();
		$tax      = self::public_taxonomy_paths();

		foreach ( $routes as $path => $term ) {
			$first_segment = explode( '/', $path, 2 )[0] ?? '';
			if ( in_array( $first_segment, self::RESERVED_ROOT_SEGMENTS, true ) ) {
				$blockers[] = sprintf(
					/* translators: 1: category name, 2: route segment */
					__( 'Category "%1$s" uses the reserved root route "/%2$s/".', 'core-blueprint' ),
					$term->name,
					$first_segment
				);
			}

			$page = get_page_by_path( $path, OBJECT, 'page' );
			if ( $page instanceof \WP_Post ) {
				$blockers[] = sprintf(
					/* translators: 1: category name, 2: route path */
					__( 'Category "%1$s" conflicts with the existing Page route "/%2$s/".', 'core-blueprint' ),
					$term->name,
					$path
				);
			}

			if ( isset( $cpt[ $path ] ) ) {
				$blockers[] = sprintf(
					/* translators: 1: category name, 2: post type label, 3: route path */
					__( 'Category "%1$s" conflicts with the "%2$s" post type archive at "/%3$s/".', 'core-blueprint' ),
					$term->name,
					$cpt[ $path ],
					$path
				);
			}

			if ( isset( $tax[ $path ] ) ) {
				$blockers[] = sprintf(
					/* translators: 1: category name, 2: taxonomy label, 3: route path */
					__( 'Category "%1$s" conflicts with the "%2$s" taxonomy route at "/%3$s/".', 'core-blueprint' ),
					$term->name,
					$tax[ $path ],
					$path
				);
			}

			foreach ( self::reserved_post_slugs( (int) $term->term_id ) as $post_slug ) {
				$blockers[] = sprintf(
					/* translators: 1: post slug, 2: category name, 3: category route */
					__( 'Post slug "%1$s" in category "%2$s" conflicts with reserved pagination route "/%3$s/%1$s/".', 'core-blueprint' ),
					$post_slug,
					$term->name,
					$path
				);
			}
		}

		if ( [] === $routes ) {
			$warnings[] = __( 'No categories currently exist. New category routes will be added automatically while the policy is active.', 'core-blueprint' );
		} else {
			$warnings[] = __( 'Enabling this policy changes canonical category URLs. Existing WordPress category URLs will redirect to the clean routes.', 'core-blueprint' );
		}

		$permalink_structure = (string) get_option( 'permalink_structure', '' );
		if ( str_contains( $permalink_structure, '%category%' ) ) {
			$warnings[] = __( 'The current post permalink structure contains %category%. Core Blueprint will reserve p{n} inside category paths for archive pagination.', 'core-blueprint' );
		}

		$blockers = array_values( array_unique( $blockers ) );
		$warnings = array_values( array_unique( $warnings ) );

		$fingerprint = Fingerprint::hash(
			[
				'permalink_structure' => $permalink_structure,
				'category_base'       => CategoryRoutes::category_base_path(),
				'category_routes'     => array_keys( $routes ),
				'post_type_archives'  => $cpt,
				'taxonomy_routes'     => $tax,
			]
		);

		return [
			'ready'          => [] === $blockers,
			'fingerprint'    => $fingerprint,
			'category_count' => count( $routes ),
			'blockers'       => $blockers,
			'warnings'       => $warnings,
			'checked_at'     => time(),
		];
	}

	/** @return array<string,string> archive path => label */
	private static function public_post_type_archive_paths(): array {
		$paths = [];
		$types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $types as $type ) {
			if ( ! $type instanceof WP_Post_Type || ! $type->has_archive ) {
				continue;
			}

			$path = '';
			if ( is_string( $type->has_archive ) ) {
				$path = trim( $type->has_archive, '/' );
			} elseif ( is_array( $type->rewrite ) && isset( $type->rewrite['slug'] ) ) {
				$path = trim( (string) $type->rewrite['slug'], '/' );
			} else {
				$path = $type->name;
			}

			if ( '' !== $path ) {
				$paths[ $path ] = (string) $type->labels->singular_name;
			}
		}

		ksort( $paths, SORT_STRING );
		return $paths;
	}

	/** @return array<string,string> taxonomy route path => label */
	private static function public_taxonomy_paths(): array {
		$paths      = [];
		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy instanceof WP_Taxonomy || 'category' === $taxonomy->name || false === $taxonomy->rewrite ) {
				continue;
			}

			$path = is_array( $taxonomy->rewrite ) && isset( $taxonomy->rewrite['slug'] )
				? trim( (string) $taxonomy->rewrite['slug'], '/' )
				: $taxonomy->name;

			if ( '' !== $path ) {
				$paths[ $path ] = (string) $taxonomy->labels->singular_name;
			}
		}

		ksort( $paths, SORT_STRING );
		return $paths;
	}

	/** @return string[] */
	private static function reserved_post_slugs( int $term_id ): array {
		global $wpdb;

		if ( $term_id < 1 || ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.post_name
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = %s
				AND tt.term_id = %d
				AND p.post_type = %s
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND p.post_name LIKE %s
			LIMIT 100",
			'category',
			$term_id,
			'post',
			$wpdb->esc_like( 'p' ) . '%'
		);

		$slugs = is_string( $sql ) ? $wpdb->get_col( $sql ) : [];
		$out   = [];

		foreach ( (array) $slugs as $slug ) {
			$slug = (string) $slug;
			if ( 1 === preg_match( '/^p[0-9]+$/', $slug ) ) {
				$out[] = $slug;
			}
		}

		sort( $out, SORT_STRING );
		return array_values( array_unique( $out ) );
	}

	private function __construct() {}
}
