<?php
declare(strict_types=1);
/**
 * JSON schema export/import for Content Models definitions.
 *
 * Customer content values are deliberately excluded. Import is merge-based;
 * definitions absent from the document are never deleted.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels;

defined( 'ABSPATH' ) || exit;

final class SchemaTransfer {
	/** @return array<string,mixed> */
	public static function export_document(): array {
		$data = Repository::all();
		return [
			'format'         => 'core-blueprint-content-models',
			'format_version' => 1,
			'schema_version' => Repository::SCHEMA_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'post_types'     => $data['post_types'],
			'taxonomies'     => $data['taxonomies'],
			'option_pages'    => $data['option_pages'],
			'field_groups'   => $data['field_groups'],
		];
	}

	/** @return array<string,mixed> */
	public static function decode( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || 'core-blueprint-content-models' !== (string) ( $data['format'] ?? '' ) ) {
			throw new \InvalidArgumentException( __( 'This is not a valid Core Blueprint Content Models JSON document.', 'core-blueprint' ) );
		}
		if ( 1 !== (int) ( $data['format_version'] ?? 0 ) ) {
			throw new \InvalidArgumentException( __( 'This Content Models JSON format version is not supported.', 'core-blueprint' ) );
		}
		return self::normalize_document( $data );
	}

	/** @return array<string,mixed> */
	public static function analyze( array $document ): array {
		$conflicts = [];
		$locked = [];
		$counts = [];
		$maps = [
			'post_types'   => 'post_type',
			'taxonomies'   => 'taxonomy',
			'option_pages'  => 'option_page',
			'field_groups' => 'field_group',
		];
		foreach ( $maps as $section => $getter ) {
			$counts[ $section ] = count( (array) ( $document[ $section ] ?? [] ) );
			foreach ( array_keys( (array) ( $document[ $section ] ?? [] ) ) as $key ) {
				$current = Repository::$getter( (string) $key );
				if ( null === $current ) {
					continue;
				}
				$entry = [ 'section' => $section, 'key' => (string) $key ];
				if ( ! empty( $current['_locked'] ) ) {
					$entry['owner'] = (string) ( $current['_owner'] ?? '' );
					$locked[] = $entry;
				} else {
					$conflicts[] = $entry;
				}
			}
		}
		return [ 'counts' => $counts, 'conflicts' => $conflicts, 'locked' => $locked ];
	}

	/** @return array<string,int> */
	public static function import( array $document, bool $overwrite ): array {
		$analysis = self::analyze( $document );
		if ( ! empty( $analysis['locked'] ) ) {
			throw new \InvalidArgumentException( __( 'The import contains definitions owned and locked by another plugin.', 'core-blueprint' ) );
		}
		if ( ! $overwrite && ! empty( $analysis['conflicts'] ) ) {
			throw new \InvalidArgumentException( __( 'The import contains existing definitions. Enable overwrite for matching user-managed definitions or resolve the conflicts first.', 'core-blueprint' ) );
		}
		return Repository::merge_imported_schema( $document, $overwrite );
	}

	/** @return array<string,mixed> */
	private static function normalize_document( array $data ): array {
		$normalized = [
			'schema_version' => Repository::SCHEMA_VERSION,
			'post_types'     => [],
			'taxonomies'     => [],
			'option_pages'    => [],
			'field_groups'   => [],
		];
		foreach ( (array) ( $data['post_types'] ?? [] ) as $definition ) {
			if ( ! is_array( $definition ) ) { continue; }
			$item = Repository::normalize_post_type( $definition );
			$normalized['post_types'][ (string) $item['key'] ] = $item;
		}
		foreach ( (array) ( $data['taxonomies'] ?? [] ) as $definition ) {
			if ( ! is_array( $definition ) ) { continue; }
			$item = Repository::normalize_taxonomy( $definition );
			$normalized['taxonomies'][ (string) $item['key'] ] = $item;
		}
		foreach ( (array) ( $data['option_pages'] ?? [] ) as $definition ) {
			if ( ! is_array( $definition ) ) { continue; }
			$item = Repository::normalize_option_page( $definition );
			$normalized['option_pages'][ (string) $item['slug'] ] = $item;
		}
		foreach ( (array) ( $data['field_groups'] ?? [] ) as $definition ) {
			if ( ! is_array( $definition ) ) { continue; }
			$source_fields = is_array( $definition['fields'] ?? null ) ? $definition['fields'] : [];
			$group = Repository::normalize_field_group( $definition );
			$fields = [];
			foreach ( $source_fields as $field ) {
				if ( ! is_array( $field ) ) { continue; }
				$field = self::prepare_field_input( $field );
				$item = Repository::normalize_field( $field );
				$fields[ (string) $item['id'] ] = $item;
			}
			$group['fields'] = $fields;
			$normalized['field_groups'][ (string) $group['id'] ] = $group;
		}
		return $normalized;
	}

	/** @return array<string,mixed> */
	private static function prepare_field_input( array $field ): array {
		if ( is_array( $field['choices'] ?? null ) ) {
			$field['choices_text'] = FieldTypes::choices_to_text( $field['choices'] );
		}
		if ( is_array( $field['sub_fields'] ?? null ) ) {
			$sub_fields = [];
			foreach ( $field['sub_fields'] as $sub_field ) {
				if ( ! is_array( $sub_field ) ) { continue; }
				if ( is_array( $sub_field['choices'] ?? null ) ) {
					$sub_field['choices_text'] = FieldTypes::choices_to_text( $sub_field['choices'] );
				}
				$sub_fields[] = $sub_field;
			}
			$field['sub_fields'] = $sub_fields;
		}
		return $field;
	}
}
