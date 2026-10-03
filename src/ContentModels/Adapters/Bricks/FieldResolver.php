<?php
declare(strict_types=1);
/**
 * Resolves Core Blueprint Content Models fields for Bricks post context.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Adapters\Bricks;

use CoreBlueprint\Core\ContentModels\Api;
use CoreBlueprint\Core\ContentModels\FieldTypes;
use CoreBlueprint\Core\ContentModels\LocationMatcher;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class FieldResolver {
	public const TAG_PREFIX = 'cb_cm:';

	/** @return array<string,array<string,mixed>> field name => definition */
	public static function post_fields( string $post_type = '' ): array {
		$fields = [];
		$schema = Api::schema();
		foreach ( (array) ( $schema['field_groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) || empty( $group['post_types'] ) ) {
				continue;
			}
			if ( '' !== $post_type && ! LocationMatcher::matches_post_type( $group, $post_type ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( ! is_array( $field ) || '' === (string) ( $field['name'] ?? '' ) ) {
					continue;
				}
				$fields[ (string) $field['name'] ] = $field;
			}
		}
		return $fields;
	}

	/** @return array{field:array<string,mixed>,value:mixed}|null */
	public static function resolve( string $tag, ?WP_Post $post = null ): ?array {
		$name = self::tag_name( $tag );
		if ( '' === $name ) {
			return null;
		}
		$post ??= get_post();
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$field = self::post_fields( $post->post_type )[ $name ] ?? null;
		if ( ! is_array( $field ) ) {
			return null;
		}
		$value = metadata_exists( 'post', $post->ID, $name ) ? get_post_meta( $post->ID, $name, true ) : ( $field['default_value'] ?? FieldTypes::empty_input_value( $field ) );
		return [ 'field' => $field, 'value' => $value ];
	}

	public static function tag_name( string $tag ): string {
		$tag = trim( $tag );
		if ( str_starts_with( $tag, '{' ) && str_ends_with( $tag, '}' ) ) {
			$tag = substr( $tag, 1, -1 );
		}
		if ( ! str_starts_with( $tag, self::TAG_PREFIX ) ) {
			return '';
		}
		return sanitize_key( substr( $tag, strlen( self::TAG_PREFIX ) ) );
	}
}
