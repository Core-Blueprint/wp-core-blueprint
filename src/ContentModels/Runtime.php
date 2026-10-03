<?php
declare(strict_types=1);
/**
 * Registers saved Core Blueprint content models with WordPress.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels;

use CoreBlueprint\Core\UI\Icon;

defined( 'ABSPATH' ) || exit;

final class Runtime {
	/** @var array<int,array{type:string,key:string,message:string}> */
	private static array $errors = [];

	public static function boot(): void {
		if ( ! State::is_enabled() ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
	}

	public static function register(): void {
		self::$errors = [];

		foreach ( Repository::post_types() as $key => $definition ) {
			self::register_post_type( (string) $key, $definition );
		}
		foreach ( Repository::taxonomies() as $key => $definition ) {
			self::register_taxonomy( (string) $key, $definition );
		}
		self::register_fields();
		self::register_term_fields();
		self::register_user_fields();
		self::register_option_settings();
	}

	/** @return array<int,array{type:string,key:string,message:string}> */
	public static function errors(): array {
		return self::$errors;
	}

	private static function register_post_type( string $key, array $definition ): void {
		if ( post_type_exists( $key ) ) {
			self::error( 'post_type', $key, __( 'A post type with this key is already registered by WordPress or another plugin.', 'core-blueprint' ) );
			return;
		}

		$singular = (string) ( $definition['singular_label'] ?? $key );
		$plural   = (string) ( $definition['plural_label'] ?? $singular );
		$public   = ! empty( $definition['public'] );

		$args = [
			'labels' => [
				'name'                  => $plural,
				'singular_name'         => $singular,
				'menu_name'             => $plural,
				'name_admin_bar'        => $singular,
				'add_new'               => __( 'Add New', 'core-blueprint' ),
				/* translators: %s: singular Content Model label. */
				'add_new_item'          => sprintf( __( 'Add New %s', 'core-blueprint' ), $singular ),
				/* translators: %s: singular Content Model label. */
				'edit_item'             => sprintf( __( 'Edit %s', 'core-blueprint' ), $singular ),
				/* translators: %s: singular Content Model label. */
				'new_item'              => sprintf( __( 'New %s', 'core-blueprint' ), $singular ),
				/* translators: %s: singular Content Model label. */
				'view_item'             => sprintf( __( 'View %s', 'core-blueprint' ), $singular ),
				/* translators: %s: plural Content Model label. */
				'view_items'            => sprintf( __( 'View %s', 'core-blueprint' ), $plural ),
				/* translators: %s: plural Content Model label. */
				'search_items'          => sprintf( __( 'Search %s', 'core-blueprint' ), $plural ),
				/* translators: %s: lowercase plural Content Model label. */
				'not_found'             => sprintf( __( 'No %s found.', 'core-blueprint' ), strtolower( $plural ) ),
				/* translators: %s: lowercase plural Content Model label. */
				'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'core-blueprint' ), strtolower( $plural ) ),
				/* translators: %s: plural Content Model label. */
				'all_items'             => sprintf( __( 'All %s', 'core-blueprint' ), $plural ),
				/* translators: %s: singular Content Model label. */
				'archives'              => sprintf( __( '%s Archives', 'core-blueprint' ), $singular ),
				/* translators: %s: singular Content Model label. */
				'attributes'            => sprintf( __( '%s Attributes', 'core-blueprint' ), $singular ),
				'featured_image'        => __( 'Featured image', 'core-blueprint' ),
				'set_featured_image'    => __( 'Set featured image', 'core-blueprint' ),
				'remove_featured_image' => __( 'Remove featured image', 'core-blueprint' ),
				'use_featured_image'    => __( 'Use as featured image', 'core-blueprint' ),
			],
			'description'         => (string) ( $definition['description'] ?? '' ),
			'public'              => $public,
			'publicly_queryable'  => $public,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => Icon::menu_icon_argument( (string) ( $definition['icon'] ?? 'dashicons-admin-post' ), 'dashicons-admin-post' ),
			'show_in_rest'        => ! empty( $definition['show_in_rest'] ),
			'has_archive'         => $public && ! empty( $definition['has_archive'] ),
			'hierarchical'        => ! empty( $definition['hierarchical'] ),
			'rewrite'             => $public ? [ 'slug' => (string) ( $definition['rewrite_slug'] ?? $key ) ] : false,
			'supports'            => is_array( $definition['supports'] ?? null ) ? $definition['supports'] : [ 'title', 'editor' ],
			'map_meta_cap'        => true,
			'capability_type'     => 'post',
			'delete_with_user'    => false,
		];

		$result = register_post_type( $key, $args );
		if ( is_wp_error( $result ) ) {
			self::error( 'post_type', $key, $result->get_error_message() );
		}
	}

	private static function register_taxonomy( string $key, array $definition ): void {
		if ( taxonomy_exists( $key ) ) {
			self::error( 'taxonomy', $key, __( 'A taxonomy with this key is already registered by WordPress or another plugin.', 'core-blueprint' ) );
			return;
		}

		$object_types = array_values( array_filter(
			(array) ( $definition['object_types'] ?? [] ),
			static fn( $post_type ): bool => is_string( $post_type ) && post_type_exists( $post_type )
		) );
		if ( empty( $object_types ) ) {
			self::error( 'taxonomy', $key, __( 'None of this taxonomy’s assigned post types are currently registered.', 'core-blueprint' ) );
			return;
		}

		$singular = (string) ( $definition['singular_label'] ?? $key );
		$plural   = (string) ( $definition['plural_label'] ?? $singular );
		$public   = ! empty( $definition['public'] );

		$result = register_taxonomy( $key, $object_types, [
			'labels' => [
				'name'                       => $plural,
				'singular_name'              => $singular,
				/* translators: %s: plural taxonomy label. */
				'search_items'               => sprintf( __( 'Search %s', 'core-blueprint' ), $plural ),
				/* translators: %s: plural taxonomy label. */
				'all_items'                  => sprintf( __( 'All %s', 'core-blueprint' ), $plural ),
				/* translators: %s: singular taxonomy label. */
				'edit_item'                  => sprintf( __( 'Edit %s', 'core-blueprint' ), $singular ),
				/* translators: %s: singular taxonomy label. */
				'update_item'                => sprintf( __( 'Update %s', 'core-blueprint' ), $singular ),
				/* translators: %s: singular taxonomy label. */
				'add_new_item'               => sprintf( __( 'Add New %s', 'core-blueprint' ), $singular ),
				/* translators: %s: singular taxonomy label. */
				'new_item_name'              => sprintf( __( 'New %s Name', 'core-blueprint' ), $singular ),
				'menu_name'                  => $plural,
			],
			'description'       => (string) ( $definition['description'] ?? '' ),
			'public'            => $public,
			'publicly_queryable'=> $public,
			'show_ui'           => true,
			'show_admin_column' => ! empty( $definition['show_admin_column'] ),
			'show_in_rest'      => ! empty( $definition['show_in_rest'] ),
			'hierarchical'      => ! empty( $definition['hierarchical'] ),
			'rewrite'           => $public ? [ 'slug' => (string) ( $definition['rewrite_slug'] ?? $key ) ] : false,
		] );

		if ( is_wp_error( $result ) ) {
			self::error( 'taxonomy', $key, $result->get_error_message() );
		}
	}

	private static function register_fields(): void {
		foreach ( Repository::field_groups() as $group_id => $group ) {
			$post_types = array_values( array_filter(
				(array) ( $group['post_types'] ?? [] ),
				static fn( $post_type ): bool => is_string( $post_type ) && post_type_exists( $post_type )
			) );

			foreach ( $post_types as $post_type ) {
				$fields = (array) ( $group['fields'] ?? [] );
				$needs_rest_meta = false;
				foreach ( $fields as $candidate ) {
					if ( is_array( $candidate ) && ! empty( $candidate['show_in_rest'] ) ) {
						$needs_rest_meta = true;
						break;
					}
				}
				if ( $needs_rest_meta && ! post_type_supports( $post_type, 'custom-fields' ) ) {
					add_post_type_support( $post_type, 'custom-fields' );
				}

				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$name = (string) ( $field['name'] ?? '' );
					if ( '' === $name || ! FieldTypes::exists( (string) ( $field['type'] ?? '' ) ) ) {
						self::error( 'field_group', (string) $group_id, __( 'A saved field definition is invalid and was not registered.', 'core-blueprint' ) );
						continue;
					}
					if ( function_exists( 'registered_meta_key_exists' ) && registered_meta_key_exists( 'post', $name, $post_type ) ) {
						/* translators: %s: WordPress post type key. */
						self::error( 'field', $name, sprintf( __( 'This meta key is already registered for post type %s by WordPress or another plugin.', 'core-blueprint' ), $post_type ) );
						continue;
					}

					$registered = register_post_meta( $post_type, $name, FieldTypes::meta_args( $field ) );
					if ( false === $registered ) {
						/* translators: %s: WordPress post type key. */
						self::error( 'field', $name, sprintf( __( 'The field could not be registered for post type %s.', 'core-blueprint' ), $post_type ) );
					}
				}
			}
		}
	}


	private static function register_term_fields(): void {
		foreach ( Repository::field_groups() as $group_id => $group ) {
			$taxonomies = array_values( array_filter(
				(array) ( $group['term_taxonomies'] ?? [] ),
				static fn( $taxonomy ): bool => is_string( $taxonomy ) && taxonomy_exists( $taxonomy )
			) );
			foreach ( $taxonomies as $taxonomy ) {
				foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$name = (string) ( $field['name'] ?? '' );
					if ( '' === $name || ! FieldTypes::exists( (string) ( $field['type'] ?? '' ) ) ) {
						self::error( 'field_group', (string) $group_id, __( 'A saved Term Meta field definition is invalid and was not registered.', 'core-blueprint' ) );
						continue;
					}
					if ( function_exists( 'registered_meta_key_exists' ) && registered_meta_key_exists( 'term', $name, $taxonomy ) ) {
						/* translators: %s: WordPress taxonomy key. */
						self::error( 'field', $name, sprintf( __( 'This meta key is already registered for taxonomy %s by WordPress or another plugin.', 'core-blueprint' ), $taxonomy ) );
						continue;
					}
					if ( false === register_term_meta( $taxonomy, $name, FieldTypes::meta_args( $field, 'term' ) ) ) {
						/* translators: %s: WordPress taxonomy key. */
						self::error( 'field', $name, sprintf( __( 'The field could not be registered for taxonomy %s.', 'core-blueprint' ), $taxonomy ) );
					}
				}
			}
		}
	}

	private static function register_user_fields(): void {
		$seen = [];
		foreach ( Repository::field_groups() as $group_id => $group ) {
			if ( empty( $group['user_enabled'] ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				if ( '' === $name || isset( $seen[ $name ] ) ) {
					continue;
				}
				if ( ! FieldTypes::exists( (string) ( $field['type'] ?? '' ) ) ) {
					self::error( 'field_group', (string) $group_id, __( 'A saved User Meta field definition is invalid and was not registered.', 'core-blueprint' ) );
					continue;
				}
				if ( function_exists( 'registered_meta_key_exists' ) && registered_meta_key_exists( 'user', $name ) ) {
					self::error( 'field', $name, __( 'This user meta key is already registered by WordPress or another plugin.', 'core-blueprint' ) );
					continue;
				}
				if ( false === register_meta( 'user', $name, FieldTypes::meta_args( $field, 'user' ) ) ) {
					self::error( 'field', $name, __( 'The user meta field could not be registered.', 'core-blueprint' ) );
					continue;
				}
				$seen[ $name ] = true;
			}
		}
	}

	private static function register_option_settings(): void {
		foreach ( Repository::field_groups() as $group_id => $group ) {
			$option_pages = array_values( array_filter(
				(array) ( $group['option_pages'] ?? [] ),
				static fn( $slug ): bool => is_string( $slug ) && null !== Repository::option_page( $slug )
			) );
			if ( empty( $option_pages ) ) {
				continue;
			}

			foreach ( $option_pages as $page_slug ) {
				$setting_group = 'cb_cm_option_page_' . sanitize_key( $page_slug );
				foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$name = (string) ( $field['name'] ?? '' );
					if ( '' === $name || ! FieldTypes::exists( (string) ( $field['type'] ?? '' ) ) ) {
						self::error( 'field_group', (string) $group_id, __( 'A saved Option Page field definition is invalid and was not registered.', 'core-blueprint' ) );
						continue;
					}
					register_setting(
						$setting_group,
						Repository::option_value_key( $page_slug, $name ),
						FieldTypes::setting_args( $field )
					);
				}
			}
		}
	}

	private static function error( string $type, string $key, string $message ): void {
		self::$errors[] = [
			'type'    => $type,
			'key'     => $key,
			'message' => $message,
		];
	}
}
