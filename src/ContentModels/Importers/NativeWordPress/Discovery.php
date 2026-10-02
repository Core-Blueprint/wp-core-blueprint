<?php
declare(strict_types=1);
/**
 * Discover WordPress-native runtime schema that can be considered for
 * Content Models adoption without understanding the code/vendor that registered it.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels\Importers\NativeWordPress;

use CB\Core\ContentModels\Repository;
use CB\Core\UI\Icon;

defined( 'ABSPATH' ) || exit;

final class Discovery {
	public const READY            = 'ready';
	public const MAPPING_REQUIRED = 'mapping_required';
	public const EXISTING         = 'existing';
	public const UNSUPPORTED      = 'unsupported';

	private const POST_TYPE_SUPPORTS = [
		'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions',
		'page-attributes', 'custom-fields', 'comments',
	];

	private const STRING_FIELD_TYPES = [
		'text', 'textarea', 'wysiwyg', 'email', 'url', 'date', 'time', 'datetime', 'color',
	];

	/** @return array{created_at:int,post_types:array<string,array<string,mixed>>,taxonomies:array<string,array<string,mixed>>,meta:array<string,array<string,mixed>>} */
	public static function snapshot(): array {
		return [
			'created_at'  => time(),
			'post_types'  => self::discover_post_types(),
			'taxonomies'  => self::discover_taxonomies(),
			'meta'        => self::discover_meta(),
		];
	}

	/** @return array<string,array<string,mixed>> */
	private static function discover_post_types(): array {
		$objects = get_post_types( [], 'objects' );
		$objects = is_array( $objects ) ? $objects : [];
		$result = [];
		foreach ( $objects as $key => $object ) {
			if ( ! is_object( $object ) || ! empty( $object->_builtin ) ) {
				continue;
			}
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$result[ 'post-type:' . $key ] = self::analyze_post_type( $key, $object );
		}
		ksort( $result, SORT_NATURAL | SORT_FLAG_CASE );
		return $result;
	}

	/** @return array<string,mixed> */
	private static function analyze_post_type( string $key, object $object ): array {
		$labels = is_object( $object->labels ?? null ) ? $object->labels : (object) [];
		$singular = trim( (string) ( $labels->singular_name ?? $object->label ?? $key ) );
		$plural   = trim( (string) ( $labels->name ?? $object->label ?? $singular ) );
		$base = [
			'id'    => 'post-type:' . $key,
			'token' => self::token( 'p', 'post-type:' . $key ),
			'kind'  => 'post_type',
			'key'   => $key,
			'label' => $plural ?: $key,
		];
		if ( null !== Repository::post_type( $key ) ) {
			return self::finish( $base + [ 'status' => self::EXISTING, 'reasons' => [ __( 'This post type already has a Content Models definition.', 'core-blueprint' ) ] ] );
		}

		$reasons = [];
		$public = ! empty( $object->public );
		if ( (bool) ( $object->publicly_queryable ?? false ) !== $public ) {
			$reasons[] = __( 'Public query behavior differs from the Content Models runtime contract.', 'core-blueprint' );
		}
		if ( true !== ( $object->show_ui ?? null ) || true !== ( $object->show_in_menu ?? null ) ) {
			$reasons[] = __( 'Admin visibility cannot be represented exactly.', 'core-blueprint' );
		}
		if ( (bool) ( $object->exclude_from_search ?? ! $public ) !== ! $public ) {
			$reasons[] = __( 'Search visibility differs from the Content Models runtime contract.', 'core-blueprint' );
		}
		if ( (bool) ( $object->show_in_nav_menus ?? $public ) !== $public ) {
			$reasons[] = __( 'Navigation-menu visibility cannot be represented exactly.', 'core-blueprint' );
		}
		if ( true !== ( $object->show_in_admin_bar ?? true ) ) {
			$reasons[] = __( 'Admin-bar visibility cannot be represented exactly.', 'core-blueprint' );
		}
		if ( null !== ( $object->menu_position ?? null ) ) {
			$reasons[] = __( 'A custom admin menu position is not part of the Content Models schema.', 'core-blueprint' );
		}
		if ( false === ( $object->can_export ?? true ) ) {
			$reasons[] = __( 'The source disables WordPress export for this post type.', 'core-blueprint' );
		}
		if ( true === ( $object->delete_with_user ?? false ) ) {
			$reasons[] = __( 'Delete-with-user behavior is not part of the Content Models schema.', 'core-blueprint' );
		}
		if ( true !== ( $object->map_meta_cap ?? true ) ) {
			$reasons[] = __( 'Custom capability mapping cannot be adopted safely.', 'core-blueprint' );
		}
		$capability_type = $object->capability_type ?? 'post';
		if ( 'post' !== $capability_type && [ 'post', 'posts' ] !== $capability_type ) {
			$reasons[] = __( 'Custom capability types cannot be adopted safely.', 'core-blueprint' );
		}
		if ( null !== ( $object->register_meta_box_cb ?? null ) ) {
			$reasons[] = __( 'A custom meta-box callback is attached to this post type.', 'core-blueprint' );
		}
		if ( ! empty( $object->template ?? [] ) || ! empty( $object->template_lock ?? false ) ) {
			$reasons[] = __( 'Editor template behavior is not represented by Content Models.', 'core-blueprint' );
		}
		if ( ! self::default_post_rest_contract( $key, $object ) ) {
			$reasons[] = __( 'Custom REST routing/controller behavior cannot be adopted safely.', 'core-blueprint' );
		}
		if ( ! self::simple_post_rewrite( $key, $object, $public ) ) {
			$reasons[] = __( 'Rewrite behavior is more complex than Content Models can represent.', 'core-blueprint' );
		}
		$taxonomies = function_exists( 'get_object_taxonomies' ) ? get_object_taxonomies( $key ) : [];
		if ( ! empty( $taxonomies ) ) {
			$reasons[] = __( 'Post-type taxonomy assignments must be reviewed separately and cannot be inferred safely.', 'core-blueprint' );
		}
		$supports = function_exists( 'get_all_post_type_supports' ) ? get_all_post_type_supports( $key ) : [];
		$supports = is_array( $supports ) ? $supports : [];
		foreach ( $supports as $feature => $args ) {
			if ( ! in_array( (string) $feature, self::POST_TYPE_SUPPORTS, true ) || ( true !== $args && [ true ] !== $args ) ) {
				/* translators: %s: WordPress post-type support feature name. */
				$reasons[] = sprintf( __( 'Post-type support “%s” cannot be represented exactly.', 'core-blueprint' ), (string) $feature );
			}
		}
		if ( ! self::post_labels_match( $labels, $singular, $plural ) ) {
			$reasons[] = __( 'One or more custom post-type labels would be lost by adoption.', 'core-blueprint' );
		}

		$definition = null;
		if ( [] === $reasons ) {
			try {
				$menu_icon = trim( (string) ( $object->menu_icon ?? '' ) );
				$menu_icon = '' !== $menu_icon ? $menu_icon : 'dashicons-admin-post';
				$normalized_icon = Icon::normalize_menu_icon( $menu_icon, 'dashicons-admin-post' );
				if ( $normalized_icon !== $menu_icon ) {
					$reasons[] = __( 'The post-type menu icon cannot be represented exactly.', 'core-blueprint' );
				} else {
					$rewrite = is_array( $object->rewrite ?? null ) ? $object->rewrite : [];
					$definition = Repository::normalize_post_type( [
						'key'            => $key,
						'singular_label' => $singular,
						'plural_label'   => $plural,
						'description'    => (string) ( $object->description ?? '' ),
						'public'         => $public,
						'show_in_rest'   => ! empty( $object->show_in_rest ),
						'has_archive'    => true === ( $object->has_archive ?? false ),
						'hierarchical'   => ! empty( $object->hierarchical ),
						'rewrite_slug'   => $public ? (string) ( $rewrite['slug'] ?? $key ) : $key,
						'icon'           => $menu_icon,
						'supports'       => array_keys( $supports ),
					] );
				}
			} catch ( \InvalidArgumentException $e ) {
				$reasons[] = $e->getMessage();
			}
		}

		$status = [] === $reasons && is_array( $definition ) ? self::READY : self::UNSUPPORTED;
		return self::finish( $base + [ 'status' => $status, 'reasons' => $reasons, 'definition' => $definition ] );
	}

	/** @return array<string,array<string,mixed>> */
	private static function discover_taxonomies(): array {
		$objects = get_taxonomies( [], 'objects' );
		$objects = is_array( $objects ) ? $objects : [];
		$result = [];
		foreach ( $objects as $key => $object ) {
			if ( ! is_object( $object ) || ! empty( $object->_builtin ) ) {
				continue;
			}
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$result[ 'taxonomy:' . $key ] = self::analyze_taxonomy( $key, $object );
		}
		ksort( $result, SORT_NATURAL | SORT_FLAG_CASE );
		return $result;
	}

	/** @return array<string,mixed> */
	private static function analyze_taxonomy( string $key, object $object ): array {
		$labels = is_object( $object->labels ?? null ) ? $object->labels : (object) [];
		$singular = trim( (string) ( $labels->singular_name ?? $object->label ?? $key ) );
		$plural   = trim( (string) ( $labels->name ?? $object->label ?? $singular ) );
		$base = [
			'id'    => 'taxonomy:' . $key,
			'token' => self::token( 't', 'taxonomy:' . $key ),
			'kind'  => 'taxonomy',
			'key'   => $key,
			'label' => $plural ?: $key,
		];
		if ( null !== Repository::taxonomy( $key ) ) {
			return self::finish( $base + [ 'status' => self::EXISTING, 'reasons' => [ __( 'This taxonomy already has a Content Models definition.', 'core-blueprint' ) ] ] );
		}

		$reasons = [];
		$public = ! empty( $object->public );
		if ( (bool) ( $object->publicly_queryable ?? false ) !== $public ) {
			$reasons[] = __( 'Public query behavior differs from the Content Models runtime contract.', 'core-blueprint' );
		}
		if ( true !== ( $object->show_ui ?? null ) ) {
			$reasons[] = __( 'Admin visibility cannot be represented exactly.', 'core-blueprint' );
		}
		if ( (bool) ( $object->show_in_nav_menus ?? $public ) !== $public ) {
			$reasons[] = __( 'Navigation-menu visibility cannot be represented exactly.', 'core-blueprint' );
		}
		if ( ! self::default_taxonomy_rest_contract( $key, $object ) ) {
			$reasons[] = __( 'Custom REST routing/controller behavior cannot be adopted safely.', 'core-blueprint' );
		}
		if ( ! self::simple_taxonomy_rewrite( $key, $object, $public ) ) {
			$reasons[] = __( 'Rewrite behavior is more complex than Content Models can represent.', 'core-blueprint' );
		}
		if ( ! empty( $object->sort ?? false ) || ! empty( $object->default_term ?? null ) ) {
			$reasons[] = __( 'Custom sorting/default-term behavior is not represented by Content Models.', 'core-blueprint' );
		}
		$meta_box_cb = $object->meta_box_cb ?? null;
		$default_meta_boxes = [ null, false, 'post_categories_meta_box', 'post_tags_meta_box' ];
		if ( ! in_array( $meta_box_cb, $default_meta_boxes, true ) ) {
			$reasons[] = __( 'A custom taxonomy meta-box callback is attached.', 'core-blueprint' );
		}
		$update_count = $object->update_count_callback ?? null;
		if ( ! in_array( $update_count, [ null, '_update_post_term_count' ], true ) ) {
			$reasons[] = __( 'A custom taxonomy count callback is attached.', 'core-blueprint' );
		}
		if ( ! self::default_taxonomy_capabilities( $object ) ) {
			$reasons[] = __( 'Custom taxonomy capabilities cannot be adopted safely.', 'core-blueprint' );
		}
		if ( ! self::taxonomy_labels_match( $labels, $singular, $plural ) ) {
			$reasons[] = __( 'One or more custom taxonomy labels would be lost by adoption.', 'core-blueprint' );
		}
		$object_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $object->object_type ?? [] ) ) ) ) );
		if ( [] === $object_types ) {
			$reasons[] = __( 'The taxonomy has no registered post-type targets.', 'core-blueprint' );
		}

		$definition = null;
		if ( [] === $reasons ) {
			try {
				$rewrite = is_array( $object->rewrite ?? null ) ? $object->rewrite : [];
				$definition = Repository::normalize_taxonomy( [
					'key'               => $key,
					'singular_label'    => $singular,
					'plural_label'      => $plural,
					'description'       => (string) ( $object->description ?? '' ),
					'object_types'      => $object_types,
					'public'            => $public,
					'show_in_rest'      => ! empty( $object->show_in_rest ),
					'hierarchical'      => ! empty( $object->hierarchical ),
					'show_admin_column' => ! empty( $object->show_admin_column ),
					'rewrite_slug'      => $public ? (string) ( $rewrite['slug'] ?? $key ) : $key,
				] );
			} catch ( \InvalidArgumentException $e ) {
				$reasons[] = $e->getMessage();
			}
		}
		$status = [] === $reasons && is_array( $definition ) ? self::READY : self::UNSUPPORTED;
		return self::finish( $base + [ 'status' => $status, 'reasons' => $reasons, 'definition' => $definition ] );
	}

	/** @return array<string,array<string,mixed>> */
	private static function discover_meta(): array {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return [];
		}
		$result = [];
		$post_types = get_post_types( [], 'objects' );
		foreach ( is_array( $post_types ) ? $post_types : [] as $key => $object ) {
			if ( ! is_object( $object ) || empty( $object->show_ui ) ) {
				continue;
			}
			$key = sanitize_key( (string) $key );
			if ( '' !== $key ) {
				self::append_meta_context( $result, 'post', $key, (string) ( $object->label ?? $key ) );
			}
		}
		$taxonomies = get_taxonomies( [], 'objects' );
		foreach ( is_array( $taxonomies ) ? $taxonomies : [] as $key => $object ) {
			if ( ! is_object( $object ) || empty( $object->show_ui ) ) {
				continue;
			}
			$key = sanitize_key( (string) $key );
			if ( '' !== $key ) {
				self::append_meta_context( $result, 'term', $key, (string) ( $object->label ?? $key ) );
			}
		}
		self::append_meta_context( $result, 'user', '', __( 'Users', 'core-blueprint' ) );
		ksort( $result, SORT_NATURAL | SORT_FLAG_CASE );
		return $result;
	}

	/** @param array<string,array<string,mixed>> $result */
	private static function append_meta_context( array &$result, string $object_type, string $subtype, string $context_label ): void {
		$registered = get_registered_meta_keys( $object_type, $subtype );
		if ( ! is_array( $registered ) ) {
			return;
		}
		foreach ( $registered as $key => $args ) {
			if ( ! is_array( $args ) ) {
				continue;
			}
			$key = (string) $key;
			$id = 'meta:' . $object_type . ':' . ( '' !== $subtype ? $subtype : '*' ) . ':' . $key;
			$result[ $id ] = self::analyze_meta( $id, $object_type, $subtype, $context_label, $key, $args );
		}
	}

	/** @return array<string,mixed> */
	private static function analyze_meta( string $id, string $object_type, string $subtype, string $context_label, string $key, array $args ): array {
		$context_id = $object_type . ':' . ( '' !== $subtype ? $subtype : '*' );
		$base = [
			'id'              => $id,
			'token'           => self::token( 'm', $id ),
			'context_token'   => self::token( 'c', $context_id ),
			'kind'            => 'meta',
			'key'             => $key,
			'label'           => $key,
			'object_type'     => $object_type,
			'object_subtype'  => $subtype,
			'context_id'      => $context_id,
			'context_label'   => $context_label,
			'registered_type' => sanitize_key( (string) ( $args['type'] ?? 'string' ) ),
			'description'     => sanitize_text_field( (string) ( $args['description'] ?? '' ) ),
		];
		if ( self::content_models_has_meta( $object_type, $subtype, $key ) ) {
			return self::finish( $base + [ 'status' => self::EXISTING, 'reasons' => [ __( 'This registered meta key is already represented by a Content Models field.', 'core-blueprint' ) ] ] );
		}
		$reasons = [];
		if ( '' === sanitize_key( $key ) || sanitize_key( $key ) !== $key || str_starts_with( $key, '_' ) || strlen( $key ) > 191 ) {
			$reasons[] = __( 'The metadata key is not a valid adoptable Content Models field name.', 'core-blueprint' );
		}
		if ( true !== ( $args['single'] ?? false ) ) {
			$reasons[] = __( 'Multi-row registered metadata cannot be represented by one Content Models field.', 'core-blueprint' );
		}
		if ( ! empty( $args['sanitize_callback'] ) || ! empty( $args['auth_callback'] ) ) {
			$reasons[] = __( 'Custom metadata callbacks would be lost during adoption.', 'core-blueprint' );
		}
		$show_in_rest = $args['show_in_rest'] ?? false;
		if ( ! is_bool( $show_in_rest ) ) {
			$reasons[] = __( 'Custom REST metadata schemas cannot be represented exactly.', 'core-blueprint' );
		}
		$type = sanitize_key( (string) ( $args['type'] ?? 'string' ) );
		$allowed = match ( $type ) {
			'boolean' => [ 'true_false' ],
			'number'  => [ 'number' ],
			'string'  => self::STRING_FIELD_TYPES,
			default   => [],
		};
		if ( [] === $allowed ) {
			/* translators: %s: registered WordPress metadata type. */
			$reasons[] = sprintf( __( 'Registered metadata type “%s” has no exact scalar Content Models storage contract.', 'core-blueprint' ), $type ?: __( 'unknown', 'core-blueprint' ) );
		}
		if ( ! in_array( $object_type, [ 'post', 'term', 'user' ], true ) || ( in_array( $object_type, [ 'post', 'term' ], true ) && '' === $subtype ) ) {
			$reasons[] = __( 'Global post/term metadata registrations cannot be mapped to a precise Content Models location.', 'core-blueprint' );
		}
		$status = [] === $reasons ? self::MAPPING_REQUIRED : self::UNSUPPORTED;
		$suggested = 'boolean' === $type ? 'true_false' : ( 'number' === $type ? 'number' : '' );
		$default = $args['default'] ?? ( 'boolean' === $type ? false : '' );
		if ( ! is_scalar( $default ) && null !== $default ) {
			$reasons[] = __( 'The registered metadata default is not scalar and cannot be adopted safely.', 'core-blueprint' );
			$status = self::UNSUPPORTED;
		}
		return self::finish( $base + [
			'status'              => $status,
			'reasons'             => $reasons,
			'allowed_field_types' => $allowed,
			'suggested_field_type'=> $suggested,
			'show_in_rest'        => is_bool( $show_in_rest ) ? $show_in_rest : false,
			'default'             => $default,
		] );
	}

	private static function content_models_has_meta( string $object_type, string $subtype, string $key ): bool {
		foreach ( Repository::field_groups() as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$matches = match ( $object_type ) {
				'post' => in_array( $subtype, (array) ( $group['post_types'] ?? [] ), true ),
				'term' => in_array( $subtype, (array) ( $group['term_taxonomies'] ?? [] ), true ),
				'user' => ! empty( $group['user_enabled'] ),
				default => false,
			};
			if ( ! $matches ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && $key === (string) ( $field['name'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function simple_post_rewrite( string $key, object $object, bool $public ): bool {
		$has_archive = $object->has_archive ?? false;
		if ( ! is_bool( $has_archive ) ) {
			return false;
		}
		if ( ! $public ) {
			return false === ( $object->rewrite ?? false ) && false === $has_archive;
		}
		$rewrite = $object->rewrite ?? null;
		if ( ! is_array( $rewrite ) || '' === (string) ( $rewrite['slug'] ?? '' ) ) {
			return false;
		}
		if ( isset( $rewrite['with_front'] ) && true !== $rewrite['with_front'] ) {
			return false;
		}
		if ( isset( $rewrite['pages'] ) && true !== $rewrite['pages'] ) {
			return false;
		}
		if ( isset( $rewrite['feeds'] ) && (bool) $rewrite['feeds'] !== $has_archive ) {
			return false;
		}
		$query_var = $object->query_var ?? $key;
		return $key === $query_var;
	}

	private static function simple_taxonomy_rewrite( string $key, object $object, bool $public ): bool {
		if ( ! $public ) {
			return false === ( $object->rewrite ?? false );
		}
		$rewrite = $object->rewrite ?? null;
		if ( ! is_array( $rewrite ) || '' === (string) ( $rewrite['slug'] ?? '' ) ) {
			return false;
		}
		if ( isset( $rewrite['with_front'] ) && true !== $rewrite['with_front'] ) {
			return false;
		}
		if ( isset( $rewrite['hierarchical'] ) && false !== $rewrite['hierarchical'] ) {
			return false;
		}
		$query_var = $object->query_var ?? $key;
		return $key === $query_var;
	}

	private static function default_post_rest_contract( string $key, object $object ): bool {
		if ( empty( $object->show_in_rest ) ) {
			return true;
		}
		$base = $object->rest_base ?? null;
		$namespace = $object->rest_namespace ?? null;
		$controller = $object->rest_controller_class ?? null;
		return ( null === $base || $key === $base )
			&& ( null === $namespace || 'wp/v2' === $namespace )
			&& ( null === $controller || 'WP_REST_Posts_Controller' === $controller );
	}

	private static function default_taxonomy_rest_contract( string $key, object $object ): bool {
		if ( empty( $object->show_in_rest ) ) {
			return true;
		}
		$base = $object->rest_base ?? null;
		$namespace = $object->rest_namespace ?? null;
		$controller = $object->rest_controller_class ?? null;
		return ( null === $base || $key === $base )
			&& ( null === $namespace || 'wp/v2' === $namespace )
			&& ( null === $controller || 'WP_REST_Terms_Controller' === $controller );
	}

	private static function default_taxonomy_capabilities( object $object ): bool {
		$cap = $object->cap ?? null;
		if ( ! is_object( $cap ) ) {
			return true;
		}
		$expected = [
			'manage_terms' => 'manage_categories',
			'edit_terms'   => 'manage_categories',
			'delete_terms' => 'manage_categories',
			'assign_terms' => 'edit_posts',
		];
		foreach ( $expected as $name => $value ) {
			if ( isset( $cap->{$name} ) && $value !== (string) $cap->{$name} ) {
				return false;
			}
		}
		return true;
	}

	private static function post_labels_match( object $labels, string $singular, string $plural ): bool {
		$expected = [
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'name_admin_bar'         => $singular,
			'add_new'               => __( 'Add New', 'core-blueprint' ),
			/* translators: %s: singular post-type label. */
			'add_new_item'          => sprintf( __( 'Add New %s', 'core-blueprint' ), $singular ),
			/* translators: %s: singular post-type label. */
			'edit_item'             => sprintf( __( 'Edit %s', 'core-blueprint' ), $singular ),
			/* translators: %s: singular post-type label. */
			'new_item'              => sprintf( __( 'New %s', 'core-blueprint' ), $singular ),
			/* translators: %s: singular post-type label. */
			'view_item'             => sprintf( __( 'View %s', 'core-blueprint' ), $singular ),
			/* translators: %s: plural post-type label. */
			'view_items'            => sprintf( __( 'View %s', 'core-blueprint' ), $plural ),
			/* translators: %s: plural post-type label. */
			'search_items'          => sprintf( __( 'Search %s', 'core-blueprint' ), $plural ),
			/* translators: %s: lowercase plural post-type label. */
			'not_found'             => sprintf( __( 'No %s found.', 'core-blueprint' ), strtolower( $plural ) ),
			/* translators: %s: lowercase plural post-type label. */
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'core-blueprint' ), strtolower( $plural ) ),
			/* translators: %s: plural post-type label. */
			'all_items'             => sprintf( __( 'All %s', 'core-blueprint' ), $plural ),
			/* translators: %s: singular post-type label. */
			'archives'              => sprintf( __( '%s Archives', 'core-blueprint' ), $singular ),
			/* translators: %s: singular post-type label. */
			'attributes'            => sprintf( __( '%s Attributes', 'core-blueprint' ), $singular ),
			'featured_image'        => __( 'Featured image', 'core-blueprint' ),
			'set_featured_image'    => __( 'Set featured image', 'core-blueprint' ),
			'remove_featured_image' => __( 'Remove featured image', 'core-blueprint' ),
			'use_featured_image'    => __( 'Use as featured image', 'core-blueprint' ),
		];
		return self::labels_match( $labels, $expected );
	}

	private static function taxonomy_labels_match( object $labels, string $singular, string $plural ): bool {
		$expected = [
			'name'          => $plural,
			'singular_name' => $singular,
			/* translators: %s: plural taxonomy label. */
			'search_items'  => sprintf( __( 'Search %s', 'core-blueprint' ), $plural ),
			/* translators: %s: plural taxonomy label. */
			'all_items'     => sprintf( __( 'All %s', 'core-blueprint' ), $plural ),
			/* translators: %s: singular taxonomy label. */
			'edit_item'     => sprintf( __( 'Edit %s', 'core-blueprint' ), $singular ),
			/* translators: %s: singular taxonomy label. */
			'update_item'   => sprintf( __( 'Update %s', 'core-blueprint' ), $singular ),
			/* translators: %s: singular taxonomy label. */
			'add_new_item'  => sprintf( __( 'Add New %s', 'core-blueprint' ), $singular ),
			/* translators: %s: singular taxonomy label. */
			'new_item_name' => sprintf( __( 'New %s Name', 'core-blueprint' ), $singular ),
			'menu_name'     => $plural,
		];
		return self::labels_match( $labels, $expected );
	}

	/** @param array<string,string> $expected */
	private static function labels_match( object $labels, array $expected ): bool {
		foreach ( $expected as $property => $value ) {
			if ( isset( $labels->{$property} ) && '' !== (string) $labels->{$property} && $value !== (string) $labels->{$property} ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	private static function finish( array $entry ): array {
		$copy = $entry;
		unset( $copy['fingerprint'] );
		$entry['fingerprint'] = self::digest( $copy );
		return $entry;
	}

	private static function token( string $prefix, string $identity ): string {
		return $prefix . substr( hash( 'sha256', $identity ), 0, 20 );
	}

	/** @param mixed $value */
	private static function digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** @param mixed $value @return mixed */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ __CLASS__, 'canonicalize' ], $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
