<?php
declare(strict_types=1);
/**
 * Public Content Models schema API for plugin-managed definitions and consumers.
 *
 * Managed definitions are runtime-only and never written into the user schema
 * option. Consumers register them before Content Models runtime registration.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels;

defined( 'ABSPATH' ) || exit;

final class Api {
	/** @var array<string,array<string,mixed>> */
	private static array $post_types = [];
	/** @var array<string,array<string,mixed>> */
	private static array $taxonomies = [];
	/** @var array<string,array<string,mixed>> */
	private static array $option_pages = [];
	/** @var array<string,array<string,mixed>> */
	private static array $field_groups = [];
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'cb_core_content_models_post_types', [ __CLASS__, 'merge_post_types' ] );
		add_filter( 'cb_core_content_models_taxonomies', [ __CLASS__, 'merge_taxonomies' ] );
		add_filter( 'cb_core_content_models_option_pages', [ __CLASS__, 'merge_option_pages' ] );
		add_filter( 'cb_core_content_models_field_groups', [ __CLASS__, 'merge_field_groups' ] );
	}

	/** @return array<string,mixed> */
	public static function register_post_type( array $definition, string $owner ): array {
		$definition = Repository::normalize_post_type( $definition );
		$definition = self::managed( $definition, $owner );
		self::$post_types[ (string) $definition['key'] ] = $definition;
		return $definition;
	}

	/** @return array<string,mixed> */
	public static function register_taxonomy( array $definition, string $owner ): array {
		$definition = Repository::normalize_taxonomy( $definition );
		$definition = self::managed( $definition, $owner );
		self::$taxonomies[ (string) $definition['key'] ] = $definition;
		return $definition;
	}

	/** @return array<string,mixed> */
	public static function register_option_page( array $definition, string $owner ): array {
		$definition = Repository::normalize_option_page( $definition );
		$definition = self::managed( $definition, $owner );
		self::$option_pages[ (string) $definition['slug'] ] = $definition;
		return $definition;
	}

	/** @return array<string,mixed> */
	public static function register_field_group( array $definition, string $owner ): array {
		$field_definitions = is_array( $definition['fields'] ?? null ) ? $definition['fields'] : [];
		$definition = Repository::normalize_field_group( $definition );
		$fields = [];
		foreach ( $field_definitions as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$normalized = Repository::normalize_field( self::prepare_field_input( $field ) );
			$fields[ (string) $normalized['id'] ] = $normalized;
		}
		$definition['fields'] = $fields;
		$definition = self::managed( $definition, $owner );
		self::$field_groups[ (string) $definition['id'] ] = $definition;
		return $definition;
	}

	/** @return array<string,mixed> */
	public static function schema(): array {
		return [
			'schema_version' => Repository::SCHEMA_VERSION,
			'post_types'     => Repository::post_types(),
			'taxonomies'     => Repository::taxonomies(),
			'option_pages'    => Repository::option_pages(),
			'field_groups'    => Repository::field_groups(),
		];
	}

	/** @return array<string,mixed>|null */
	public static function field_schema( string $group_id, string $field_id ): ?array {
		$field = Repository::field( sanitize_key( $group_id ), sanitize_key( $field_id ) );
		if ( null === $field ) {
			return null;
		}
		$field['rest_schema'] = FieldTypes::rest_schema( $field );
		$field['meta_type'] = FieldTypes::meta_type( $field );
		return $field;
	}

	/** @return mixed */
	public static function value( string $field_name, int $object_id, string $context = 'post', string $option_page = '' ) {
		$field_name = sanitize_key( $field_name );
		if ( '' === $field_name ) {
			return null;
		}
		return match ( sanitize_key( $context ) ) {
			'term'        => get_term_meta( $object_id, $field_name, true ),
			'user'        => get_user_meta( $object_id, $field_name, true ),
			'option_page' => get_option( Repository::option_value_key( sanitize_key( $option_page ), $field_name ), null ),
			default       => get_post_meta( $object_id, $field_name, true ),
		};
	}

	/** @param array<string,array<string,mixed>> $stored @return array<string,array<string,mixed>> */
	public static function merge_post_types( array $stored ): array { return array_replace( $stored, self::$post_types ); }
	/** @param array<string,array<string,mixed>> $stored @return array<string,array<string,mixed>> */
	public static function merge_taxonomies( array $stored ): array { return array_replace( $stored, self::$taxonomies ); }
	/** @param array<string,array<string,mixed>> $stored @return array<string,array<string,mixed>> */
	public static function merge_option_pages( array $stored ): array { return array_replace( $stored, self::$option_pages ); }
	/** @param array<string,array<string,mixed>> $stored @return array<string,array<string,mixed>> */
	public static function merge_field_groups( array $stored ): array { return array_replace( $stored, self::$field_groups ); }


	/** @return array<string,mixed> */
	private static function prepare_field_input( array $field ): array {
		if ( is_array( $field['choices'] ?? null ) && ! isset( $field['choices_text'] ) ) {
			$field['choices_text'] = FieldTypes::choices_to_text( $field['choices'] );
		}
		if ( is_array( $field['sub_fields'] ?? null ) ) {
			$sub_fields = [];
			foreach ( $field['sub_fields'] as $sub_field ) {
				if ( ! is_array( $sub_field ) ) {
					continue;
				}
				if ( is_array( $sub_field['choices'] ?? null ) && ! isset( $sub_field['choices_text'] ) ) {
					$sub_field['choices_text'] = FieldTypes::choices_to_text( $sub_field['choices'] );
				}
				$sub_fields[] = $sub_field;
			}
			$field['sub_fields'] = $sub_fields;
		}
		return $field;
	}

	/** @return array<string,mixed> */
	private static function managed( array $definition, string $owner ): array {
		$owner = sanitize_key( $owner );
		if ( '' === $owner ) {
			throw new \InvalidArgumentException( __( 'Plugin-managed Content Models require a non-empty owner identifier.', 'core-blueprint' ) );
		}
		$definition['_managed'] = true;
		$definition['_locked'] = true;
		$definition['_owner'] = $owner;
		return $definition;
	}
}
