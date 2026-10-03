<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets\ImportExport;

use CoreBlueprint\Core\Snippets\Repository;
use CoreBlueprint\Core\Snippets\Schema;

defined( 'ABSPATH' ) || exit;

final class Importer {
	/**
	 * Imports Core Blueprint JSON and a conservative subset of Fluent Snippets exports.
	 * Every imported snippet is disabled by default. Unknown Fluent condition schemas are
	 * intentionally not executed; code is preserved and must be reviewed before enabling.
	 */
	public static function import_json( string $json, bool $overwrite = false ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return self::result_with_error( __( 'The uploaded file is not valid JSON.', 'core-blueprint' ) );
		}

		$file_type = (string) ( $data['file_type'] ?? '' );
		if ( Exporter::FILE_TYPE === $file_type ) {
			return self::import_core( $data, $overwrite );
		}
		if ( 'fluent_code_snippets' === $file_type ) {
			return self::import_fluent( $data );
		}

		return self::result_with_error( __( 'Unsupported snippets export format.', 'core-blueprint' ) );
	}

	private static function import_core( array $data, bool $overwrite ): array {
		$result = self::empty_result( 'core-blueprint' );
		$items  = isset( $data['snippets'] ) && is_array( $data['snippets'] ) ? $data['snippets'] : [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_array( $item['meta'] ?? null ) || ! is_string( $item['code'] ?? null ) ) {
				$result['skipped']++;
				continue;
			}

			$code = base64_decode( $item['code'], true );
			if ( false === $code ) {
				$result['skipped']++;
				$result['errors'][] = __( 'A snippet contained invalid Base64 code.', 'core-blueprint' );
				continue;
			}
			$expected = (string) ( $item['code_hash'] ?? '' );
			if ( '' !== $expected && ! hash_equals( $expected, hash( 'sha256', $code ) ) ) {
				$result['skipped']++;
				$result['errors'][] = __( 'A snippet failed its integrity hash check.', 'core-blueprint' );
				continue;
			}

			$meta = $item['meta'];
			$meta['enabled'] = false;
			if ( ! $overwrite ) {
				unset( $meta['id'], $meta['created_at'], $meta['updated_at'] );
				$meta['shortcode'] = '';
			}
			$meta['source'] = 'core-blueprint-import';

			$saved = Repository::save( $meta, $code );
			if ( is_wp_error( $saved ) ) {
				$result['skipped']++;
				$result['errors'][] = $saved->get_error_message();
				continue;
			}
			$result['created']++;
			$result['titles'][] = (string) ( $saved['title'] ?? '' );
		}

		return $result;
	}

	private static function import_fluent( array $data ): array {
		$result = self::empty_result( 'fluent-snippets' );
		$items  = isset( $data['snippets'] ) && is_array( $data['snippets'] ) ? $data['snippets'] : [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_array( $item['info'] ?? null ) || ! is_string( $item['code'] ?? null ) ) {
				$result['skipped']++;
				continue;
			}
			$code = base64_decode( $item['code'], true );
			if ( false === $code ) {
				$result['skipped']++;
				continue;
			}
			$expected = (string) ( $item['code_hash'] ?? '' );
			if ( '' !== $expected && ! hash_equals( strtolower( $expected ), md5( $code ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5 -- compatibility check for Fluent export contract.
				$result['skipped']++;
				$result['errors'][] = __( 'A Fluent Snippets item failed its integrity hash check.', 'core-blueprint' );
				continue;
			}

			$mapped = self::map_fluent_meta( $item['info'], $code );
			if ( is_wp_error( $mapped ) ) {
				$result['skipped']++;
				$result['errors'][] = $mapped->get_error_message();
				continue;
			}

			$saved = Repository::save( $mapped, $code );
			if ( is_wp_error( $saved ) ) {
				$result['skipped']++;
				$result['errors'][] = $saved->get_error_message();
				continue;
			}
			$result['created']++;
			$result['titles'][] = (string) ( $saved['title'] ?? '' );
		}

		return $result;
	}

	/** @return array|\WP_Error */
	private static function map_fluent_meta( array $info, string $code ) {
		$source_type = (string) ( $info['type'] ?? 'PHP' );
		$type_map = [
			'PHP'         => 'php',
			'php'         => 'php',
			'js'          => 'js',
			'css'         => 'css',
			'php_content' => 'html',
		];
		$type = $type_map[ $source_type ] ?? '';
		if ( '' === $type ) {
			return new \WP_Error( 'cb_snippets_fluent_type', sprintf(
				/* translators: %s: source snippet type */
				__( 'Unsupported Fluent Snippets type: %s', 'core-blueprint' ),
				$source_type
			) );
		}

		if ( 'php_content' === $source_type && preg_match( '/<\?(?:php|=)?/i', $code ) ) {
			return new \WP_Error(
				'cb_snippets_fluent_mixed',
				__( 'A mixed PHP/content Fluent snippet needs manual migration and was not imported automatically.', 'core-blueprint' )
			);
		}

		$run_at   = sanitize_key( (string) ( $info['run_at'] ?? '' ) );
		$location = self::map_fluent_location( $type, $run_at );
		$title    = sanitize_text_field( (string) ( $info['name'] ?? __( 'Imported snippet', 'core-blueprint' ) ) );
		$description = sanitize_textarea_field( (string) ( $info['description'] ?? '' ) );
		if ( ! empty( $info['condition'] ) ) {
			$description .= ( '' !== $description ? "\n\n" : '' ) . __( 'Imported from Fluent Snippets. Review the original Fluent conditions before enabling; unknown condition rules are not migrated automatically.', 'core-blueprint' );
		}

		return [
			'title'       => $title,
			'description' => $description,
			'type'        => $type,
			'location'    => $location,
			'priority'    => max( 1, min( 999, (int) ( $info['priority'] ?? 10 ) ) ),
			'enabled'     => false,
			'shortcode'   => '',
			'tags'        => $info['tags'] ?? [],
			'conditions'  => [ 'relation' => 'and', 'rules' => [] ],
			'source'      => 'fluent-snippets-import',
		];
	}

	private static function map_fluent_location( string $type, string $run_at ): string {
		if ( 'php' === $type ) {
			return 'backend' === $run_at ? 'admin_init' : 'plugins_loaded';
		}
		if ( 'js' === $type ) {
			return in_array( $run_at, [ 'wp_head', 'wp_footer', 'admin_head', 'admin_footer' ], true ) ? $run_at : 'wp_footer';
		}
		if ( 'css' === $type ) {
			if ( 'admin_head' === $run_at ) {
				return 'admin';
			}
			if ( 'everywhere' === $run_at || 'everywehere' === $run_at ) {
				return 'both';
			}
			return 'frontend';
		}
		return 'shortcode';
	}

	private static function empty_result( string $source ): array {
		return [
			'source'  => $source,
			'created' => 0,
			'skipped' => 0,
			'errors'  => [],
			'titles'  => [],
		];
	}

	private static function result_with_error( string $message ): array {
		$result = self::empty_result( 'unknown' );
		$result['errors'][] = $message;
		return $result;
	}
}
