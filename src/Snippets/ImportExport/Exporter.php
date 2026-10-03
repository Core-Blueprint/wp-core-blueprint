<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets\ImportExport;

use CoreBlueprint\Core\Snippets\Repository;

defined( 'ABSPATH' ) || exit;

final class Exporter {
	public const FILE_TYPE = 'core_blueprint_snippets';
	public const SCHEMA_VERSION = '1.0';

	/**
	 * @param string[] $ids Empty exports all snippets.
	 */
	public static function build( array $ids = [] ): array {
		$all = Repository::all();
		$selected = [];
		$id_filter = array_values( array_filter( array_map( 'sanitize_key', $ids ) ) );

		foreach ( $all as $id => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			if ( ! empty( $id_filter ) && ! in_array( (string) $id, $id_filter, true ) ) {
				continue;
			}

			$code = Repository::code( (string) $id );
			$selected[] = [
				'meta'      => $meta,
				'code'      => base64_encode( $code ),
				'code_hash' => hash( 'sha256', $code ),
			];
		}

		return [
			'file_type'      => self::FILE_TYPE,
			'schema_version' => self::SCHEMA_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'snippets_count' => count( $selected ),
			'snippets'       => $selected,
		];
	}
}
