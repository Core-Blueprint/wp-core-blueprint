<?php
declare(strict_types=1);
/**
 * Metadata-first privacy helpers for AI Governance.
 *
 * Raw prompts, responses, request bodies and arbitrary ability payload values
 * are deliberately not persisted by the automatic observer.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\AIGovernance;

use CoreBlueprint\Core\Governance\ContextSanitizer;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	private const RAW_CONTENT_KEY_PATTERN = '/(?:^|[_-])(?:prompt|response|request|payload|body|content|message|input|output)(?:$|[_-])/i';
	private const SAFE_METADATA_SUFFIX = '/(?:_type|_count|_bytes|_hash|_id|_code)$/i';

	/** @param array<string|int,mixed> $context @return array<string|int,mixed> */
	public static function sanitize_context( array $context ): array {
		$redacted = self::redact_raw_content( $context );
		return ContextSanitizer::sanitize( is_array( $redacted ) ? $redacted : [] );
	}

	/**
	 * Produce payload-shape evidence without retaining the payload itself.
	 *
	 * @return array<string,mixed>
	 */
	public static function summarize( mixed $value ): array {
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) {
			/** @var \WP_Error $value */
			$codes = array_values( array_filter( array_map( 'sanitize_key', $value->get_error_codes() ) ) );
			return [
				'type'        => 'wp_error',
				'error_codes' => array_slice( $codes, 0, 20 ),
			];
		}
		if ( null === $value ) {
			return [ 'type' => 'null' ];
		}
		if ( is_string( $value ) ) {
			return [ 'type' => 'string', 'bytes' => strlen( $value ) ];
		}
		if ( is_array( $value ) ) {
			return [ 'type' => 'array', 'item_count' => count( $value ) ];
		}
		if ( is_object( $value ) ) {
			return [ 'type' => 'object', 'class' => sanitize_text_field( get_class( $value ) ) ];
		}
		if ( is_int( $value ) ) {
			return [ 'type' => 'integer' ];
		}
		if ( is_float( $value ) ) {
			return [ 'type' => 'number' ];
		}
		if ( is_bool( $value ) ) {
			return [ 'type' => 'boolean' ];
		}
		if ( is_resource( $value ) ) {
			return [ 'type' => 'resource' ];
		}
		return [ 'type' => 'unknown' ];
	}

	private static function redact_raw_content( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = [];
		foreach ( $value as $key => $child ) {
			$key_string = is_int( $key ) ? '' : (string) $key;
			if (
				'' !== $key_string
				&& 1 === preg_match( self::RAW_CONTENT_KEY_PATTERN, $key_string )
				&& 1 !== preg_match( self::SAFE_METADATA_SUFFIX, $key_string )
			) {
				$out[ $key ] = '[redacted:metadata-only]';
				continue;
			}
			$out[ $key ] = self::redact_raw_content( $child );
		}
		return $out;
	}
}
