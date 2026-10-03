<?php
declare(strict_types=1);
/**
 * Field-group location matching.
 *
 * Post types and Option Pages are independent location namespaces. A field
 * group may target either or both without changing its field definitions.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels;

defined( 'ABSPATH' ) || exit;

final class LocationMatcher {
	public static function matches_post_type( array $group, string $post_type ): bool {
		return in_array( $post_type, (array) ( $group['post_types'] ?? [] ), true );
	}

	public static function matches_option_page( array $group, string $page_slug ): bool {
		return in_array( $page_slug, (array) ( $group['option_pages'] ?? [] ), true );
	}

	public static function matches_taxonomy( array $group, string $taxonomy ): bool {
		return in_array( $taxonomy, (array) ( $group['term_taxonomies'] ?? [] ), true );
	}

	public static function matches_user( array $group, \WP_User $user ): bool {
		if ( empty( $group['user_enabled'] ) ) {
			return false;
		}
		$roles = array_values( array_filter( array_map( 'sanitize_key', (array) ( $group['user_roles'] ?? [] ) ) ) );
		return empty( $roles ) || (bool) array_intersect( $roles, (array) $user->roles );
	}
}
