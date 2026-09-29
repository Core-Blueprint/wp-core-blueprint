<?php
declare(strict_types=1);
/**
 * Category route catalog for URL Governance.
 *
 * The catalog intentionally expands only real category terms. This avoids a
 * generic root catch-all rewrite that could steal Pages, CPTs or endpoints.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Routing;

use WP_Term;

defined( 'ABSPATH' ) || exit;

final class CategoryRoutes {

	/** @return array<string,WP_Term> Canonical category path => term. */
	public static function all(): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$routes = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$path = self::path( $term );
			if ( '' !== $path ) {
				$routes[ $path ] = $term;
			}
		}

		ksort( $routes, SORT_STRING );
		return $routes;
	}

	public static function path( WP_Term $term ): string {
		if ( 'category' !== $term->taxonomy ) {
			return '';
		}

		$slugs     = [];
		$ancestors = array_reverse( get_ancestors( (int) $term->term_id, 'category', 'taxonomy' ) );

		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, 'category' );
			if ( $ancestor instanceof WP_Term && '' !== $ancestor->slug ) {
				$slugs[] = $ancestor->slug;
			}
		}

		if ( '' !== $term->slug ) {
			$slugs[] = $term->slug;
		}

		return implode( '/', $slugs );
	}

	public static function category_base_path(): string {
		global $wp_rewrite;

		$base = '';
		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			$base = trim( (string) $wp_rewrite->category_base, '/' );
		}
		if ( '' === $base ) {
			$base = trim( (string) get_option( 'category_base', '' ), '/' );
		}

		if ( '.' === $base ) {
			return '';
		}

		return '' !== $base ? $base : 'category';
	}

	public static function canonical_url( WP_Term $term, int $page = 1 ): string {
		$path = self::path( $term );
		if ( '' === $path ) {
			return home_url( '/' );
		}

		if ( $page > 1 ) {
			$path .= '/p' . $page;
			return home_url( user_trailingslashit( $path, 'paged' ) );
		}

		return home_url( user_trailingslashit( $path, 'category' ) );
	}

	public static function feed_url( WP_Term $term, string $feed = '' ): string {
		$path = self::path( $term );
		if ( '' === $path ) {
			return home_url( '/' );
		}

		$path .= '/feed';
		if ( '' !== $feed && 'feed' !== $feed ) {
			$path .= '/' . sanitize_key( $feed );
		}

		return home_url( user_trailingslashit( $path, 'feed' ) );
	}

	private function __construct() {}
}
