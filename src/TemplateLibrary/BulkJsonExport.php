<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Global Library's bounded, read-only multi-JSON download.
 *
 * Not a T4 resource ZIP; no media, importer, new writer or provider authority.
 * Every record goes through the same CAS and actor-authorized export-json path
 * as the individual card. One refused/changed record prevents ZIP delivery.
 */
final class BulkJsonExport {
	public const MAX_ITEMS = 12;
	private const MAX_DATA_BYTES = 12000000;

	/** @param list<array{source:string,templateId:string,revision:int}> $items
	 *  @return array<string,mixed>
	 */
	public static function prepare( array $items ): array {
		if ( count( $items ) < 1 || count( $items ) > self::MAX_ITEMS
			|| ! class_exists( \ZipArchive::class ) ) {
			return [ 'status' => 'unavailable' ];
		}
		$unique = [];
		$exports = [];
		$manifest = [];
		$bytes = 0;
		foreach ( $items as $position => $item ) {
			if ( ! is_array( $item ) || array_keys( $item ) !== [ 'source', 'templateId', 'revision' ]
				|| ! is_string( $item['source'] ) || ! TemplateQuery::valid_id( $item['source'] )
				|| ! is_string( $item['templateId'] ) || ! TemplateQuery::valid_id( $item['templateId'] )
				|| ! is_int( $item['revision'] ) || $item['revision'] < 1 ) {
				return [ 'status' => 'invalid' ];
			}
			$key = $item['source'] . ':' . $item['templateId'];
			if ( isset( $unique[ $key ] ) ) {
				return [ 'status' => 'invalid' ];
			}
			$unique[ $key ] = true;
			$result = TemplateSourceRegistry::perform_action(
				$item['source'], $item['templateId'], 'export-json', $item['revision'], [], null
			);
			if ( 'ready' !== ( $result['status'] ?? null ) ) {
				return [ 'status' => in_array( $result['status'] ?? '', [ 'conflict', 'refused', 'missing', 'unsupported' ], true )
					? $result['status'] : 'unknown' ];
			}
			$json = $result['data'] ?? null;
			if ( ! is_string( $json ) || '' === $json || strlen( $json ) > 2500000
				|| null === json_decode( $json ) || JSON_ERROR_NONE !== json_last_error() ) {
				return [ 'status' => 'unknown' ];
			}
			$bytes += strlen( $json );
			if ( $bytes > self::MAX_DATA_BYTES ) {
				return [ 'status' => 'unavailable' ];
			}
			// Never derive ZIP entry paths from browser/provider identifiers.
			$name = sprintf( 'templates/%02d.json', $position + 1 );
			$exports[ $name ] = $json;
			$manifest[] = [
				'file' => $name, 'source' => $item['source'],
				'templateId' => $item['templateId'], 'revision' => $item['revision'],
				'sha256' => hash( 'sha256', $json ),
			];
		}

		$path = tempnam( get_temp_dir(), 'cbtl-' );
		if ( ! is_string( $path ) ) {
			return [ 'status' => 'unavailable' ];
		}
		$zip = new \ZipArchive();
		try {
			@chmod( $path, 0600 );
			if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) {
				return [ 'status' => 'unavailable' ];
			}
			$meta = wp_json_encode( [
				'format' => 'core-blueprint-bulk-json-v1',
				'note' => 'Separate JSON exports; not a T4 resource-package import ZIP.',
				'items' => $manifest,
			] );
			if ( ! is_string( $meta ) || ! $zip->addFromString( 'manifest.json', $meta ) ) {
				$zip->close();
				return [ 'status' => 'unavailable' ];
			}
			foreach ( $exports as $name => $json ) {
				if ( ! $zip->addFromString( $name, $json ) ) {
					$zip->close();
					return [ 'status' => 'unavailable' ];
				}
			}
			if ( ! $zip->close() ) {
				return [ 'status' => 'unavailable' ];
			}
			$archive = file_get_contents( $path );
			if ( ! is_string( $archive ) || strlen( $archive ) > 16000000 ) {
				return [ 'status' => 'unavailable' ];
			}
			return [
				'status' => 'ready', 'format' => 'core-blueprint-bulk-json-v1',
				'count' => count( $items ), 'data' => base64_encode( $archive ),
			];
		} catch ( \Throwable ) {
			$zip->close();
			return [ 'status' => 'unavailable' ];
		} finally {
			@unlink( $path );
		}
	}

	private function __construct() {}
}
