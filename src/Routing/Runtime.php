<?php
declare(strict_types=1);
/**
 * Runtime URL Governance integration.
 *
 * Clean archive routing is opt-in. While disabled, this class leaves public
 * URLs and WordPress rewrite behaviour untouched.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Routing;

use WP_Term;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	private const REWRITE_DIRTY_OPTION = 'cb_core_routing_rewrite_dirty';
	private const RUNTIME_SUSPENDED_OPTION = 'cb_core_routing_runtime_suspended';
	private static bool $deactivating = false;
	private static ?bool $prepared_ready = null;

	public static function boot(): void {
		add_action( 'init', [ self::class, 'prepare_runtime' ], 90 );
		add_action( 'init', [ self::class, 'register_rewrite_rules' ], 91 );
		add_action( 'init', [ self::class, 'maybe_flush_rewrite_rules' ], 99 );
		add_filter( 'rewrite_rules_array', [ self::class, 'filter_generated_rules' ], 999 );

		add_filter( 'term_link', [ self::class, 'filter_term_link' ], 20, 3 );
		add_filter( 'get_pagenum_link', [ self::class, 'filter_pagenum_link' ], 20, 2 );
		add_filter( 'redirect_canonical', [ self::class, 'filter_redirect_canonical' ], 10, 2 );
		add_action( 'template_redirect', [ self::class, 'maybe_redirect_legacy_route' ], 0 );

		add_action( 'created_category', [ self::class, 'category_changed' ], 10, 0 );
		add_action( 'edited_category', [ self::class, 'category_changed' ], 10, 0 );
		add_action( 'delete_category', [ self::class, 'category_changed' ], 10, 0 );
		add_action( 'created_term', [ self::class, 'public_term_changed' ], 10, 3 );
		add_action( 'edited_term', [ self::class, 'public_term_changed' ], 10, 3 );
		add_action( 'delete_term', [ self::class, 'public_term_changed' ], 10, 3 );

		add_action( 'wp_after_insert_post', [ self::class, 'public_content_route_changed' ], 10, 4 );
		add_action( 'before_delete_post', [ self::class, 'public_content_deleted' ], 10, 2 );
		add_action( 'set_object_terms', [ self::class, 'category_relationship_changed' ], 10, 6 );
		add_action( 'update_option_permalink_structure', [ self::class, 'routing_structure_changed' ], 10, 0 );
		add_action( 'update_option_category_base', [ self::class, 'routing_structure_changed' ], 10, 0 );
		add_action( 'activated_plugin', [ self::class, 'routing_structure_changed' ], 10, 0 );
		add_action( 'deactivated_plugin', [ self::class, 'routing_structure_changed' ], 10, 0 );
		add_action( 'switch_theme', [ self::class, 'routing_structure_changed' ], 10, 0 );
		add_action( 'upgrader_process_complete', [ self::class, 'routing_structure_changed' ], 10, 0 );
	}

	public static function is_active(): bool {
		if (
			! Policy::enabled()
			|| '1' === (string) get_option( self::RUNTIME_SUSPENDED_OPTION, '' )
		) {
			return false;
		}

		return '1' !== (string) get_option( self::REWRITE_DIRTY_OPTION, '' )
			|| true === self::$prepared_ready;
	}

	/**
	 * Re-evaluate derived runtime safety only after a relevant routing mutation.
	 * Normal requests do not pay for a full collision preflight.
	 */
	public static function prepare_runtime(): void {
		if ( ! Policy::enabled() ) {
			self::$prepared_ready = false;
			delete_option( self::RUNTIME_SUSPENDED_OPTION );
			return;
		}

		if ( '1' !== (string) get_option( self::REWRITE_DIRTY_OPTION, '' ) ) {
			self::$prepared_ready = null;
			return;
		}

		self::$prepared_ready = self::apply_preflight_state();
	}

	public static function register_rewrite_rules(): void {
		if ( ! self::is_active() ) {
			return;
		}

		foreach ( self::rewrite_definitions() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Reactivation restores persisted routing policy after deactivation removed
	 * the plugin-owned rewrite rules. Flushing remains deferred until init.
	 */
	public static function reconcile_activation(): void {
		if ( Policy::enabled() ) {
			self::mark_rewrite_dirty();
		}
	}

	/**
	 * Remove only URL Governance rewrite rules during plugin deactivation.
	 * The routing policy remains stored and is reconciled on reactivation.
	 */
	public static function cleanup_deactivation(): void {
		if (
			! Policy::enabled()
			&& '1' !== (string) get_option( self::REWRITE_DIRTY_OPTION, '' )
			&& '1' !== (string) get_option( self::RUNTIME_SUSPENDED_OPTION, '' )
		) {
			return;
		}

		global $wp_rewrite;
		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			foreach ( array_keys( self::rewrite_definitions() ) as $regex ) {
				unset( $wp_rewrite->extra_rules_top[ $regex ], $wp_rewrite->extra_rules[ $regex ] );
			}
		}

		self::$deactivating = true;
		try {
			flush_rewrite_rules( false );
		} finally {
			self::$deactivating = false;
			self::$prepared_ready = null;
			delete_option( self::REWRITE_DIRTY_OPTION );
			delete_option( self::RUNTIME_SUSPENDED_OPTION );
		}
	}

	public static function filter_term_link( string $url, WP_Term $term, string $taxonomy ): string {
		if ( ! self::is_active() || 'category' !== $taxonomy || 'category' !== $term->taxonomy ) {
			return $url;
		}

		return CategoryRoutes::canonical_url( $term );
	}

	public static function filter_pagenum_link( string $url, int $pagenum ): string {
		if ( ! self::is_active() || ! is_category() ) {
			return $url;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term || 'category' !== $term->taxonomy ) {
			return $url;
		}

		$canonical = CategoryRoutes::canonical_url( $term, max( 1, $pagenum ) );
		$query     = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' !== $query ) {
			$args = [];
			parse_str( $query, $args );
			if ( is_array( $args ) && [] !== $args ) {
				$canonical = add_query_arg( $args, $canonical );
			}
		}

		return $canonical;
	}

	/**
	 * Keep WordPress redirect_canonical from translating an already-normalized
	 * compact route back to the default /page/{n}/ shape. Priority-zero routing
	 * normalization runs before redirect_canonical and owns slash/legacy forms.
	 *
	 * @param string|false $redirect_url
	 * @return string|false
	 */
	public static function filter_redirect_canonical( $redirect_url, string $requested_url ) {
		if ( ! Policy::enabled() ) {
			return $redirect_url;
		}

		$path = self::relative_path_from_url( $requested_url );
		if ( '' !== $path && self::is_clean_canonical_path( $path ) ) {
			return false;
		}

		return $redirect_url;
	}

	public static function maybe_redirect_legacy_route(): void {
		if ( ! self::is_active() || is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			return;
		}

		$current = self::current_request_path();
		if ( '' === $current ) {
			return;
		}

		$category_base = trim( CategoryRoutes::category_base_path(), '/' );

		foreach ( CategoryRoutes::all() as $path => $term ) {
			$quoted      = preg_quote( $path, '#' );
			$legacy_base = '' !== $category_base
				? trim( $category_base . '/' . $path, '/' )
				: $path;

			// Normalize the clean root route itself, including trailing slash.
			if ( $current === $path ) {
				self::redirect_if_needed( CategoryRoutes::canonical_url( $term ) );
				return;
			}

			// Legacy WordPress category-base route. When category_base is already
			// removed by another configuration, legacy_base equals the clean path
			// and must not redirect to itself.
			if ( $legacy_base !== $path && $current === $legacy_base ) {
				self::redirect( CategoryRoutes::canonical_url( $term ) );
			}

			if (
				$legacy_base !== $path
				&& preg_match( '#^' . preg_quote( $legacy_base, '#' ) . '/(?:page|p)/?([0-9]+)/?$#', $current, $match )
			) {
				self::redirect( CategoryRoutes::canonical_url( $term, max( 1, (int) $match[1] ) ) );
			}

			// WordPress default pagination remains a supported legacy route.
			if ( preg_match( '#^' . $quoted . '/page/?([0-9]+)/?$#', $current, $match ) ) {
				self::redirect( CategoryRoutes::canonical_url( $term, max( 1, (int) $match[1] ) ) );
			}

			// Compact pagination owns exactly one canonical spelling. p0/p1,
			// leading zeros and missing trailing slashes normalize here.
			if ( preg_match( '#^' . $quoted . '/p([0-9]+)/?$#', $current, $match ) ) {
				$raw_page = (string) $match[1];
				$page     = (int) $raw_page;
				$target   = CategoryRoutes::canonical_url( $term, max( 1, $page ) );

				if ( $page < 2 || (string) $page !== $raw_page ) {
					self::redirect( $target );
				}

				self::redirect_if_needed( $target );
				return;
			}

			// Canonical clean feed shape.
			if ( $current === $path . '/feed' ) {
				self::redirect_if_needed( CategoryRoutes::feed_url( $term ) );
				return;
			}
			if ( preg_match( '#^' . $quoted . '/feed/(feed|rdf|rss|rss2|atom)/?$#', $current, $match ) ) {
				self::redirect_if_needed( CategoryRoutes::feed_url( $term, (string) $match[1] ) );
				return;
			}

			// Alternate WordPress feed suffixes remain readable but redirect to
			// one canonical /feed/{format}/ shape.
			if ( preg_match( '#^' . $quoted . '/(feed|rdf|rss|rss2|atom)/?$#', $current, $match ) ) {
				self::redirect( CategoryRoutes::feed_url( $term, (string) $match[1] ) );
			}

			if ( $legacy_base !== $path && $current === $legacy_base . '/feed' ) {
				self::redirect( CategoryRoutes::feed_url( $term ) );
			}
			if (
				$legacy_base !== $path
				&& preg_match( '#^' . preg_quote( $legacy_base, '#' ) . '/feed/(feed|rdf|rss|rss2|atom)/?$', $current, $match )
			) {
				self::redirect( CategoryRoutes::feed_url( $term, (string) $match[1] ) );
			}
			if (
				$legacy_base !== $path
				&& preg_match( '#^' . preg_quote( $legacy_base, '#' ) . '/(feed|rdf|rss|rss2|atom)/?$', $current, $match )
			) {
				self::redirect( CategoryRoutes::feed_url( $term, (string) $match[1] ) );
			}
		}
	}

	public static function category_changed(): void {
		if ( Policy::enabled() ) {
			self::mark_rewrite_dirty();
		}
	}

	public static function public_term_changed( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		unset( $term_id, $term_taxonomy_id );

		if ( ! Policy::enabled() || 'category' === $taxonomy ) {
			return;
		}

		$object = get_taxonomy( $taxonomy );
		if ( $object instanceof \WP_Taxonomy && $object->public ) {
			self::mark_rewrite_dirty();
		}
	}

	public static function public_content_route_changed(
		int $post_id,
		\WP_Post $post,
		bool $update,
		?\WP_Post $post_before
	): void {
		unset( $post_id );

		if ( ! Policy::enabled() ) {
			return;
		}

		$public_statuses = array_values( get_post_stati( [ 'public' => true ], 'names' ) );
		$current_type    = get_post_type_object( $post->post_type );
		$previous_type   = $post_before instanceof \WP_Post
			? get_post_type_object( $post_before->post_type )
			: null;
		$public_type     = ( $current_type instanceof \WP_Post_Type && $current_type->public )
			|| ( $previous_type instanceof \WP_Post_Type && $previous_type->public );
		$public_status   = in_array( $post->post_status, $public_statuses, true )
			|| ( $post_before instanceof \WP_Post && in_array( $post_before->post_status, $public_statuses, true ) );

		if ( ! $public_type || ! $public_status ) {
			return;
		}

		if ( ! $update || ! $post_before instanceof \WP_Post ) {
			self::mark_rewrite_dirty();
			return;
		}

		foreach ( [ 'post_name', 'post_parent', 'post_type', 'post_status', 'post_date', 'post_author' ] as $field ) {
			if ( $post->{$field} !== $post_before->{$field} ) {
				self::mark_rewrite_dirty();
				return;
			}
		}
	}


	public static function category_relationship_changed(
		int $object_id,
		array $terms,
		array $term_taxonomy_ids,
		string $taxonomy,
		bool $append,
		array $old_term_taxonomy_ids
	): void {
		unset( $terms, $append );

		if ( ! Policy::enabled() || 'category' !== $taxonomy ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$type            = get_post_type_object( $post->post_type );
		$public_statuses = array_values( get_post_stati( [ 'public' => true ], 'names' ) );
		if (
			! $type instanceof \WP_Post_Type
			|| ! $type->public
			|| ! in_array( $post->post_status, $public_statuses, true )
		) {
			return;
		}

		$current = array_map( 'intval', $term_taxonomy_ids );
		$before  = array_map( 'intval', $old_term_taxonomy_ids );
		sort( $current, SORT_NUMERIC );
		sort( $before, SORT_NUMERIC );

		if ( $current !== $before ) {
			self::mark_rewrite_dirty();
		}
	}

	public static function public_content_deleted( int $post_id, \WP_Post $post ): void {
		unset( $post_id );

		if ( ! Policy::enabled() ) {
			return;
		}

		$type            = get_post_type_object( $post->post_type );
		$public_statuses = array_values( get_post_stati( [ 'public' => true ], 'names' ) );
		if (
			$type instanceof \WP_Post_Type
			&& $type->public
			&& in_array( $post->post_status, $public_statuses, true )
		) {
			self::mark_rewrite_dirty();
		}
	}

	public static function routing_structure_changed(): void {
		if ( Policy::enabled() ) {
			self::mark_rewrite_dirty();
		}
	}

	public static function mark_rewrite_dirty(): void {
		self::$prepared_ready = null;
		update_option( self::REWRITE_DIRTY_OPTION, '1', false );
	}

	public static function maybe_flush_rewrite_rules(): void {
		if ( '1' !== (string) get_option( self::REWRITE_DIRTY_OPTION, '' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		delete_option( self::REWRITE_DIRTY_OPTION );
	}

	/** @param array<string,string> $rules @return array<string,string> */
	public static function filter_generated_rules( array $rules ): array {
		$definitions = self::rewrite_definitions();

		if ( self::$deactivating || ! Policy::enabled() ) {
			foreach ( array_keys( $definitions ) as $regex ) {
				unset( $rules[ $regex ] );
			}
			return $rules;
		}

		$ready = self::apply_preflight_state();
		self::$prepared_ready = $ready;
		if ( ! $ready ) {
			foreach ( array_keys( $definitions ) as $regex ) {
				unset( $rules[ $regex ] );
			}
			return $rules;
		}

		return $definitions + $rules;
	}

	private static function apply_preflight_state(): bool {
		$result = Preflight::run();
		$ready  = ! empty( $result['ready'] );

		if ( $ready ) {
			delete_option( self::RUNTIME_SUSPENDED_OPTION );
		} else {
			update_option( self::RUNTIME_SUSPENDED_OPTION, '1', false );
		}

		return $ready;
	}

	/** @return array<string,string> regex => WordPress rewrite query. */
	private static function rewrite_definitions(): array {
		$paths = array_keys( CategoryRoutes::all() );
		if ( [] === $paths ) {
			return [];
		}

		usort(
			$paths,
			static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a )
		);

		$alternation = implode(
			'|',
			array_map(
				static fn( string $path ): string => preg_quote( $path, '#' ),
				$paths
			)
		);

		return [
			'^(' . $alternation . ')/p([0-9]+)/?$' =>
				'index.php?category_name=$matches[1]&paged=$matches[2]',
			'^(' . $alternation . ')/feed/(feed|rdf|rss|rss2|atom)/?$' =>
				'index.php?category_name=$matches[1]&feed=$matches[2]',
			'^(' . $alternation . ')/(feed|rdf|rss|rss2|atom)/?$' =>
				'index.php?category_name=$matches[1]&feed=$matches[2]',
			'^(' . $alternation . ')/?$' =>
				'index.php?category_name=$matches[1]',
		];
	}

	private static function is_clean_canonical_path( string $current ): bool {
		foreach ( CategoryRoutes::all() as $path => $_term ) {
			$quoted = preg_quote( $path, '#' );

			if ( $current === $path ) {
				return true;
			}
			if ( preg_match( '#^' . $quoted . '/p([2-9][0-9]*|1[0-9]+)/?$#', $current ) ) {
				return true;
			}
			if ( preg_match( '#^' . $quoted . '/feed(?:/(feed|rdf|rss|rss2|atom))?/?$#', $current ) ) {
				return true;
			}
		}

		return false;
	}

	private static function current_request_path(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';

		return self::relative_path_from_url( $request_uri );
	}

	private static function relative_path_from_url( string $url ): string {
		$request_path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$home_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		$request_path = '/' . ltrim( rawurldecode( $request_path ), '/' );
		$home_path    = '/' . trim( rawurldecode( $home_path ), '/' );

		if ( '/' !== $home_path && str_starts_with( $request_path, $home_path . '/' ) ) {
			$request_path = substr( $request_path, strlen( $home_path ) );
		} elseif ( $request_path === $home_path ) {
			$request_path = '/';
		}

		return trim( $request_path, '/' );
	}

	private static function redirect_if_needed( string $target ): void {
		$request_uri  = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$request_path = rawurldecode( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) );
		$target_path  = rawurldecode( (string) wp_parse_url( $target, PHP_URL_PATH ) );

		if ( $request_path !== $target_path ) {
			self::redirect( $target );
		}
	}

	private static function redirect( string $target ): never {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( '' !== $query ) {
			$args = [];
			parse_str( $query, $args );
			if ( is_array( $args ) && [] !== $args ) {
				$target = add_query_arg( $args, $target );
			}
		}

		wp_safe_redirect( $target, 301, 'Core Blueprint URL Governance' );
		exit;
	}

	private function __construct() {}
}
