<?php
declare(strict_types=1);
/**
 * Bricks Dynamic Data adapter for Content Models.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Adapters\Bricks;

use CoreBlueprint\Core\ContentModels\FieldTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class DynamicData {
	/** @param array<int,array<string,mixed>> $tags @return array<int,array<string,mixed>> */
	public static function register_tags( array $tags ): array {
		$known = [];
		foreach ( $tags as $tag ) {
			if ( is_array( $tag ) ) {
				$known[] = (string) ( $tag['name'] ?? '' );
			}
		}
		foreach ( FieldResolver::post_fields() as $name => $field ) {
			$tag_name = '{' . FieldResolver::TAG_PREFIX . $name . '}';
			if ( in_array( $tag_name, $known, true ) ) {
				continue;
			}
			$tags[] = [
				'name'  => $tag_name,
				'label' => sprintf( '%s — %s', (string) ( $field['label'] ?? $name ), (string) ( FieldTypes::labels()[ (string) ( $field['type'] ?? '' ) ] ?? $field['type'] ?? '' ) ),
				'group' => __( 'Core Blueprint', 'core-blueprint' ),
			];
		}
		return $tags;
	}

	/** @return mixed */
	public static function render_tag( $tag, $post, string $context = 'text' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_string( $tag ) || ! str_contains( $tag, FieldResolver::TAG_PREFIX ) ) {
			return $tag;
		}
		$post = $post instanceof WP_Post ? $post : get_post( is_numeric( $post ) ? (int) $post : 0 );
		$resolved = FieldResolver::resolve( $tag, $post instanceof WP_Post ? $post : null );
		return null === $resolved ? $tag : self::typed_value( $resolved['field'], $resolved['value'], $context );
	}

	public static function render_content( string $content, $post, string $context = 'text' ): string {
		if ( ! str_contains( $content, '{' . FieldResolver::TAG_PREFIX ) ) {
			return $content;
		}
		$post = $post instanceof WP_Post ? $post : get_post( is_numeric( $post ) ? (int) $post : 0 );
		return (string) preg_replace_callback( '/\{cb_cm:([a-z0-9_\-]+)\}/', static function ( array $match ) use ( $post, $context ): string {
			$resolved = FieldResolver::resolve( $match[0], $post instanceof WP_Post ? $post : null );
			return null === $resolved ? $match[0] : self::text_value( $resolved['field'], $resolved['value'], $context );
		}, $content );
	}

	/** @return mixed */
	private static function typed_value( array $field, $value, string $context ) {
		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, [ 'group', 'repeater', 'gallery', 'checkbox' ], true ) || ( FieldTypes::is_relation_type( $type ) && ! empty( $field['relation_multiple'] ) ) ) {
			return $value;
		}
		if ( in_array( $type, [ 'image', 'file' ], true ) && 'text' === $context ) {
			return wp_get_attachment_url( absint( $value ) ) ?: '';
		}
		return $value;
	}

	private static function text_value( array $field, $value, string $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$type = (string) ( $field['type'] ?? '' );
		if ( 'true_false' === $type ) {
			return ! empty( $value ) ? '1' : '0';
		}
		if ( 'image' === $type || 'file' === $type ) {
			return (string) ( wp_get_attachment_url( absint( $value ) ) ?: '' );
		}
		if ( 'gallery' === $type ) {
			$urls = array_filter( array_map( static fn( $id ): string => (string) ( wp_get_attachment_url( absint( $id ) ) ?: '' ), is_array( $value ) ? $value : [] ) );
			return implode( ', ', $urls );
		}
		if ( FieldTypes::is_relation_type( $type ) ) {
			$labels = [];
			foreach ( FieldTypes::relation_raw_ids( $value ) as $id ) {
				if ( 'user_relation' === $type ) {
					$user = get_userdata( $id );
					$labels[] = $user ? (string) $user->display_name : '';
				} elseif ( 'term_relation' === $type ) {
					$term = get_term( $id );
					$labels[] = $term instanceof \WP_Term ? (string) $term->name : '';
				} else {
					$labels[] = (string) get_the_title( $id );
				}
			}
			return implode( ', ', array_filter( $labels, static fn( string $label ): bool => '' !== $label ) );
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
