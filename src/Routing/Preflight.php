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

			foreach ( self::public_content_collisions( $path ) as $collision ) {
				$blockers[] = sprintf(
					/* translators: 1: category name, 2: content label, 3: route path */
					__( 'Category "%1$s" conflicts with existing public content "%2$s" at "/%3$s/".', 'core-blueprint' ),
					$term->name,
					$collision,
					$path
				);
			}

			foreach ( self::public_pagination_content_collisions( $path ) as $collision_path => $collision ) {
				$blockers[] = sprintf(
					/* translators: 1: category name, 2: content label, 3: route path */
					__( 'Category "%1$s" conflicts with existing public content "%2$s" at "/%3$s/".', 'core-blueprint' ),
					$term->name,
					$collision,
					$collision_path
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

			foreach ( self::public_taxonomy_term_collisions( $path ) as $taxonomy_label ) {
				$blockers[] = sprintf(
					/* translators: 1: category name, 2: taxonomy label, 3: route path */
					__( 'Category "%1$s" conflicts with the "%2$s" taxonomy route at "/%3$s/".', 'core-blueprint' ),
					$term->name,
					$taxonomy_label,
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

	/** @return string[] */
	private static function public_content_collisions( string $path ): array {
		$slug = basename( trim( $path, '/' ) );
		if ( '' === $slug ) {
			return [];
		}

		$post_types = array_values( get_post_types( [ 'public' => true ], 'names' ) );
		if ( [] === $post_types ) {
			return [];
		}

		$candidates = [];

		$exact = get_page_by_path( trim( $path, '/' ), OBJECT, $post_types );
		$public_statuses = array_values( get_post_stati( [ 'public' => true ], 'names' ) );
		if ( $exact instanceof \WP_Post && in_array( $exact->post_status, $public_statuses, true ) ) {
			$candidates[ $exact->ID ] = $exact;
		}

		$slug_candidates = get_posts(
			[
				'post_type'        => $post_types,
				'post_status'      => array_values( get_post_stati( [ 'public' => true ], 'names' ) ),
				'name'             => $slug,
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		foreach ( $slug_candidates as $candidate ) {
			if ( $candidate instanceof \WP_Post ) {
				$candidates[ $candidate->ID ] = $candidate;
			}
		}

		$collisions = [];
		foreach ( $candidates as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$permalink = get_permalink( $post );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			if ( self::relative_public_path( $permalink ) === trim( $path, '/' ) ) {
				$collisions[] = self::public_post_label( $post );
			}
		}

		sort( $collisions, SORT_STRING );
		return array_values( array_unique( $collisions ) );
	}

	/** @return array<string,string> public route path => content label */
	private static function public_pagination_content_collisions( string $path ): array {
		$prefix     = trim( $path, '/' );
		$collisions = [];

		foreach ( self::public_pagination_candidates() as $public_path => $label ) {
			if ( 1 === preg_match( '#^' . preg_quote( $prefix, '#' ) . '/p[0-9]+$#', $public_path ) ) {
				$collisions[ $public_path ] = $label;
			}
		}

		ksort( $collisions, SORT_STRING );
		return $collisions;
	}

	/** @return array<string,string> public route path => content label */
	private static function public_pagination_candidates(): array {
		global $wpdb;

		static $cached_signature  = null;
		static $cached_candidates = [];

		$post_types    = array_values( get_post_types( [ 'public' => true ], 'names' ) );
		$post_statuses = array_values( get_post_stati( [ 'public' => true ], 'names' ) );
		sort( $post_types, SORT_STRING );
		sort( $post_statuses, SORT_STRING );

		$signature = Fingerprint::hash(
			[
				'posts_last_changed' => (string) wp_cache_get_last_changed( 'posts' ),
				'post_types'         => $post_types,
				'post_statuses'      => $post_statuses,
			]
		);
		if ( null !== $cached_signature && hash_equals( $cached_signature, $signature ) ) {
			return $cached_candidates;
		}

		if ( [] === $post_types || [] === $post_statuses || ! $wpdb instanceof \wpdb ) {
			$cached_signature  = $signature;
			$cached_candidates = [];
			return [];
		}

		$type_placeholders   = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
		$args                = array_merge( $post_types, $post_statuses, [ '^p[0-9]+$' ] );
		$sql                 = $wpdb->prepare(
			"SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type IN ({$type_placeholders})
				AND post_status IN ({$status_placeholders})
				AND post_name REGEXP %s",
			...$args
		);
		$ids = is_string( $sql ) ? $wpdb->get_col( $sql ) : [];

		$candidates = [];
		foreach ( (array) $ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$permalink = get_permalink( $post );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$public_path = self::relative_public_path( $permalink );
			if ( '' !== $public_path ) {
				$candidates[ $public_path ] = self::public_post_label( $post );
			}
		}

		ksort( $candidates, SORT_STRING );
		$cached_signature  = $signature;
		$cached_candidates = $candidates;
		return $candidates;
	}


	/** @return string[] */
	private static function public_taxonomy_term_collisions( string $path ): array {
		$slug       = basename( trim( $path, '/' ) );
		$collisions = [];

		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) {
			if (
				! $taxonomy instanceof WP_Taxonomy
				|| 'category' === $taxonomy->name
				|| false === $taxonomy->rewrite
			) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, $taxonomy->name );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$link = get_term_link( $term, $taxonomy->name );
			if ( is_wp_error( $link ) || ! is_string( $link ) ) {
				continue;
			}

			if ( self::relative_public_path( $link ) === trim( $path, '/' ) ) {
				$collisions[] = (string) $taxonomy->labels->singular_name;
			}
		}

		sort( $collisions, SORT_STRING );
		return array_values( array_unique( $collisions ) );
	}

	private static function public_post_label( \WP_Post $post ): string {
		$type  = get_post_type_object( $post->post_type );
		$label = $type instanceof WP_Post_Type
			? (string) $type->labels->singular_name
			: (string) $post->post_type;

		return sprintf(
			/* translators: 1: content title, 2: content type */
			__( '%1$s (%2$s)', 'core-blueprint' ),
			get_the_title( $post ),
			$label
		);
	}

	private static function relative_public_path( string $url ): string {
		$path      = '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$home_path = '/' . trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '/' !== $home_path && str_starts_with( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		} elseif ( $path === $home_path ) {
			$path = '/';
		}

		return trim( rawurldecode( $path ), '/' );
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


	private function __construct() {}
}
