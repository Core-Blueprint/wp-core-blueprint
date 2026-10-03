<?php
declare(strict_types=1);
/**
 * Admin write boundary for Content Models definitions.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Admin;

use CoreBlueprint\Core\ContentModels\FieldTypes;
use CoreBlueprint\Core\ContentModels\LocationMatcher;
use CoreBlueprint\Core\ContentModels\Repository;
use CoreBlueprint\Core\ContentModels\State;
use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Actions {
	private const CAPABILITY = 'cb_manage_content_models';

	public static function boot(): void {
		add_action( 'admin_post_cb_core_content_models_save_post_type', [ __CLASS__, 'save_post_type' ] );
		add_action( 'admin_post_cb_core_content_models_delete_post_type', [ __CLASS__, 'delete_post_type' ] );
		add_action( 'admin_post_cb_core_content_models_save_taxonomy', [ __CLASS__, 'save_taxonomy' ] );
		add_action( 'admin_post_cb_core_content_models_delete_taxonomy', [ __CLASS__, 'delete_taxonomy' ] );
		add_action( 'admin_post_cb_core_content_models_save_option_page', [ __CLASS__, 'save_option_page' ] );
		add_action( 'admin_post_cb_core_content_models_delete_option_page', [ __CLASS__, 'delete_option_page' ] );
		add_action( 'admin_post_cb_core_content_models_save_field_group', [ __CLASS__, 'save_field_group' ] );
		add_action( 'admin_post_cb_core_content_models_delete_field_group', [ __CLASS__, 'delete_field_group' ] );
		add_action( 'admin_post_cb_core_content_models_save_field', [ __CLASS__, 'save_field' ] );
		add_action( 'admin_post_cb_core_content_models_delete_field', [ __CLASS__, 'delete_field' ] );
		add_action( 'admin_post_cb_core_content_models_sort_fields', [ __CLASS__, 'sort_fields' ] );
		add_action( 'wp_ajax_cb_core_content_models_quick_save_field', [ __CLASS__, 'quick_save_field' ] );
		add_action( 'wp_ajax_cb_core_content_models_search_relation_objects', [ __CLASS__, 'search_relation_objects' ] );
	}

	public static function save_post_type(): void {
		self::guard( 'cb_core_content_models_save_post_type' );
		self::require_enabled();

		$original = isset( $_POST['original_key'] ) ? sanitize_key( wp_unslash( $_POST['original_key'] ) ) : '';
		$duplicate_source = isset( $_POST['duplicate_source'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_source'] ) ) : '';
		$input = [
			'key'            => isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : '',
			'singular_label' => isset( $_POST['singular_label'] ) ? wp_unslash( $_POST['singular_label'] ) : '',
			'plural_label'   => isset( $_POST['plural_label'] ) ? wp_unslash( $_POST['plural_label'] ) : '',
			'description'    => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'public'         => ! empty( $_POST['public'] ),
			'show_in_rest'   => ! empty( $_POST['show_in_rest'] ),
			'has_archive'    => ! empty( $_POST['has_archive'] ),
			'hierarchical'   => ! empty( $_POST['hierarchical'] ),
			'rewrite_slug'   => isset( $_POST['rewrite_slug'] ) ? wp_unslash( $_POST['rewrite_slug'] ) : '',
			'icon'           => isset( $_POST['icon'] ) ? wp_unslash( $_POST['icon'] ) : 'dashicons-admin-post',
			'supports'       => self::array_input( 'supports' ),
		];

		try {
			$definition = Repository::normalize_post_type( $input );
			$key = (string) $definition['key'];

			if ( '' !== $original && $original !== $key ) {
				throw new \InvalidArgumentException( __( 'Post type keys are immutable after creation. Create a new model or use a future schema migration to rename a key safely.', 'core-blueprint' ) );
			}

			$existing = '' !== $original ? Repository::post_type( $original ) : null;
			$duplicate_from = '' !== $duplicate_source ? Repository::post_type( $duplicate_source ) : null;
			if ( '' !== $duplicate_source && null === $duplicate_from ) {
				throw new \InvalidArgumentException( __( 'The source post type for this duplicate no longer exists.', 'core-blueprint' ) );
			}
			if ( '' === $original ) {
				if ( null !== Repository::post_type( $key ) || post_type_exists( $key ) ) {
					throw new \InvalidArgumentException( __( 'That post type key is already in use.', 'core-blueprint' ) );
				}
			}

			Repository::save_post_type( $definition );
			self::audit_definition(
				null !== $existing ? 'content_models_post_type_updated' : ( null !== $duplicate_from ? 'content_models_post_type_duplicated' : 'content_models_post_type_created' ),
				$key,
				$existing ?? $duplicate_from,
				$definition
			);

			self::redirect( 'post-types', 'saved', [ 'view' => 'edit', 'model' => $key ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( 'post-types', 'error', [
				'view'    => '' !== $duplicate_source ? 'duplicate' : 'edit',
				'model'   => '' !== $duplicate_source ? $duplicate_source : $original,
				'message' => $e->getMessage(),
			] );
		}
	}

	public static function delete_post_type(): void {
		self::guard( 'cb_core_content_models_delete_post_type' );
		self::require_enabled();
		$key = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';
		$existing = Repository::post_type( $key );
		if ( null === $existing ) {
			self::redirect( 'post-types', 'not-found' );
		}

		$used_by = [];
		foreach ( Repository::taxonomies() as $taxonomy_key => $taxonomy ) {
			if ( in_array( $key, (array) ( $taxonomy['object_types'] ?? [] ), true ) ) {
				$used_by[] = (string) $taxonomy_key;
			}
		}
		foreach ( Repository::field_groups() as $group_id => $group ) {
			if ( in_array( $key, (array) ( $group['post_types'] ?? [] ), true ) ) {
				$used_by[] = (string) $group_id;
			}
		}
		if ( ! empty( $used_by ) ) {
			self::redirect( 'post-types', 'in-use', [
				'view'  => 'delete',
				'model' => $key,
			] );
		}

		Repository::delete_post_type( $key );
		self::audit_definition( 'content_models_post_type_deleted', $key, $existing, null );
		self::redirect( 'post-types', 'deleted' );
	}

	public static function save_taxonomy(): void {
		self::guard( 'cb_core_content_models_save_taxonomy' );
		self::require_enabled();

		$original = isset( $_POST['original_key'] ) ? sanitize_key( wp_unslash( $_POST['original_key'] ) ) : '';
		$duplicate_source = isset( $_POST['duplicate_source'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_source'] ) ) : '';
		$raw_objects = self::array_input( 'object_types' );
		$allowed_objects = array_fill_keys( get_post_types( [], 'names' ), true );
		$objects = array_values( array_filter(
			array_map( 'sanitize_key', $raw_objects ),
			static fn( string $post_type ): bool => isset( $allowed_objects[ $post_type ] )
		) );

		$input = [
			'key'               => isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : '',
			'singular_label'    => isset( $_POST['singular_label'] ) ? wp_unslash( $_POST['singular_label'] ) : '',
			'plural_label'      => isset( $_POST['plural_label'] ) ? wp_unslash( $_POST['plural_label'] ) : '',
			'description'       => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'object_types'      => $objects,
			'public'            => ! empty( $_POST['public'] ),
			'show_in_rest'      => ! empty( $_POST['show_in_rest'] ),
			'hierarchical'      => ! empty( $_POST['hierarchical'] ),
			'show_admin_column' => ! empty( $_POST['show_admin_column'] ),
			'rewrite_slug'      => isset( $_POST['rewrite_slug'] ) ? wp_unslash( $_POST['rewrite_slug'] ) : '',
		];

		try {
			$definition = Repository::normalize_taxonomy( $input );
			$key = (string) $definition['key'];

			if ( '' !== $original && $original !== $key ) {
				throw new \InvalidArgumentException( __( 'Taxonomy keys are immutable after creation. Create a new model or use a future schema migration to rename a key safely.', 'core-blueprint' ) );
			}

			$existing = '' !== $original ? Repository::taxonomy( $original ) : null;
			$duplicate_from = '' !== $duplicate_source ? Repository::taxonomy( $duplicate_source ) : null;
			if ( '' !== $duplicate_source && null === $duplicate_from ) {
				throw new \InvalidArgumentException( __( 'The source taxonomy for this duplicate no longer exists.', 'core-blueprint' ) );
			}
			if ( '' === $original ) {
				if ( null !== Repository::taxonomy( $key ) || taxonomy_exists( $key ) ) {
					throw new \InvalidArgumentException( __( 'That taxonomy key is already in use.', 'core-blueprint' ) );
				}
			}

			Repository::save_taxonomy( $definition );
			self::audit_definition(
				null !== $existing ? 'content_models_taxonomy_updated' : ( null !== $duplicate_from ? 'content_models_taxonomy_duplicated' : 'content_models_taxonomy_created' ),
				$key,
				$existing ?? $duplicate_from,
				$definition
			);

			self::redirect( 'taxonomies', 'saved', [ 'view' => 'edit', 'model' => $key ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( 'taxonomies', 'error', [
				'view'    => '' !== $duplicate_source ? 'duplicate' : 'edit',
				'model'   => '' !== $duplicate_source ? $duplicate_source : $original,
				'message' => $e->getMessage(),
			] );
		}
	}

	public static function delete_taxonomy(): void {
		self::guard( 'cb_core_content_models_delete_taxonomy' );
		self::require_enabled();
		$key = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';
		$existing = Repository::taxonomy( $key );
		if ( null === $existing ) {
			self::redirect( 'taxonomies', 'not-found' );
		}

		Repository::delete_taxonomy( $key );
		self::audit_definition( 'content_models_taxonomy_deleted', $key, $existing, null );
		self::redirect( 'taxonomies', 'deleted' );
	}


	public static function save_option_page(): void {
		self::guard( 'cb_core_content_models_save_option_page' );
		self::require_enabled();

		$original = isset( $_POST['original_slug'] ) ? sanitize_key( wp_unslash( $_POST['original_slug'] ) ) : '';
		$duplicate_source = isset( $_POST['duplicate_source'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_source'] ) ) : '';
		$input = [
			'slug'        => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
			'title'       => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
			'menu_label'  => isset( $_POST['menu_label'] ) ? wp_unslash( $_POST['menu_label'] ) : '',
			'description' => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'parent_slug' => isset( $_POST['parent_slug'] ) ? wp_unslash( $_POST['parent_slug'] ) : '',
			'capability'  => isset( $_POST['capability'] ) ? wp_unslash( $_POST['capability'] ) : 'manage_options',
			'position'    => isset( $_POST['position'] ) ? wp_unslash( $_POST['position'] ) : '',
			'icon'        => isset( $_POST['icon'] ) ? wp_unslash( $_POST['icon'] ) : 'dashicons-admin-generic',
		];

		try {
			$definition = Repository::normalize_option_page( $input );
			$slug = (string) $definition['slug'];
			if ( '' !== $original && $original !== $slug ) {
				throw new \InvalidArgumentException( __( 'Option Page slugs are immutable after creation because they are part of the storage namespace. Duplicate the page when a new slug is required.', 'core-blueprint' ) );
			}
			$existing = '' !== $original ? Repository::option_page( $original ) : null;
			$duplicate_from = '' !== $duplicate_source ? Repository::option_page( $duplicate_source ) : null;
			if ( '' !== $original && null === $existing ) {
				throw new \InvalidArgumentException( __( 'Option Page definition not found.', 'core-blueprint' ) );
			}
			if ( '' !== $duplicate_source && null === $duplicate_from ) {
				throw new \InvalidArgumentException( __( 'The source Option Page for this duplicate no longer exists.', 'core-blueprint' ) );
			}
			if ( '' === $original && null !== Repository::option_page( $slug ) ) {
				throw new \InvalidArgumentException( __( 'That Option Page slug is already in use.', 'core-blueprint' ) );
			}
			if ( $slug === (string) ( $definition['parent_slug'] ?? '' ) ) {
				throw new \InvalidArgumentException( __( 'An Option Page cannot be its own parent.', 'core-blueprint' ) );
			}
			$parent_definition = Repository::option_page( (string) ( $definition['parent_slug'] ?? '' ) );
			if ( null !== $parent_definition && '' !== (string) ( $parent_definition['parent_slug'] ?? '' ) ) {
				throw new \InvalidArgumentException( __( 'WordPress admin menus support one submenu level. Choose a top-level Option Page or another WordPress admin menu as the parent.', 'core-blueprint' ) );
			}

			Repository::save_option_page( $definition );
			self::audit_definition(
				null !== $existing ? 'content_models_option_page_updated' : ( null !== $duplicate_from ? 'content_models_option_page_duplicated' : 'content_models_option_page_created' ),
				$slug,
				$existing ?? $duplicate_from,
				$definition
			);
			self::redirect( 'option-pages', 'saved', [ 'view' => 'edit', 'model' => $slug ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( 'option-pages', 'error', [
				'view'    => '' !== $duplicate_source ? 'duplicate' : 'edit',
				'model'   => '' !== $duplicate_source ? $duplicate_source : $original,
				'message' => $e->getMessage(),
			] );
		}
	}

	public static function delete_option_page(): void {
		self::guard( 'cb_core_content_models_delete_option_page' );
		self::require_enabled();
		$slug = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';
		$existing = Repository::option_page( $slug );
		if ( null === $existing ) {
			self::redirect( 'option-pages', 'not-found' );
		}

		foreach ( Repository::field_groups() as $group ) {
			if ( in_array( $slug, (array) ( $group['option_pages'] ?? [] ), true ) ) {
				self::redirect( 'option-pages', 'option-page-in-use', [ 'view' => 'delete', 'model' => $slug ] );
			}
		}
		foreach ( Repository::option_pages() as $other_slug => $other_page ) {
			if ( $other_slug !== $slug && $slug === (string) ( $other_page['parent_slug'] ?? '' ) ) {
				self::redirect( 'option-pages', 'option-page-in-use', [ 'view' => 'delete', 'model' => $slug ] );
			}
		}

		Repository::delete_option_page( $slug );
		self::audit_definition( 'content_models_option_page_deleted', $slug, $existing, null );
		self::redirect( 'option-pages', 'deleted' );
	}


	public static function save_field_group(): void {
		self::guard( 'cb_core_content_models_save_field_group' );
		self::require_enabled();

		$original = isset( $_POST['original_id'] ) ? sanitize_key( wp_unslash( $_POST['original_id'] ) ) : '';
		$duplicate_source = isset( $_POST['duplicate_source'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_source'] ) ) : '';
		$raw_post_types = self::array_input( 'post_types' );
		$allowed_post_types = array_fill_keys( get_post_types( [ 'show_ui' => true ], 'names' ), true );
		$post_types = array_values( array_filter(
			array_map( 'sanitize_key', $raw_post_types ),
			static fn( string $post_type ): bool => isset( $allowed_post_types[ $post_type ] )
		) );
		$raw_option_pages = self::array_input( 'option_pages' );
		$allowed_option_pages = array_fill_keys( array_keys( Repository::option_pages() ), true );
		$option_pages = array_values( array_filter(
			array_map( 'sanitize_key', $raw_option_pages ),
			static fn( string $page_slug ): bool => isset( $allowed_option_pages[ $page_slug ] )
		) );
		$raw_term_taxonomies = self::array_input( 'term_taxonomies' );
		$allowed_taxonomies = array_fill_keys( get_taxonomies( [ 'show_ui' => true ], 'names' ), true );
		$term_taxonomies = array_values( array_filter(
			array_map( 'sanitize_key', $raw_term_taxonomies ),
			static fn( string $taxonomy ): bool => isset( $allowed_taxonomies[ $taxonomy ] )
		) );
		$raw_user_roles = self::array_input( 'user_roles' );
		$roles = wp_roles();
		$allowed_roles = $roles ? array_fill_keys( array_keys( $roles->roles ), true ) : [];
		$user_roles = array_values( array_filter(
			array_map( 'sanitize_key', $raw_user_roles ),
			static fn( string $role ): bool => isset( $allowed_roles[ $role ] )
		) );

		$input = [
			'title'       => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
			'description' => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'post_types'   => $post_types,
			'option_pages'    => $option_pages,
			'term_taxonomies' => $term_taxonomies,
			'user_enabled'    => ! empty( $_POST['user_enabled'] ),
			'user_roles'      => $user_roles,
			'context'     => isset( $_POST['context'] ) ? wp_unslash( $_POST['context'] ) : 'normal',
			'priority'    => isset( $_POST['priority'] ) ? wp_unslash( $_POST['priority'] ) : 'default',
		];

		try {
			$existing = '' !== $original ? Repository::field_group( $original ) : null;
			$duplicate_from = '' !== $duplicate_source ? Repository::field_group( $duplicate_source ) : null;
			if ( '' !== $original && null === $existing ) {
				throw new \InvalidArgumentException( __( 'Field group not found.', 'core-blueprint' ) );
			}
			if ( '' !== $duplicate_source && null === $duplicate_from ) {
				throw new \InvalidArgumentException( __( 'The source field group for this duplicate no longer exists.', 'core-blueprint' ) );
			}
			$definition = Repository::normalize_field_group( $input, '' !== $original ? $original : null );
			$seed_fields = null !== $duplicate_from ? Repository::clone_field_definitions( (array) ( $duplicate_from['fields'] ?? [] ) ) : [];
			if ( ! empty( $seed_fields ) ) {
				$definition['fields'] = $seed_fields;
			}
			$conflicts = Repository::field_group_conflicts( $definition, '' !== $original ? $original : null );
			if ( ! empty( $conflicts ) ) {
				throw new \InvalidArgumentException( sprintf(
					/* translators: %s: comma-separated list of conflicting field locations. */
					__( 'These locations would create duplicate field keys: %s.', 'core-blueprint' ),
					implode( ', ', $conflicts )
				) );
			}

			$saved = Repository::save_field_group( $definition, '' !== $original ? $original : null, $seed_fields );
			$id = (string) $saved['id'];
			self::audit_definition(
				null !== $existing ? 'content_models_field_group_updated' : ( null !== $duplicate_from ? 'content_models_field_group_duplicated' : 'content_models_field_group_created' ),
				$id,
				$existing ?? $duplicate_from,
				$saved
			);
			self::redirect( 'field-groups', 'saved', [ 'view' => 'edit', 'model' => $id ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( 'field-groups', 'error', [
				'view'    => '' !== $duplicate_source ? 'duplicate' : 'edit',
				'model'   => '' !== $duplicate_source ? $duplicate_source : $original,
				'message' => $e->getMessage(),
			] );
		}
	}

	public static function delete_field_group(): void {
		self::guard( 'cb_core_content_models_delete_field_group' );
		self::require_enabled();
		$id = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';
		$existing = Repository::field_group( $id );
		if ( null === $existing ) {
			self::redirect( 'field-groups', 'not-found' );
		}
		Repository::delete_field_group( $id );
		self::audit_definition( 'content_models_field_group_deleted', $id, $existing, null );
		self::redirect( 'field-groups', 'deleted' );
	}

	public static function save_field(): void {
		self::guard( 'cb_core_content_models_save_field' );
		self::require_enabled();

		$group_id = isset( $_POST['group_id'] ) ? sanitize_key( wp_unslash( $_POST['group_id'] ) ) : '';
		$original_field_id = isset( $_POST['original_field_id'] ) ? sanitize_key( wp_unslash( $_POST['original_field_id'] ) ) : '';
		$duplicate_source_field = isset( $_POST['duplicate_source_field'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_source_field'] ) ) : '';

		try {
			self::persist_field_from_post( $group_id, $original_field_id, $duplicate_source_field );
			self::redirect( 'field-groups', 'field-saved', [ 'view' => 'edit', 'model' => $group_id ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( 'field-groups', 'error', [
				'view'    => '' !== $duplicate_source_field ? 'duplicate-field' : 'field',
				'model'   => $group_id,
				'field'   => '' !== $duplicate_source_field ? $duplicate_source_field : $original_field_id,
				'message' => $e->getMessage(),
			] );
		}
	}

	public static function quick_save_field(): void {
		self::guard_ajax( 'cb_core_content_models_quick_save_field' );
		if ( ! State::is_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'Enable Content Models before changing field definitions.', 'core-blueprint' ) ], 409 );
		}

		$group_id = isset( $_POST['group_id'] ) ? sanitize_key( wp_unslash( $_POST['group_id'] ) ) : '';
		$original_field_id = isset( $_POST['original_field_id'] ) ? sanitize_key( wp_unslash( $_POST['original_field_id'] ) ) : '';

		try {
			$saved = self::persist_field_from_post( $group_id, $original_field_id );
			$type = (string) ( $saved['type'] ?? 'text' );
			wp_send_json_success( [
				'message'      => __( 'Field saved.', 'core-blueprint' ),
				'field_id'     => (string) ( $saved['id'] ?? $original_field_id ),
				'label'        => (string) ( $saved['label'] ?? '' ),
				'type'         => $type,
				'type_label'   => (string) ( FieldTypes::labels()[ $type ] ?? $type ),
				'required'     => ! empty( $saved['required'] ),
				'show_in_rest' => ! empty( $saved['show_in_rest'] ),
			] );
		} catch ( \InvalidArgumentException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	public static function delete_field(): void {
		self::guard( 'cb_core_content_models_delete_field' );
		self::require_enabled();
		$group_id = isset( $_POST['group_id'] ) ? sanitize_key( wp_unslash( $_POST['group_id'] ) ) : '';
		$field_id = isset( $_POST['field_id'] ) ? sanitize_key( wp_unslash( $_POST['field_id'] ) ) : '';
		$existing = Repository::field( $group_id, $field_id );
		if ( null === $existing ) {
			self::redirect( 'field-groups', 'not-found', [ 'view' => 'edit', 'model' => $group_id ] );
		}
		Repository::delete_field( $group_id, $field_id );
		self::audit_field( 'content_models_field_deleted', $group_id, $field_id, $existing, null );
		self::redirect( 'field-groups', 'field-deleted', [ 'view' => 'edit', 'model' => $group_id ] );
	}

	public static function sort_fields(): void {
		self::guard( 'cb_core_content_models_sort_fields' );
		self::require_enabled();

		$group_id = isset( $_POST['group_id'] ) ? sanitize_key( wp_unslash( $_POST['group_id'] ) ) : '';
		$group = Repository::field_group( $group_id );
		if ( null === $group ) {
			self::redirect( 'field-groups', 'not-found' );
		}

		$raw_order = isset( $_POST['field_order'] ) ? (string) wp_unslash( $_POST['field_order'] ) : '';
		$ordered_ids = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw_order ) ) ) ) );
		$before_fields = is_array( $group['fields'] ?? null ) ? $group['fields'] : [];

		try {
			$after_fields = Repository::reorder_fields( $group_id, $ordered_ids );
			self::audit_field_order( $group_id, $group, array_keys( $before_fields ), array_keys( $after_fields ) );
			self::redirect( 'field-groups', 'field-order-saved', [ 'view' => 'edit', 'model' => $group_id ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( 'field-groups', 'error', [
				'view'    => 'edit',
				'model'   => $group_id,
				'message' => $e->getMessage(),
			] );
		}
	}

	/** @return array<string,mixed> */
	private static function persist_field_from_post( string $group_id, string $original_field_id, string $duplicate_source_field = '' ): array {
		$group = Repository::field_group( $group_id );
		if ( null === $group ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Field group not found.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$input = [
			'name'          => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'label'         => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '',
			'type'          => isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : 'text',
			'instructions'  => isset( $_POST['instructions'] ) ? wp_unslash( $_POST['instructions'] ) : '',
			'required'      => ! empty( $_POST['required'] ),
			'show_in_rest'  => ! empty( $_POST['show_in_rest'] ),
			'placeholder'   => isset( $_POST['placeholder'] ) ? wp_unslash( $_POST['placeholder'] ) : '',
			'default_value' => isset( $_POST['default_value'] ) ? wp_unslash( $_POST['default_value'] ) : '',
			'choices_text'  => isset( $_POST['choices_text'] ) ? wp_unslash( $_POST['choices_text'] ) : '',
			'min'           => isset( $_POST['min'] ) ? wp_unslash( $_POST['min'] ) : '',
			'max'           => isset( $_POST['max'] ) ? wp_unslash( $_POST['max'] ) : '',
			'step'          => isset( $_POST['step'] ) ? wp_unslash( $_POST['step'] ) : '',
			'rows'                => isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : 5,
			'relation_multiple'   => ! empty( $_POST['relation_multiple'] ),
			'relation_post_types' => self::array_input( 'relation_post_types' ),
			'relation_roles'      => self::array_input( 'relation_roles' ),
			'relation_taxonomies' => self::array_input( 'relation_taxonomies' ),
			'sub_fields'           => self::array_input( 'sub_fields' ),
			'repeater_min'         => isset( $_POST['repeater_min'] ) ? wp_unslash( $_POST['repeater_min'] ) : 0,
			'repeater_max'         => isset( $_POST['repeater_max'] ) ? wp_unslash( $_POST['repeater_max'] ) : 0,
			'conditional_logic'    => self::array_input( 'conditional_logic' ),
		];

		$existing = '' !== $original_field_id ? Repository::field( $group_id, $original_field_id ) : null;
		$duplicate_from = '' !== $duplicate_source_field ? Repository::field( $group_id, $duplicate_source_field ) : null;
		if ( '' !== $original_field_id && null === $existing ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Field not found.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( '' !== $duplicate_source_field && null === $duplicate_from ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The source field for this duplicate no longer exists.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( null !== $existing && ! isset( $_POST['sub_fields'] ) ) {
			$input['sub_fields'] = is_array( $existing['sub_fields'] ?? null ) ? $existing['sub_fields'] : [];
			$input['repeater_min'] = (int) ( $existing['repeater_min'] ?? 0 );
			$input['repeater_max'] = (int) ( $existing['repeater_max'] ?? 0 );
		}
		if ( null !== $existing && ! isset( $_POST['conditional_logic'] ) ) {
			$input['conditional_logic'] = is_array( $existing['conditional_logic'] ?? null ) ? $existing['conditional_logic'] : [];
		}
		if ( null !== $duplicate_from && is_array( $input['sub_fields'] ?? null ) ) {
			foreach ( $input['sub_fields'] as &$sub_field ) {
				if ( is_array( $sub_field ) ) {
					$sub_field['id'] = '';
				}
			}
			unset( $sub_field );
		}
		if ( null !== $existing && isset( $_POST['name'] ) && sanitize_key( (string) wp_unslash( $_POST['name'] ) ) !== (string) ( $existing['name'] ?? '' ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Field names are immutable after creation. Use a schema migration when a stored meta key must change.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( null !== $existing && isset( $_POST['type'] ) && sanitize_key( (string) wp_unslash( $_POST['type'] ) ) !== (string) ( $existing['type'] ?? '' ) && empty( $_POST['confirm_type_change'] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Changing a field type requires explicit confirmation because existing WordPress metadata may be interpreted differently.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$saved = Repository::save_field( $group_id, $input, '' !== $original_field_id ? $original_field_id : null );
		$field_id = (string) $saved['id'];
		self::audit_field(
			null !== $existing ? 'content_models_field_updated' : ( null !== $duplicate_from ? 'content_models_field_duplicated' : 'content_models_field_created' ),
			$group_id,
			$field_id,
			$existing ?? $duplicate_from,
			$saved
		);
		return $saved;
	}


	public static function search_relation_objects(): void {
		if ( ! State::is_enabled() || ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Relation search is unavailable.', 'core-blueprint' ) ], 403 );
		}

		$context = isset( $_POST['context'] ) && is_array( $_POST['context'] ) ? wp_unslash( $_POST['context'] ) : [];
		$group_id = sanitize_key( (string) ( $context['group_id'] ?? '' ) );
		$field_id = sanitize_key( (string) ( $context['field_id'] ?? '' ) );
		$group = Repository::field_group( $group_id );
		$parent_field = Repository::field( $group_id, $field_id );
		$field = $parent_field;
		$sub_field_id = sanitize_key( (string) ( $context['sub_field_id'] ?? '' ) );
		if ( null !== $parent_field && '' !== $sub_field_id && FieldTypes::is_structured_type( (string) ( $parent_field['type'] ?? '' ) ) ) {
			$field = null;
			foreach ( FieldTypes::sub_fields( $parent_field ) as $candidate ) {
				if ( $sub_field_id === sanitize_key( (string) ( $candidate['id'] ?? '' ) ) ) {
					$field = $candidate;
					break;
				}
			}
		}
		if ( null === $group || null === $field || ! FieldTypes::is_relation_type( (string) ( $field['type'] ?? '' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Relation field not found.', 'core-blueprint' ) ], 404 );
		}

		if ( false === check_ajax_referer( 'cb_cm_relation_search_' . $group_id . '_' . $field_id, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Relation search request expired. Refresh the editor and try again.', 'core-blueprint' ) ], 403 );
		}
		if ( ! self::can_search_relation_context( $group, $context ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to search relation targets in this context.', 'core-blueprint' ) ], 403 );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( [ 'items' => [] ] );
		}

		wp_send_json_success( [ 'items' => self::relation_search_items( $field, $search ) ] );
	}

	/** @param array<string,mixed> $group @param array<string,mixed> $context */
	private static function can_search_relation_context( array $group, array $context ): bool {
		$kind = sanitize_key( (string) ( $context['kind'] ?? '' ) );
		if ( 'post' === $kind ) {
			$post_id = absint( $context['post_id'] ?? 0 );
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );
				return $post instanceof \WP_Post && LocationMatcher::matches_post_type( $group, $post->post_type ) && current_user_can( 'edit_post', $post_id );
			}
			$post_type = sanitize_key( (string) ( $context['post_type'] ?? '' ) );
			$object = get_post_type_object( $post_type );
			return '' !== $post_type
				&& null !== $object
				&& LocationMatcher::matches_post_type( $group, $post_type )
				&& current_user_can( (string) $object->cap->edit_posts );
		}
		if ( 'option_page' === $kind ) {
			$page_slug = sanitize_key( (string) ( $context['page_slug'] ?? '' ) );
			$page = Repository::option_page( $page_slug );
			return null !== $page
				&& LocationMatcher::matches_option_page( $group, $page_slug )
				&& current_user_can( (string) ( $page['capability'] ?? 'manage_options' ) );
		}
		if ( 'term' === $kind ) {
			$taxonomy = sanitize_key( (string) ( $context['taxonomy'] ?? '' ) );
			$taxonomy_object = get_taxonomy( $taxonomy );
			if ( '' === $taxonomy || ! $taxonomy_object || ! LocationMatcher::matches_taxonomy( $group, $taxonomy ) ) {
				return false;
			}
			$term_id = absint( $context['term_id'] ?? 0 );
			return $term_id > 0
				? term_exists( $term_id, $taxonomy ) && current_user_can( (string) $taxonomy_object->cap->edit_terms )
				: current_user_can( (string) $taxonomy_object->cap->edit_terms );
		}
		if ( 'user' === $kind ) {
			$user_id = absint( $context['user_id'] ?? 0 );
			$user = get_userdata( $user_id );
			return $user instanceof \WP_User
				&& LocationMatcher::matches_user( $group, $user )
				&& current_user_can( 'edit_user', $user_id );
		}
		return false;
	}

	/** @return array<int,array{id:int,label:string,meta:string}> */
	private static function relation_search_items( array $field, string $search ): array {
		$type = (string) ( $field['type'] ?? '' );
		$items = [];
		if ( 'post_relation' === $type ) {
			$post_types = array_values( array_filter(
				array_map( 'sanitize_key', (array) ( $field['relation_post_types'] ?? [] ) ),
				static fn( string $post_type ): bool => post_type_exists( $post_type )
			) );
			if ( empty( $post_types ) ) {
				return [];
			}
			$query = new \WP_Query( [
				'post_type'              => $post_types,
				'post_status'            => 'any',
				's'                      => $search,
				'posts_per_page'         => 20,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			] );
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof \WP_Post || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) || ( ! current_user_can( 'read_post', $post->ID ) && ! current_user_can( 'edit_post', $post->ID ) ) ) {
					continue;
				}
				$post_type = get_post_type_object( $post->post_type );
				$title = get_the_title( $post );
				$items[] = [
					'id'    => $post->ID,
					/* translators: %d: WordPress object ID. */
				'label' => '' !== trim( (string) $title ) ? (string) $title : sprintf( __( '(no title) #%d', 'core-blueprint' ), $post->ID ),
					'meta'  => sprintf( '%s · #%d', $post_type ? (string) $post_type->labels->singular_name : $post->post_type, $post->ID ),
				];
			}
			return $items;
		}

		if ( 'user_relation' === $type ) {
			$args = [
				'number'         => 20,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'search'         => '*' . $search . '*',
				'search_columns' => [ 'user_login', 'user_nicename', 'display_name' ],
			];
			$roles = array_values( array_filter( array_map( 'sanitize_key', (array) ( $field['relation_roles'] ?? [] ) ) ) );
			if ( ! empty( $roles ) ) {
				$args['role__in'] = $roles;
			}
			$query = new \WP_User_Query( $args );
			foreach ( (array) $query->get_results() as $user ) {
				if ( $user instanceof \WP_User ) {
					$items[] = [ 'id' => $user->ID, 'label' => (string) $user->display_name, 'meta' => __( 'User', 'core-blueprint' ) . ' · #' . $user->ID ];
				}
			}
			return $items;
		}

		if ( 'term_relation' === $type ) {
			$taxonomies = array_values( array_filter(
				array_map( 'sanitize_key', (array) ( $field['relation_taxonomies'] ?? [] ) ),
				static fn( string $taxonomy ): bool => taxonomy_exists( $taxonomy )
			) );
			if ( empty( $taxonomies ) ) {
				return [];
			}
			$terms = get_terms( [
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'search'     => $search,
				'number'     => 20,
				'orderby'    => 'name',
				'order'      => 'ASC',
			] );
			if ( is_wp_error( $terms ) ) {
				return [];
			}
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$taxonomy = get_taxonomy( $term->taxonomy );
				$items[] = [ 'id' => $term->term_id, 'label' => (string) $term->name, 'meta' => sprintf( '%s · #%d', $taxonomy ? (string) $taxonomy->labels->singular_name : $term->taxonomy, $term->term_id ) ];
			}
		}
		return $items;
	}

	private static function guard_ajax( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to manage content models.', 'core-blueprint' ) ], 403 );
		}
		check_ajax_referer( $nonce_action );
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage content models.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
		check_admin_referer( $nonce_action );
	}


	private static function array_input( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) ) {
			return [];
		}
		$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public handlers verify a nonce before calling this helper; each caller applies domain-specific normalization.
		return is_array( $value ) ? $value : [];
	}

	private static function require_enabled(): void {
		if ( State::is_enabled() ) {
			return;
		}
		self::redirect( 'settings', 'disabled' );
	}

	/** @param array<string,string> $extra */
	private static function redirect( string $tab, string $status, array $extra = [] ): never {
		$args = array_merge( [
			'page'   => Page::SLUG,
			'tab'    => $tab,
			'status' => $status,
		], $extra );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function audit_definition( string $event, string $key, ?array $before, ?array $after ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}

		$context = [ 'key' => $key ];
		if ( null !== $after ) {
			$context['label'] = (string) ( $after['singular_label'] ?? $after['title'] ?? $after['plural_label'] ?? '' );
		}
		if ( null !== $before && null !== $after ) {
			$context['changed'] = self::changed_keys( $before, $after );
		}

		AuditLog::log( $event, 'notice', $context );
	}


	private static function audit_field( string $event, string $group_id, string $field_id, ?array $before, ?array $after ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}
		$context = [
			'group_id' => $group_id,
			'field_id' => $field_id,
			'meta_key' => (string) ( ( $after ?? $before )['name'] ?? '' ),
			'label'    => (string) ( ( $after ?? $before )['label'] ?? '' ),
		];
		if ( null !== $before && null !== $after ) {
			$context['changed'] = self::changed_keys( $before, $after );
		}
		AuditLog::log( $event, 'notice', $context );
	}

	private static function audit_field_order( string $group_id, array $group, array $before_order, array $after_order ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}
		AuditLog::log( 'content_models_field_order_updated', 'notice', [
			'group_id'     => $group_id,
			'title'        => (string) ( $group['title'] ?? $group_id ),
			'before_order' => array_values( array_map( 'strval', $before_order ) ),
			'after_order'  => array_values( array_map( 'strval', $after_order ) ),
		] );
	}

	/** @return string[] */
	private static function changed_keys( array $before, array $after ): array {
		$keys = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		return array_values( array_filter( $keys, static function ( string $key ) use ( $before, $after ): bool {
			return ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null );
		} ) );
	}
}
