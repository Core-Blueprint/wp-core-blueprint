<?php
declare(strict_types=1);
/**
 * Validate existing values for one explicitly selected registered meta key.
 * This is compatibility validation, never metadata-key discovery.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels\Importers\NativeWordPress;

use CB\Core\ContentModels\FieldTypes;

defined( 'ABSPATH' ) || exit;

final class ValueCompatibility {
	/** @param array<string,mixed> $meta @return array{compatible:bool,count:int,digest:string,reason:string} */
	public static function inspect( array $meta, string $field_type ): array {
		$rows = self::rows( $meta );
		foreach ( $rows as $row ) {
			$value = self::decode_raw_value( $row['raw'] );
			if ( ! self::value_is_compatible( $value, $field_type ) ) {
				return [
					'compatible' => false,
					'count'      => count( $rows ),
					'digest'     => self::digest( $rows ),
					'reason'     => __( 'One or more existing values are incompatible with the selected Content Models field type.', 'core-blueprint' ),
				];
			}
		}
		$default = $meta['default'] ?? '';
		if ( ! self::value_is_compatible( $default, $field_type ) ) {
			return [
				'compatible' => false,
				'count'      => count( $rows ),
				'digest'     => self::digest( $rows ),
				'reason'     => __( 'The registered metadata default is incompatible with the selected Content Models field type.', 'core-blueprint' ),
			];
		}
		return [ 'compatible' => true, 'count' => count( $rows ), 'digest' => self::digest( $rows ), 'reason' => '' ];
	}

	/**
	 * Decode one raw WordPress meta value without instantiating serialized classes.
	 *
	 * @return mixed
	 */
	private static function decode_raw_value( string $raw ) {
		if ( ! is_serialized( $raw ) ) {
			return $raw;
		}

		return unserialize( trim( $raw ), [ 'allowed_classes' => false ] );
	}

	/** @param mixed $value */
	private static function value_is_compatible( $value, string $field_type ): bool {
		if ( 'true_false' === $field_type ) {
			return null === $value
				|| true === $value
				|| false === $value
				|| 1 === $value
				|| 0 === $value
				|| '1' === $value
				|| '0' === $value
				|| '' === $value;
		}
		if ( 'number' === $field_type ) {
			return null === $value || '' === $value || is_numeric( $value );
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			return false;
		}
		$raw = null === $value ? '' : (string) $value;
		$field = [
			'type' => $field_type,
			'min' => '', 'max' => '', 'choices' => [], 'relation_multiple' => false,
		];
		$normalized = FieldTypes::sanitize_value( $field, $raw );
		return is_string( $normalized ) && $normalized === $raw;
	}

	/** @param array<string,mixed> $meta @return array<int,array{id:int,raw:string}> */
	private static function rows( array $meta ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}
		$object_type = (string) ( $meta['object_type'] ?? '' );
		$subtype = (string) ( $meta['object_subtype'] ?? '' );
		$key = (string) ( $meta['key'] ?? '' );
		if ( '' === $key ) {
			return [];
		}
		$sql = '';
		if ( 'post' === $object_type && '' !== $subtype ) {
			$sql = $wpdb->prepare(
				"SELECT pm.post_id AS object_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s ORDER BY pm.post_id ASC, pm.meta_id ASC",
				$key,
				$subtype
			);
		} elseif ( 'term' === $object_type && '' !== $subtype ) {
			$sql = $wpdb->prepare(
				"SELECT tm.term_id AS object_id, tm.meta_value FROM {$wpdb->termmeta} tm WHERE tm.meta_key = %s AND EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = tm.term_id AND tt.taxonomy = %s) ORDER BY tm.term_id ASC, tm.meta_id ASC",
				$key,
				$subtype
			);
		} elseif ( 'user' === $object_type ) {
			$sql = $wpdb->prepare(
				"SELECT um.user_id AS object_id, um.meta_value FROM {$wpdb->usermeta} um WHERE um.meta_key = %s ORDER BY um.user_id ASC, um.umeta_id ASC",
				$key
			);
		}
		if ( '' === $sql ) {
			return [];
		}
		$found = $wpdb->get_results( $sql, ARRAY_A );
		$result = [];
		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$result[] = [ 'id' => (int) ( $row['object_id'] ?? 0 ), 'raw' => (string) ( $row['meta_value'] ?? '' ) ];
		}
		return $result;
	}

	/** @param array<int,array{id:int,raw:string}> $rows */
	private static function digest( array $rows ): string {
		return hash( 'sha256', (string) wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
