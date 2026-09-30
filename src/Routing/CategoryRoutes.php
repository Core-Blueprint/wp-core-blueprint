<?php
declare(strict_types=1);
/**
 * Category route catalog for URL Governance.
 *
 * The catalog intentionally expands only real category terms. This avoids a
 * generic root catch-all rewrite that could steal Pages, CPTs or endpoints.
 *
 * URL generation preserves the context WordPress and other plugins already
 * resolved. Core Blueprint owns only the category-base removal and compact
 * pagination/feed suffixes.
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
				'taxonomy'         => 'category',
				'hide_empty'       => false,
				// Route generation must see the canonical WordPress term set.
				// Providers that honor the standard suppression contract should
				// not scope this infrastructure query to a presentation context.
				'suppress_filters' => true,
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
		$base = trim( (string) get_option( 'category_base', '' ), '/' );

		if ( '.' === $base ) {
			return '';
		}

		return '' !== $base ? $base : 'category';
	}

	/**
	 * Remove only the WordPress category-base segment from the raw category
	 * permastruct. WordPress then finishes the term URL and downstream filters
	 * remain free to add language directories, mapped domains or query context.
	 */
	public static function clean_permastruct( string $termlink ): string {
		$base = trim( self::category_base_path(), '/' );
		if ( '' === $base || ! str_contains( $termlink, '%category%' ) ) {
			return $termlink;
		}

		$pattern = '#(^|/)' . preg_quote( $base, '#' ) . '/(?=%category%(?:/|$))#';
		$clean   = preg_replace( $pattern, '$1', $termlink, 1 );

		return is_string( $clean ) && '' !== $clean ? $clean : $termlink;
	}

	public static function canonical_url( WP_Term $term, int $page = 1 ): string {
		$link = get_term_link( $term, 'category' );
		if ( is_wp_error( $link ) || ! is_string( $link ) || '' === $link ) {
			return '';
		}

		return self::pagination_url( $link, $term, max( 1, $page ) );
	}

	public static function pagination_url( string $url, WP_Term $term, int $page ): string {
		$suffix = $page > 1 ? [ 'p' . $page ] : [];
		return self::rewrite_term_tail( $url, $term, $suffix, $page > 1 ? 'paged' : 'category' );
	}

	public static function feed_url( WP_Term $term, string $feed = '' ): string {
		$link = self::canonical_url( $term );
		if ( '' === $link ) {
			return '';
		}

		$feed         = sanitize_key( $feed );
		$default_feed = sanitize_key( (string) get_default_feed() );
		$suffix       = [ 'feed' ];

		if ( '' !== $feed && 'feed' !== $feed && $default_feed !== $feed ) {
			$suffix[] = $feed;
		}

		return self::rewrite_term_tail( $link, $term, $suffix, 'feed' );
	}

	/**
	 * Preserve every URL component outside the category route itself.
	 *
	 * Examples of preserved context include a WordPress subdirectory, a language
	 * directory, a mapped language domain, and query-based language negotiation.
	 * Unknown shapes fail open and return the original URL unchanged.
	 *
	 * @param string[] $suffix
	 */
	private static function rewrite_term_tail( string $url, WP_Term $term, array $suffix, string $trail_type ): string {
		$old_path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $old_path ) || '' === $old_path ) {
			return $url;
		}

		$term_path = self::path( $term );
		if ( '' === $term_path ) {
			return $url;
		}

		$path_segments = self::segments( $old_path );
		$term_segments = self::segments( $term_path );
		if ( [] === $path_segments || [] === $term_segments || count( $path_segments ) < count( $term_segments ) ) {
			return $url;
		}

		$decoded_path = array_map( 'rawurldecode', $path_segments );
		$decoded_term = array_map( 'rawurldecode', $term_segments );
		$term_count   = count( $term_segments );

		for ( $start = count( $path_segments ) - $term_count; $start >= 0; $start-- ) {
			if ( array_slice( $decoded_path, $start, $term_count ) !== $decoded_term ) {
				continue;
			}

			$tail = array_slice( $decoded_path, $start + $term_count );
			if ( ! self::recognized_route_tail( $tail ) ) {
				continue;
			}

			$prefix = array_slice( $path_segments, 0, $start );
			$prefix = self::without_known_category_base( $prefix );
			$route  = array_slice( $path_segments, $start, $term_count );
			$parts  = array_merge( $prefix, $route, $suffix );

			$new_path = '/' . implode( '/', $parts );
			$new_path = '/' . ltrim( user_trailingslashit( ltrim( $new_path, '/' ), $trail_type ), '/' );

			$offset = strpos( $url, $old_path );
			if ( false === $offset ) {
				return $url;
			}

			return substr( $url, 0, $offset )
				. $new_path
				. substr( $url, $offset + strlen( $old_path ) );
		}

		return $url;
	}

	/** @param string[] $tail */
	private static function recognized_route_tail( array $tail ): bool {
		if ( [] === $tail ) {
			return true;
		}

		if ( 1 === count( $tail ) && 1 === preg_match( '/^p[0-9]+$/', $tail[0] ) ) {
			return true;
		}

		if (
			2 === count( $tail )
			&& 'page' === $tail[0]
			&& ctype_digit( $tail[1] )
		) {
			return true;
		}

		if (
			3 === count( $tail )
			&& 1 === preg_match( '/^p[0-9]+$/', $tail[0] )
			&& 'page' === $tail[1]
			&& ctype_digit( $tail[2] )
		) {
			return true;
		}

		return false;
	}

	/** @param string[] $segments @return string[] */
	private static function without_known_category_base( array $segments ): array {
		$base = self::segments( self::category_base_path() );
		if ( [] === $base || count( $segments ) < count( $base ) ) {
			return $segments;
		}

		$offset = count( $segments ) - count( $base );
		if (
			array_map( 'rawurldecode', array_slice( $segments, $offset ) )
			=== array_map( 'rawurldecode', $base )
		) {
			return array_slice( $segments, 0, $offset );
		}

		return $segments;
	}

	/** @return string[] */
	private static function segments( string $path ): array {
		$path = trim( $path, '/' );
		return '' === $path
			? []
			: array_values( array_filter( explode( '/', $path ), static fn( string $part ): bool => '' !== $part ) );
	}

	private function __construct() {}
}
