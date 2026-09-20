<?php
declare(strict_types=1);
/**
 * Versioned storage for user-defined content models.
 *
 * Definitions live in one normal WordPress option. Runtime content remains in
 * native WordPress posts/terms/meta; deleting a definition never deletes data.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels;

use CB\Core\UI\Icon;

defined( 'ABSPATH' ) || exit;

final class Repository {
	private const OPTION = 'cb_core_content_models_schema';
	public const SCHEMA_VERSION = 5;

	private const POST_TYPE_SUPPORTS = [
		'title',
		'editor',
		'thumbnail',
		'excerpt',
		'author',
		'revisions',
		'page-attributes',
		'custom-fields',
		'comments',
	];

	/** @return array{schema_version:int,post_types:array<string,array<string,mixed>>,taxonomies:array<string,array<string,mixed>>,option_pages:array<string,array<string,mixed>>,field_groups:array<string,array<string,mixed>>} */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'post_types'     => is_array( $stored['post_types'] ?? null ) ? $stored['post_types'] : [],
			'taxonomies'     => is_array( $stored['taxonomies'] ?? null ) ? $stored['taxonomies'] : [],
			'option_pages'    => is_array( $stored['option_pages'] ?? null ) ? $stored['option_pages'] : [],
			'field_groups'   => is_array( $stored['field_groups'] ?? null ) ? $stored['field_groups'] : [],
		];
	}

	/** @return array<string,array<string,mixed>> */
	public static function post_types(): array {
		$models = apply_filters( 'cb_core_content_models_post_types', self::all()['post_types'] );
		$models = is_array( $models ) ? $models : [];
		ksort( $models, SORT_NATURAL | SORT_FLAG_CASE );
		return $models;
	}

	/** @return array<string,array<string,mixed>> */
	public static function taxonomies(): array {
		$models = apply_filters( 'cb_core_content_models_taxonomies', self::all()['taxonomies'] );
		$models = is_array( $models ) ? $models : [];
		ksort( $models, SORT_NATURAL | SORT_FLAG_CASE );
		return $models;
	}

	/** @return array<string,array<string,mixed>> */
	public static function option_pages(): array {
		$pages = apply_filters( 'cb_core_content_models_option_pages', self::all()['option_pages'] );
		$pages = is_array( $pages ) ? $pages : [];
		uasort( $pages, static fn( array $a, array $b ): int => strnatcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) ) );
		return $pages;
	}

	/** @return array<string,array<string,mixed>> */
	public static function field_groups(): array {
		$groups = apply_filters( 'cb_core_content_models_field_groups', self::all()['field_groups'] );
		$groups = is_array( $groups ) ? $groups : [];
		uasort( $groups, static fn( array $a, array $b ): int => strnatcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) ) );
		return $groups;
	}

	public static function post_type( string $key ): ?array {
		$models = self::post_types();
		return isset( $models[ $key ] ) && is_array( $models[ $key ] ) ? $models[ $key ] : null;
	}

	public static function taxonomy( string $key ): ?array {
		$models = self::taxonomies();
		return isset( $models[ $key ] ) && is_array( $models[ $key ] ) ? $models[ $key ] : null;
	}

	public static function option_page( string $slug ): ?array {
		$pages = self::option_pages();
		return isset( $pages[ $slug ] ) && is_array( $pages[ $slug ] ) ? $pages[ $slug ] : null;
	}

	public static function field_group( string $id ): ?array {
		$groups = self::field_groups();
		return isset( $groups[ $id ] ) && is_array( $groups[ $id ] ) ? $groups[ $id ] : null;
	}

	public static function field( string $group_id, string $field_id ): ?array {
		$group = self::field_group( $group_id );
		$fields = is_array( $group['fields'] ?? null ) ? $group['fields'] : [];
		return isset( $fields[ $field_id ] ) && is_array( $fields[ $field_id ] ) ? $fields[ $field_id ] : null;
	}

	/** @return array<string,mixed> */
	public static function normalize_post_type( array $input ): array {
		$key = sanitize_key( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 20 ) {
			throw new \InvalidArgumentException( __( 'Post type keys must be 1–20 lowercase characters, numbers, dashes or underscores.', 'core-blueprint' ) );
		}

		$singular = sanitize_text_field( (string) ( $input['singular_label'] ?? '' ) );
		$plural   = sanitize_text_field( (string) ( $input['plural_label'] ?? '' ) );
		if ( '' === $singular || '' === $plural ) {
			throw new \InvalidArgumentException( __( 'Singular and plural labels are required.', 'core-blueprint' ) );
		}

		$supports = array_values( array_intersect(
			self::POST_TYPE_SUPPORTS,
			array_map( 'sanitize_key', is_array( $input['supports'] ?? null ) ? $input['supports'] : [] )
		) );

		$rewrite = sanitize_title( (string) ( $input['rewrite_slug'] ?? '' ) );
		if ( '' === $rewrite ) {
			$rewrite = $key;
		}

		$public = ! empty( $input['public'] );

		return [
			'key'            => $key,
			'singular_label' => $singular,
			'plural_label'   => $plural,
			'description'    => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'public'         => $public,
			'show_in_rest'   => ! empty( $input['show_in_rest'] ),
			'has_archive'    => $public && ! empty( $input['has_archive'] ),
			'hierarchical'   => ! empty( $input['hierarchical'] ),
			'rewrite_slug'   => $rewrite,
			'icon'           => Icon::normalize_menu_icon( (string) ( $input['icon'] ?? 'dashicons-admin-post' ), 'dashicons-admin-post' ),
			'supports'       => $supports,
		];
	}

	/** @return array<string,mixed> */
	public static function normalize_taxonomy( array $input ): array {
		$key = sanitize_key( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 32 ) {
			throw new \InvalidArgumentException( __( 'Taxonomy keys must be 1–32 lowercase characters, numbers, dashes or underscores.', 'core-blueprint' ) );
		}

		$singular = sanitize_text_field( (string) ( $input['singular_label'] ?? '' ) );
		$plural   = sanitize_text_field( (string) ( $input['plural_label'] ?? '' ) );
		if ( '' === $singular || '' === $plural ) {
			throw new \InvalidArgumentException( __( 'Singular and plural labels are required.', 'core-blueprint' ) );
		}

		$objects = array_values( array_unique( array_filter( array_map(
			'sanitize_key',
			is_array( $input['object_types'] ?? null ) ? $input['object_types'] : []
		) ) ) );
		if ( empty( $objects ) ) {
			throw new \InvalidArgumentException( __( 'Select at least one post type for this taxonomy.', 'core-blueprint' ) );
		}

		$rewrite = sanitize_title( (string) ( $input['rewrite_slug'] ?? '' ) );
		if ( '' === $rewrite ) {
			$rewrite = $key;
		}

		return [
			'key'               => $key,
			'singular_label'    => $singular,
			'plural_label'      => $plural,
			'description'       => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'object_types'      => $objects,
			'public'            => ! empty( $input['public'] ),
			'show_in_rest'      => ! empty( $input['show_in_rest'] ),
			'hierarchical'      => ! empty( $input['hierarchical'] ),
			'show_admin_column' => ! empty( $input['show_admin_column'] ),
			'rewrite_slug'      => $rewrite,
		];
	}

	/** @return array<string,mixed> */
	public static function normalize_option_page( array $input ): array {
		$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug || strlen( $slug ) > 80 ) {
			throw new \InvalidArgumentException( __( 'Option Page slugs must be 1–80 lowercase characters, numbers, dashes or underscores.', 'core-blueprint' ) );
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$menu_label = sanitize_text_field( (string) ( $input['menu_label'] ?? '' ) );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( __( 'An Option Page title is required.', 'core-blueprint' ) );
		}
		if ( '' === $menu_label ) {
			$menu_label = $title;
		}

		$capability = sanitize_key( (string) ( $input['capability'] ?? 'manage_options' ) );
		if ( '' === $capability ) {
			$capability = 'manage_options';
		}

		$parent_slug = sanitize_text_field( (string) ( $input['parent_slug'] ?? '' ) );
		if ( str_contains( $parent_slug, '://' ) ) {
			throw new \InvalidArgumentException( __( 'Parent page must be a WordPress admin menu slug, not a URL.', 'core-blueprint' ) );
		}

		$icon = Icon::normalize_menu_icon( (string) ( $input['icon'] ?? 'dashicons-admin-generic' ), 'dashicons-admin-generic' );

		$position = null;
		if ( isset( $input['position'] ) && '' !== (string) $input['position'] && is_numeric( $input['position'] ) ) {
			$position = max( 1, min( 999, (int) $input['position'] ) );
		}

		return [
			'slug'        => $slug,
			'title'       => $title,
			'menu_label'  => $menu_label,
			'description' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'parent_slug' => $parent_slug,
			'capability'  => $capability,
			'position'    => $position,
			'icon'        => $icon,
		];
	}

	/** @return array<string,mixed> */
	public static function normalize_field_group( array $input, ?string $existing_id = null ): array {
		$id = null !== $existing_id ? sanitize_key( $existing_id ) : sanitize_key( (string) ( $input['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = self::generate_id( 'group' );
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( __( 'A field group title is required.', 'core-blueprint' ) );
		}

		$post_types = array_values( array_unique( array_filter( array_map(
			'sanitize_key',
			is_array( $input['post_types'] ?? null ) ? $input['post_types'] : []
		) ) ) );
		$option_pages = array_values( array_unique( array_filter( array_map(
			'sanitize_key',
			is_array( $input['option_pages'] ?? null ) ? $input['option_pages'] : []
		) ) ) );
		$term_taxonomies = array_values( array_unique( array_filter( array_map(
			'sanitize_key',
			is_array( $input['term_taxonomies'] ?? null ) ? $input['term_taxonomies'] : []
		) ) ) );
		$user_enabled = ! empty( $input['user_enabled'] );
		$user_roles = array_values( array_unique( array_filter( array_map(
			'sanitize_key',
			is_array( $input['user_roles'] ?? null ) ? $input['user_roles'] : []
		) ) ) );
		if ( empty( $post_types ) && empty( $option_pages ) && empty( $term_taxonomies ) && ! $user_enabled ) {
			throw new \InvalidArgumentException( __( 'Select at least one Post Type, Option Page, taxonomy term context or user-profile context for this field group.', 'core-blueprint' ) );
		}

		$context = sanitize_key( (string) ( $input['context'] ?? 'normal' ) );
		if ( ! in_array( $context, [ 'normal', 'side' ], true ) ) {
			$context = 'normal';
		}
		$priority = sanitize_key( (string) ( $input['priority'] ?? 'default' ) );
		if ( ! in_array( $priority, [ 'high', 'default', 'low' ], true ) ) {
			$priority = 'default';
		}

		$existing = null !== $existing_id ? self::field_group( $existing_id ) : null;
		return [
			'id'          => $id,
			'title'       => $title,
			'description' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'post_types'  => $post_types,
			'option_pages'    => $option_pages,
			'term_taxonomies' => $term_taxonomies,
			'user_enabled'    => $user_enabled,
			'user_roles'      => $user_roles,
			'context'     => $context,
			'priority'    => $priority,
			'fields'      => is_array( $existing['fields'] ?? null ) ? $existing['fields'] : [],
		];
	}

	/** @return array<string,mixed> */
	public static function normalize_field( array $input, ?array $existing = null ): array {
		$id = is_array( $existing ) ? sanitize_key( (string) ( $existing['id'] ?? '' ) ) : sanitize_key( (string) ( $input['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = self::generate_id( 'field' );
		}

		$label = sanitize_text_field( (string) ( $input['label'] ?? '' ) );
		if ( '' === $label ) {
			throw new \InvalidArgumentException( __( 'A field label is required.', 'core-blueprint' ) );
		}

		$name = is_array( $existing )
			? sanitize_key( (string) ( $existing['name'] ?? '' ) )
			: sanitize_key( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name || strlen( $name ) > 191 ) {
			throw new \InvalidArgumentException( __( 'Field names must be valid WordPress meta keys and may not exceed 191 characters.', 'core-blueprint' ) );
		}
		if ( str_starts_with( $name, '_' ) ) {
			throw new \InvalidArgumentException( __( 'Core Blueprint field names may not start with an underscore because that marks protected WordPress metadata.', 'core-blueprint' ) );
		}

		$type = sanitize_key( (string) ( $input['type'] ?? 'text' ) );
		if ( ! FieldTypes::exists( $type ) ) {
			throw new \InvalidArgumentException( __( 'Select a supported field type.', 'core-blueprint' ) );
		}

		$choices = [];
		if ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
			$choices = FieldTypes::parse_choices( (string) ( $input['choices_text'] ?? '' ) );
			if ( empty( $choices ) ) {
				throw new \InvalidArgumentException( __( 'Choice fields require at least one value.', 'core-blueprint' ) );
			}
		}

		$relation_multiple = ! empty( $input['relation_multiple'] );
		$relation_post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $input['relation_post_types'] ?? [] ) ) ) ) );
		$relation_roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $input['relation_roles'] ?? [] ) ) ) ) );
		$relation_taxonomies = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $input['relation_taxonomies'] ?? [] ) ) ) ) );
		$existing_sub_fields = is_array( $existing['sub_fields'] ?? null ) ? $existing['sub_fields'] : [];
		$sub_fields = [];
		if ( FieldTypes::is_structured_type( $type ) ) {
			$sub_fields = self::normalize_sub_fields( is_array( $input['sub_fields'] ?? null ) ? $input['sub_fields'] : [], $existing_sub_fields );
			if ( empty( $sub_fields ) ) {
				throw new \InvalidArgumentException( __( 'Group and Repeater fields require at least one subfield.', 'core-blueprint' ) );
			}
		}
		$conditional_logic = self::normalize_conditional_logic( is_array( $input['conditional_logic'] ?? null ) ? $input['conditional_logic'] : [] );
		if ( 'post_relation' === $type && empty( $relation_post_types ) ) {
			throw new \InvalidArgumentException( __( 'Post / Object Relation fields require at least one allowed post type.', 'core-blueprint' ) );
		}
		if ( 'term_relation' === $type && empty( $relation_taxonomies ) ) {
			throw new \InvalidArgumentException( __( 'Taxonomy / Term Relation fields require at least one allowed taxonomy.', 'core-blueprint' ) );
		}

		$default = $input['default_value'] ?? '';
		if ( FieldTypes::is_structured_type( $type ) ) {
			$default = [];
		} elseif ( in_array( $type, [ 'image', 'file' ], true ) || ( FieldTypes::is_relation_type( $type ) && ! $relation_multiple ) ) {
			$default = 0;
		} elseif ( 'gallery' === $type || ( FieldTypes::is_relation_type( $type ) && $relation_multiple ) ) {
			$default = [];
		}
		if ( 'checkbox' === $type && ! is_array( $default ) ) {
			$default = array_values( array_filter( array_map( 'trim', explode( ',', (string) $default ) ) ) );
		}

		$repeater_min = max( 0, min( 100, (int) ( $input['repeater_min'] ?? 0 ) ) );
		$repeater_max = max( 0, min( 500, (int) ( $input['repeater_max'] ?? 0 ) ) );
		if ( $repeater_max > 0 && $repeater_max < $repeater_min ) {
			throw new \InvalidArgumentException( __( 'Repeater maximum rows must be greater than or equal to minimum rows.', 'core-blueprint' ) );
		}

		$field = [
			'id'            => $id,
			'name'          => $name,
			'label'         => $label,
			'type'          => $type,
			'instructions'  => sanitize_textarea_field( (string) ( $input['instructions'] ?? '' ) ),
			'required'      => ! empty( $input['required'] ),
			'show_in_rest'  => ! empty( $input['show_in_rest'] ),
			'placeholder'   => sanitize_text_field( (string) ( $input['placeholder'] ?? '' ) ),
			'default_value' => $default,
			'choices'       => $choices,
			'min'           => is_numeric( $input['min'] ?? null ) ? (string) $input['min'] : '',
			'max'           => is_numeric( $input['max'] ?? null ) ? (string) $input['max'] : '',
			'step'          => is_numeric( $input['step'] ?? null ) ? (string) $input['step'] : '',
			'rows'                => max( 2, min( 20, (int) ( $input['rows'] ?? 5 ) ) ),
			'relation_multiple'   => $relation_multiple,
			'relation_post_types' => $relation_post_types,
			'relation_roles'      => $relation_roles,
			'relation_taxonomies' => $relation_taxonomies,
			'sub_fields'           => $sub_fields,
			'repeater_min'         => $repeater_min,
			'repeater_max'         => $repeater_max,
			'conditional_logic'    => $conditional_logic,
		];
		$field['default_value'] = FieldTypes::sanitize_value( $field, $field['default_value'] );
		return $field;
	}


	/**
	 * Normalize ordered subfield definitions for Group and Repeater fields.
	 * Structured fields are intentionally one level deep in schema v5.
	 *
	 * @param array<int|string,mixed> $rows
	 * @param array<string,array<string,mixed>> $existing
	 * @return array<string,array<string,mixed>>
	 */
	public static function normalize_sub_fields( array $rows, array $existing = [] ): array {
		$result = [];
		$names = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$before = '' !== $id && isset( $existing[ $id ] ) && is_array( $existing[ $id ] ) ? $existing[ $id ] : null;
			if ( null === $before && '' === $id ) {
				$id = self::generate_id( 'subfield' );
			}
			if ( null !== $before ) {
				$id = (string) ( $before['id'] ?? $id );
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$name = null !== $before ? sanitize_key( (string) ( $before['name'] ?? '' ) ) : sanitize_key( (string) ( $row['name'] ?? '' ) );
			$type = sanitize_key( (string) ( $row['type'] ?? 'text' ) );
			if ( '' === $label || '' === $name ) {
				throw new \InvalidArgumentException( __( 'Every structured subfield requires a label and field name.', 'core-blueprint' ) );
			}
			if ( str_starts_with( $name, '_' ) || strlen( $name ) > 191 || isset( $names[ $name ] ) ) {
				throw new \InvalidArgumentException( __( 'Structured subfield names must be unique valid WordPress keys and may not start with an underscore.', 'core-blueprint' ) );
			}
			if ( ! FieldTypes::exists( $type ) || FieldTypes::is_structured_type( $type ) ) {
				throw new \InvalidArgumentException( __( 'Structured subfields must use a supported non-structured field type.', 'core-blueprint' ) );
			}
			$names[ $name ] = true;
			$choices = in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ? FieldTypes::parse_choices( (string) ( $row['choices_text'] ?? '' ) ) : [];
			if ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) && empty( $choices ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Subfield %s requires at least one choice.', 'core-blueprint' ), $label ) );
			}
			$relation_multiple = ! empty( $row['relation_multiple'] );
			$relation_post_types = array_values( array_unique( array_filter( array_map(
				'sanitize_key',
				(array) ( $row['relation_post_types'] ?? [] )
			) ) ) );
			$relation_roles = array_values( array_unique( array_filter( array_map(
				'sanitize_key',
				(array) ( $row['relation_roles'] ?? [] )
			) ) ) );
			$relation_taxonomies = array_values( array_unique( array_filter( array_map(
				'sanitize_key',
				(array) ( $row['relation_taxonomies'] ?? [] )
			) ) ) );
			if ( 'post_relation' === $type && empty( $relation_post_types ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Subfield %s requires at least one allowed post type.', 'core-blueprint' ), $label ) );
			}
			if ( 'term_relation' === $type && empty( $relation_taxonomies ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Subfield %s requires at least one allowed taxonomy.', 'core-blueprint' ), $label ) );
			}
			$sub = [
				'id'                    => $id,
				'name'                  => $name,
				'label'                 => $label,
				'type'                  => $type,
				'instructions'          => sanitize_textarea_field( (string) ( $row['instructions'] ?? '' ) ),
				'required'              => ! empty( $row['required'] ),
				'placeholder'           => sanitize_text_field( (string) ( $row['placeholder'] ?? '' ) ),
				'default_value'         => $row['default_value'] ?? '',
				'choices'               => $choices,
				'min'                   => is_numeric( $row['min'] ?? null ) ? (string) $row['min'] : '',
				'max'                   => is_numeric( $row['max'] ?? null ) ? (string) $row['max'] : '',
				'step'                  => is_numeric( $row['step'] ?? null ) ? (string) $row['step'] : '',
				'rows'                  => max( 2, min( 20, (int) ( $row['rows'] ?? 5 ) ) ),
				'relation_multiple'     => $relation_multiple,
				'relation_post_types'   => $relation_post_types,
				'relation_roles'        => $relation_roles,
				'relation_taxonomies'   => $relation_taxonomies,
			];
			if ( in_array( $type, [ 'image', 'file' ], true ) || ( FieldTypes::is_relation_type( $type ) && ! $relation_multiple ) ) {
				$sub['default_value'] = 0;
			} elseif ( 'gallery' === $type || ( FieldTypes::is_relation_type( $type ) && $relation_multiple ) ) {
				$sub['default_value'] = [];
			} elseif ( 'checkbox' === $type && ! is_array( $sub['default_value'] ) ) {
				$sub['default_value'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $sub['default_value'] ) ) ) );
			}
			$sub['default_value'] = FieldTypes::sanitize_value( $sub, $sub['default_value'] );
			$result[ $id ] = $sub;
		}
		return $result;
	}

	/** @param array<int|string,mixed> $groups @return array<int,array<int,array{field:string,operator:string,value:string}>> */
	public static function normalize_conditional_logic( array $groups ): array {
		$normalized = [];
		foreach ( $groups as $rules ) {
			if ( ! is_array( $rules ) ) {
				continue;
			}
			$group = [];
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
				$operator = sanitize_key( (string) ( $rule['operator'] ?? 'equals' ) );
				if ( '' === $field || ! in_array( $operator, [ 'equals', 'not_equals', 'empty', 'not_empty' ], true ) ) {
					continue;
				}
				$group[] = [ 'field' => $field, 'operator' => $operator, 'value' => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ) ];
			}
			if ( ! empty( $group ) ) {
				$normalized[] = $group;
			}
		}
		return $normalized;
	}



	/** @param array<string,array<string,mixed>> $fields
	 *  @return array<string,array<string,mixed>>
	 */
	public static function clone_field_definitions( array $fields ): array {
		$cloned = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$copy = $field;
			$copy['id'] = self::generate_id( 'field' );
			if ( FieldTypes::is_structured_type( (string) ( $copy['type'] ?? '' ) ) && is_array( $copy['sub_fields'] ?? null ) ) {
				$sub_fields = [];
				foreach ( $copy['sub_fields'] as $sub_field ) {
					if ( ! is_array( $sub_field ) ) {
						continue;
					}
					$sub_field['id'] = self::generate_id( 'subfield' );
					$sub_fields[ (string) $sub_field['id'] ] = $sub_field;
				}
				$copy['sub_fields'] = $sub_fields;
			}
			$cloned[ (string) $copy['id'] ] = $copy;
		}
		return $cloned;
	}

	public static function suggest_field_copy_name( string $group_id, string $source_name ): string {
		$base = sanitize_key( $source_name );
		if ( '' === $base ) {
			$base = 'field';
		}
		$base = substr( $base, 0, 180 );
		$candidate = $base . '_copy';
		$suffix = 2;
		while ( self::field_name_conflicts( $group_id, [ 'name' => $candidate ], null ) ) {
			$tail = '_copy_' . $suffix;
			$candidate = substr( $base, 0, 191 - strlen( $tail ) ) . $tail;
			++$suffix;
		}
		return $candidate;
	}

	/** @return string[] */
	public static function field_group_conflicts( array $candidate, ?string $existing_group_id = null ): array {
		$names = [];
		foreach ( (array) ( $candidate['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && '' !== (string) ( $field['name'] ?? '' ) ) {
				$names[] = (string) $field['name'];
			}
		}
		if ( empty( $names ) ) {
			return [];
		}

		$conflicts = [];
		foreach ( self::field_groups() as $other_group_id => $other_group ) {
			if ( null !== $existing_group_id && $other_group_id === $existing_group_id ) {
				continue;
			}
			if ( ! self::field_groups_overlap( $candidate, $other_group ) ) {
				continue;
			}
			foreach ( (array) ( $other_group['fields'] ?? [] ) as $other_field ) {
				if ( is_array( $other_field ) && in_array( (string) ( $other_field['name'] ?? '' ), $names, true ) ) {
					$conflicts[] = (string) $other_field['name'];
				}
			}
		}
		return array_values( array_unique( array_filter( $conflicts ) ) );
	}

	public static function save_post_type( array $definition ): void {
		$definition = self::normalize_post_type( $definition );
		self::assert_definition_unlocked( self::post_type( (string) $definition['key'] ) );
		$data = self::all();
		$data['post_types'][ (string) $definition['key'] ] = $definition;
		self::write( $data, true );
	}

	public static function save_taxonomy( array $definition ): void {
		$definition = self::normalize_taxonomy( $definition );
		self::assert_definition_unlocked( self::taxonomy( (string) $definition['key'] ) );
		$data = self::all();
		$data['taxonomies'][ (string) $definition['key'] ] = $definition;
		self::write( $data, true );
	}

	public static function save_option_page( array $definition ): void {
		$definition = self::normalize_option_page( $definition );
		self::assert_definition_unlocked( self::option_page( (string) $definition['slug'] ) );
		$data = self::all();
		$data['option_pages'][ (string) $definition['slug'] ] = $definition;
		self::write( $data, false );
	}

	public static function save_field_group( array $definition, ?string $existing_id = null, array $seed_fields = [] ): array {
		if ( null !== $existing_id ) { self::assert_definition_unlocked( self::field_group( $existing_id ) ); }
		$definition = self::normalize_field_group( $definition, $existing_id );
		if ( null === $existing_id && ! empty( $seed_fields ) ) {
			$definition['fields'] = $seed_fields;
		}
		$data = self::all();
		$data['field_groups'][ (string) $definition['id'] ] = $definition;
		self::write( $data, false );
		return $definition;
	}

	public static function save_field( string $group_id, array $input, ?string $existing_field_id = null ): array {
		$group = self::field_group( $group_id );
		if ( null === $group ) {
			throw new \InvalidArgumentException( __( 'Field group not found.', 'core-blueprint' ) );
		}
		self::assert_definition_unlocked( $group );
		$existing = null !== $existing_field_id ? self::field( $group_id, $existing_field_id ) : null;
		$field = self::normalize_field( $input, $existing );
		if ( self::field_name_conflicts( $group_id, $field, $existing_field_id ) ) {
			throw new \InvalidArgumentException( __( 'That field name is already used by another field group on an overlapping location.', 'core-blueprint' ) );
		}

		$data = self::all();
		$fields = is_array( $data['field_groups'][ $group_id ]['fields'] ?? null ) ? $data['field_groups'][ $group_id ]['fields'] : [];
		$fields[ (string) $field['id'] ] = $field;
		$data['field_groups'][ $group_id ]['fields'] = $fields;
		self::write( $data, false );
		return $field;
	}

	public static function delete_post_type( string $key ): bool {
		$key  = sanitize_key( $key );
		if ( ! empty( self::post_type( $key )['_locked'] ?? false ) ) {
			return false;
		}
		$data = self::all();
		if ( ! isset( $data['post_types'][ $key ] ) ) {
			return false;
		}
		unset( $data['post_types'][ $key ] );
		self::write( $data, true );
		return true;
	}

	public static function delete_taxonomy( string $key ): bool {
		$key  = sanitize_key( $key );
		if ( ! empty( self::taxonomy( $key )['_locked'] ?? false ) ) {
			return false;
		}
		$data = self::all();
		if ( ! isset( $data['taxonomies'][ $key ] ) ) {
			return false;
		}
		unset( $data['taxonomies'][ $key ] );
		self::write( $data, true );
		return true;
	}

	public static function delete_option_page( string $slug ): bool {
		$slug = sanitize_key( $slug );
		if ( ! empty( self::option_page( $slug )['_locked'] ?? false ) ) {
			return false;
		}
		$data = self::all();
		if ( ! isset( $data['option_pages'][ $slug ] ) ) {
			return false;
		}
		unset( $data['option_pages'][ $slug ] );
		self::write( $data, false );
		return true;
	}

	public static function delete_field_group( string $id ): bool {
		$id = sanitize_key( $id );
		if ( ! empty( self::field_group( $id )['_locked'] ?? false ) ) {
			return false;
		}
		$data = self::all();
		if ( ! isset( $data['field_groups'][ $id ] ) ) {
			return false;
		}
		unset( $data['field_groups'][ $id ] );
		self::write( $data, false );
		return true;
	}

	public static function delete_field( string $group_id, string $field_id ): bool {
		$group_id = sanitize_key( $group_id );
		$field_id = sanitize_key( $field_id );
		if ( ! empty( self::field_group( $group_id )['_locked'] ?? false ) ) { return false; }
		$data = self::all();
		if ( ! isset( $data['field_groups'][ $group_id ]['fields'][ $field_id ] ) ) {
			return false;
		}
		unset( $data['field_groups'][ $group_id ]['fields'][ $field_id ] );
		self::write( $data, false );
		return true;
	}

	public static function reorder_fields( string $group_id, array $ordered_ids ): array {
		$group_id = sanitize_key( $group_id );
		self::assert_definition_unlocked( self::field_group( $group_id ) );
		$data = self::all();
		if ( ! isset( $data['field_groups'][ $group_id ] ) || ! is_array( $data['field_groups'][ $group_id ] ) ) {
			throw new \InvalidArgumentException( __( 'Field group not found.', 'core-blueprint' ) );
		}
		$fields = is_array( $data['field_groups'][ $group_id ]['fields'] ?? null ) ? $data['field_groups'][ $group_id ]['fields'] : [];
		if ( [] === $fields ) {
			return [];
		}

		$normalized_ids = [];
		foreach ( $ordered_ids as $field_id ) {
			$field_id = sanitize_key( (string) $field_id );
			if ( '' !== $field_id && isset( $fields[ $field_id ] ) && ! in_array( $field_id, $normalized_ids, true ) ) {
				$normalized_ids[] = $field_id;
			}
		}

		$reordered = [];
		foreach ( $normalized_ids as $field_id ) {
			$reordered[ $field_id ] = $fields[ $field_id ];
		}
		foreach ( $fields as $field_id => $field ) {
			if ( ! isset( $reordered[ $field_id ] ) ) {
				$reordered[ $field_id ] = $field;
			}
		}

		$data['field_groups'][ $group_id ]['fields'] = $reordered;
		self::write( $data, false );
		return $reordered;
	}

	private static function field_name_conflicts( string $group_id, array $candidate, ?string $existing_field_id ): bool {
		$group = self::field_group( $group_id );
		if ( null === $group ) {
			return true;
		}
		foreach ( self::field_groups() as $other_group_id => $other_group ) {
			if ( ! self::field_groups_overlap( $group, $other_group ) ) {
				continue;
			}
			foreach ( (array) ( $other_group['fields'] ?? [] ) as $other_field_id => $other_field ) {
				if ( $other_group_id === $group_id && null !== $existing_field_id && $other_field_id === $existing_field_id ) {
					continue;
				}
				if ( (string) ( $other_field['name'] ?? '' ) === (string) ( $candidate['name'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}


	public static function field_groups_overlap( array $a, array $b ): bool {
		if ( array_intersect( (array) ( $a['post_types'] ?? [] ), (array) ( $b['post_types'] ?? [] ) ) ) {
			return true;
		}
		if ( array_intersect( (array) ( $a['option_pages'] ?? [] ), (array) ( $b['option_pages'] ?? [] ) ) ) {
			return true;
		}
		if ( array_intersect( (array) ( $a['term_taxonomies'] ?? [] ), (array) ( $b['term_taxonomies'] ?? [] ) ) ) {
			return true;
		}
		if ( ! empty( $a['user_enabled'] ) && ! empty( $b['user_enabled'] ) ) {
			$roles_a = (array) ( $a['user_roles'] ?? [] );
			$roles_b = (array) ( $b['user_roles'] ?? [] );
			return empty( $roles_a ) || empty( $roles_b ) || (bool) array_intersect( $roles_a, $roles_b );
		}
		return false;
	}

	public static function option_value_key( string $page_slug, string $field_name ): string {
		$page_slug = sanitize_key( $page_slug );
		$field_name = sanitize_key( $field_name );
		$base = 'cb_cm_opt_' . $page_slug . '_' . $field_name;
		if ( strlen( $base ) <= 191 ) {
			return $base;
		}
		$hash = substr( hash( 'sha256', $page_slug . '|' . $field_name ), 0, 16 );
		$prefix = 'cb_cm_opt_' . substr( $page_slug, 0, 48 ) . '_';
		$room = max( 1, 191 - strlen( $prefix ) - strlen( $hash ) - 1 );
		return $prefix . substr( $field_name, 0, $room ) . '_' . $hash;
	}

	private static function generate_id( string $prefix ): string {
		return sanitize_key( $prefix . '_' . str_replace( '-', '', wp_generate_uuid4() ) );
	}

	/**
	 * Merge a validated imported schema into user-managed definitions.
	 * Definitions absent from the import remain untouched.
	 *
	 * @return array<string,int>
	 */
	public static function merge_imported_schema( array $document, bool $overwrite ): array {
		$data = self::all();
		$counts = [ 'post_types' => 0, 'taxonomies' => 0, 'option_pages' => 0, 'field_groups' => 0 ];
		foreach ( array_keys( $counts ) as $section ) {
			foreach ( (array) ( $document[ $section ] ?? [] ) as $key => $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}
				if ( isset( $data[ $section ][ $key ] ) && ! $overwrite ) {
					continue;
				}
				$data[ $section ][ $key ] = $definition;
				++$counts[ $section ];
			}
		}
		self::write( $data, ! empty( $counts['post_types'] ) || ! empty( $counts['taxonomies'] ) );
		return $counts;
	}

	/**
	 * Restore only Content Models definitions touched by one failed Profile apply.
	 *
	 * Definitions outside the imported target remain untouched. A touched
	 * definition is restored only when its current raw state still matches either
	 * the imported target or the pre-apply snapshot. Any third state is treated as
	 * a concurrent mutation and fails closed instead of being overwritten.
	 *
	 * @internal Core Blueprint Profiles rollback boundary only.
	 */
	public static function restore_profile_schema( array $snapshot, array $target ): void {
		$maps = [
			'post_types'   => 'post_type',
			'taxonomies'   => 'taxonomy',
			'option_pages'  => 'option_page',
			'field_groups' => 'field_group',
		];
		$data = self::all();
		$rewrite_dirty = false;

		foreach ( $maps as $section => $getter ) {
			if ( ! isset( $snapshot[ $section ], $target[ $section ] ) || ! is_array( $snapshot[ $section ] ) || ! is_array( $target[ $section ] ) ) {
				throw new \InvalidArgumentException( __( 'The Content Models rollback snapshot is invalid.', 'core-blueprint' ) );
			}

			foreach ( $target[ $section ] as $key => $target_definition ) {
				$key = (string) $key;
				$current_definition = $data[ $section ][ $key ] ?? null;
				$snapshot_has = array_key_exists( $key, $snapshot[ $section ] );
				$snapshot_definition = $snapshot_has ? $snapshot[ $section ][ $key ] : null;

				if ( $current_definition !== $target_definition && ( ! $snapshot_has || $current_definition !== $snapshot_definition ) ) {
					throw new \RuntimeException( __( 'A Content Models definition changed during Profile rollback and was not overwritten.', 'core-blueprint' ) );
				}

				self::assert_definition_unlocked( self::{$getter}( $key ) );
				if ( $snapshot_has ) {
					$data[ $section ][ $key ] = $snapshot_definition;
				} else {
					unset( $data[ $section ][ $key ] );
				}

				if ( 'post_types' === $section || 'taxonomies' === $section ) {
					$rewrite_dirty = true;
				}
			}
		}

		self::write( $data, $rewrite_dirty );
		$restored = self::all();
		foreach ( $maps as $section => $getter ) {
			unset( $getter );
			foreach ( $target[ $section ] as $key => $unused ) {
				$key = (string) $key;
				if ( array_key_exists( $key, $snapshot[ $section ] ) ) {
					if ( ! array_key_exists( $key, $restored[ $section ] ) || $restored[ $section ][ $key ] !== $snapshot[ $section ][ $key ] ) {
						throw new \RuntimeException( __( 'The Content Models rollback snapshot could not be restored.', 'core-blueprint' ) );
					}
				} elseif ( array_key_exists( $key, $restored[ $section ] ) ) {
					throw new \RuntimeException( __( 'The Content Models rollback snapshot could not be restored.', 'core-blueprint' ) );
				}
			}
		}
	}


	private static function assert_definition_unlocked( ?array $definition ): void {
		if ( is_array( $definition ) && ! empty( $definition['_locked'] ) ) {
			throw new \InvalidArgumentException( __( 'This Content Model is managed by another plugin and is locked against admin mutations.', 'core-blueprint' ) );
		}
	}

	private static function write( array $data, bool $rewrite_dirty ): void {
		$data['schema_version'] = self::SCHEMA_VERSION;
		update_option( self::OPTION, $data, false );
		if ( $rewrite_dirty ) {
			Rewrite::mark_dirty();
		}
	}
}
