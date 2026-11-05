<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\DataExchange;

use JsonException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Read-only, bounded verifier for the candidate offline template resource package. */
final class ResourcePackageVerifier {
	private const MAX_PACKAGE = 24 * 1024 * 1024;
	private const MAX_MANIFEST = 64 * 1024;
	private const MAX_RESOURCE = 5 * 1024 * 1024;
	private const MAX_RESOURCES = 16;
	private const MAX_RESOURCE_TOTAL = 20 * 1024 * 1024;
	private const EOCD = "PK\x05\x06";
	private const CENTRAL = "PK\x01\x02";
	private const LOCAL = "PK\x03\x04";

	/**
	 * Checks one regular local file; performs no extraction, staging, writes or imports.
	 * Results are evidence about bytes read NOW, not a reusable trust or authorization token.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function verify_file( string $path ): array|WP_Error {
		if ( '' === $path || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return self::refuse();
		}
		$size = filesize( $path );
		if ( ! is_int( $size ) || $size < 22 || $size > self::MAX_PACKAGE ) {
			return self::refuse();
		}
		$raw = file_get_contents( $path, false, null, 0, self::MAX_PACKAGE + 1 );
		if ( ! is_string( $raw ) || strlen( $raw ) !== $size ) {
			return self::refuse();
		}
		$files = self::read_archive( $raw );
		if ( null === $files ) {
			return self::refuse();
		}
		try {
			$manifest = json_decode( $files['manifest.json'], true, 32, JSON_THROW_ON_ERROR );
			$document = json_decode( $files['data-exchange.json'], true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			return self::refuse();
		}
		// Both JSON parts use the canonical Data Exchange encoder shape. Reject
		// duplicate object keys and ambiguous non-canonical numeric/escape forms.
		$flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( json_encode( $manifest, $flags ) !== $files['manifest.json']
			|| json_encode( $document, $flags ) !== $files['data-exchange.json'] ) {
			return self::refuse();
		}
		if ( ! self::object_keys( $manifest, [ 'package_format', 'package_version', 'extension_id', 'entity', 'record_count', 'document', 'resources' ] )
			|| 'core-blueprint-template-resources' !== $manifest['package_format']
			|| 1 !== $manifest['package_version'] || 1 !== $manifest['record_count']
			|| ! self::object_keys( $document, [ 'format', 'format_version', 'extension_id', 'entity', 'schema_version', 'exported_at', 'records' ] )
			|| Foundation::FORMAT_ID !== $document['format'] || Foundation::FORMAT_VERSION !== $document['format_version']
			|| ! is_int( $document['schema_version'] ) || $document['schema_version'] < 1
			|| ! is_string( $document['exported_at'] )
			|| ! is_string( $manifest['extension_id'] ) || ! is_string( $manifest['entity'] )
			|| ! is_string( $document['extension_id'] ) || ! is_string( $document['entity'] )
			|| $manifest['extension_id'] !== $document['extension_id'] || $manifest['entity'] !== $document['entity']
			|| ! self::identifier( $manifest['extension_id'] ) || ! self::identifier( $manifest['entity'] )
			|| ! is_array( $document['records'] ) || ! array_is_list( $document['records'] ) || 1 !== count( $document['records'] )
			|| ! is_array( $document['records'][0] ) || ! self::object_keys( $manifest['document'], [ 'path', 'size', 'sha256' ] )
			|| 'data-exchange.json' !== $manifest['document']['path']
			|| strlen( $files['data-exchange.json'] ) !== $manifest['document']['size']
			|| hash( 'sha256', $files['data-exchange.json'] ) !== $manifest['document']['sha256']
			|| ! is_array( $manifest['resources'] ) || ! array_is_list( $manifest['resources'] )
			|| ! is_array( $document['records'][0]['resources'] ?? null ) || ! array_is_list( $document['records'][0]['resources'] )
		) {
			return self::refuse();
		}
		if ( count( $manifest['resources'] ) > self::MAX_RESOURCES || count( $manifest['resources'] ) !== count( $document['records'][0]['resources'] ) ) {
			return self::refuse();
		}
		$expected = [ 'manifest.json' => true, 'data-exchange.json' => true ];
		$projection = [];
		$last_key = '';
		foreach ( $manifest['resources'] as $index => $resource ) {
			if ( ! self::object_keys( $resource, [ 'key', 'path', 'media_type', 'size', 'sha256' ] )
				|| ! is_string( $resource['key'] ) || ! self::identifier( $resource['key'] )
				|| $resource['key'] <= $last_key
				|| ! self::digest( $resource['sha256'] )
				|| $resource['path'] !== 'resources/sha256/' . $resource['sha256']
				|| ! is_int( $resource['size'] ) || $resource['size'] < 1 || $resource['size'] > self::MAX_RESOURCE
				|| ! in_array( $resource['media_type'], [ 'image/png', 'image/jpeg', 'image/webp' ], true )
				|| ! isset( $files[ $resource['path'] ] )
				|| strlen( $files[ $resource['path'] ] ) !== $resource['size']
				|| hash( 'sha256', $files[ $resource['path'] ] ) !== $resource['sha256']
				|| ! self::valid_image( $files[ $resource['path'] ], $resource['media_type'] )
			) {
				return self::refuse();
			}
			$record_resource = $document['records'][0]['resources'][ $index ];
			if ( ! self::object_keys( $record_resource, [ 'key', 'media_type', 'size', 'sha256' ] )
				|| $record_resource['key'] !== $resource['key'] || $record_resource['media_type'] !== $resource['media_type']
				|| $record_resource['size'] !== $resource['size'] || $record_resource['sha256'] !== $resource['sha256'] ) {
				return self::refuse();
			}
			$expected[ $resource['path'] ] = true;
			$projection[] = [ 'key' => $resource['key'], 'sha256' => $resource['sha256'], 'size' => $resource['size'], 'media_type' => $resource['media_type'] ];
			$last_key = $resource['key'];
		}
		if ( count( $expected ) !== count( $files ) || array_diff_key( $expected, $files ) ) {
			return self::refuse();
		}
		// Do not treat this result as authority at apply time; verify immutable bytes again.
		return [
			'package_sha256' => hash( 'sha256', $raw ),
			'document_sha256' => $manifest['document']['sha256'],
			'document_json' => $files['data-exchange.json'],
			'extension_id' => $manifest['extension_id'],
			'entity' => $manifest['entity'],
			'resources' => $projection,
		];
	}

	/** @return array<string,string>|null */
	private static function read_archive( string $raw ): ?array {
		$length = strlen( $raw );
		$end = strrpos( $raw, self::EOCD, max( 0, $length - 65557 ) );
		if ( false === $end || $end + 22 > $length ) {
			return null;
		}
		$footer = unpack( 'vdisk/vcdisk/ventries_disk/ventries/Vbytes/Voffset/vcomment', substr( $raw, $end + 4, 18 ) );
		if ( 0 !== $footer['disk'] || 0 !== $footer['cdisk'] || $footer['entries_disk'] !== $footer['entries']
			|| $footer['entries'] < 2 || $footer['entries'] > self::MAX_RESOURCES + 2
			|| $footer['offset'] + $footer['bytes'] !== $end || $end + 22 + $footer['comment'] !== $length ) {
			return null;
		}
		$cursor = $footer['offset'];
		$files = [];
		$regions = [];
		$total_resource = 0;
		for ( $i = 0; $i < $footer['entries']; ++$i ) {
			if ( $cursor + 46 > $end || substr( $raw, $cursor, 4 ) !== self::CENTRAL ) {
				return null;
			}
			$c = unpack( 'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr( $raw, $cursor + 4, 42 ) );
			$cursor += 46;
			if ( ! in_array( $c['flags'], [ 0, 2048 ], true ) || ! in_array( $c['method'], [ 0, 8 ], true )
				|| $c['need'] > 20 || 0 !== $c['extra'] || 0 !== $c['comment'] || 0 !== $c['disk']
				|| $c['compressed'] === 0xffffffff || $c['uncompressed'] === 0xffffffff || $c['offset'] === 0xffffffff
				|| $c['name_length'] < 1 || $cursor + $c['name_length'] > $end ) {
				return null;
			}
			$name = substr( $raw, $cursor, $c['name_length'] );
			$cursor += $c['name_length'];
			if ( ! in_array( $name, [ 'manifest.json', 'data-exchange.json' ], true )
				&& 1 !== preg_match( '#^resources/sha256/[a-f0-9]{64}$#D', $name ) ) {
				return null;
			}
			if ( isset( $files[ $name ] ) || ( $c['external'] & 16 ) !== 0 ) {
				return null;
			}
			$unix_mode = $c['made'] >> 8 === 3 ? ( $c['external'] >> 16 ) & 0xffff : 0;
			if ( 0 !== ( $unix_mode & 0170000 ) && ( $unix_mode & 0170000 ) !== 0100000 ) {
				return null;
			}
			$limit = 'manifest.json' === $name ? self::MAX_MANIFEST : ( 'data-exchange.json' === $name ? Foundation::MAX_INPUT_BYTES : self::MAX_RESOURCE );
			if ( $c['uncompressed'] > $limit || $c['compressed'] > self::MAX_PACKAGE ) {
				return null;
			}
			if ( str_starts_with( $name, 'resources/' ) ) {
				$total_resource += $c['uncompressed'];
				if ( $total_resource > self::MAX_RESOURCE_TOTAL ) {
					return null;
				}
			}
			$start = $c['offset'];
			if ( $start + 30 > $footer['offset'] || substr( $raw, $start, 4 ) !== self::LOCAL ) {
				return null;
			}
			$l = unpack( 'vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra', substr( $raw, $start + 4, 26 ) );
			if ( $l['flags'] !== $c['flags'] || $l['method'] !== $c['method'] || $l['crc'] !== $c['crc']
				|| $l['compressed'] !== $c['compressed'] || $l['uncompressed'] !== $c['uncompressed']
				|| $l['name_length'] !== $c['name_length'] || 0 !== $l['extra']
				|| substr( $raw, $start + 30, $l['name_length'] ) !== $name ) {
				return null;
			}
			$data_start = $start + 30 + $l['name_length'];
			$data_end = $data_start + $c['compressed'];
			if ( $data_end > $footer['offset'] ) {
				return null;
			}
			$compressed = substr( $raw, $data_start, $c['compressed'] );
			$bytes = 0 === $c['method'] ? $compressed : @gzinflate( $compressed, $limit + 1 );
			if ( ! is_string( $bytes ) || strlen( $bytes ) !== $c['uncompressed'] || crc32( $bytes ) !== $c['crc'] ) {
				return null;
			}
			$files[ $name ] = $bytes;
			$regions[] = [ $start, $data_end ];
		}
		if ( $cursor !== $end ) {
			return null;
		}
		sort( $regions );
		$next = 0;
		foreach ( $regions as [ $start, $finish ] ) {
			if ( $start !== $next ) {
				return null; // No overlaps, prefixes, gaps or hidden local records.
			}
			$next = $finish;
		}
		return $next === $footer['offset'] && isset( $files['manifest.json'], $files['data-exchange.json'] ) ? $files : null;
	}

	private static function valid_image( string $bytes, string $mime ): bool {
		$expected = [ 'image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG, 'image/webp' => IMAGETYPE_WEBP ];
		if ( ! class_exists( \finfo::class ) || ! function_exists( 'getimagesizefromstring' ) ) {
			return false;
		}
		$image = @getimagesizefromstring( $bytes );
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		return is_array( $image ) && ( $image[2] ?? null ) === $expected[ $mime ]
			&& $finfo->buffer( $bytes ) === $mime;
	}

	private static function object_keys( mixed $value, array $keys ): bool {
		return is_array( $value ) && ! array_is_list( $value )
			&& 0 === count( array_diff( $keys, array_keys( $value ) ) )
			&& 0 === count( array_diff( array_keys( $value ), $keys ) );
	}

	private static function identifier( string $value ): bool {
		return strlen( $value ) <= 96 && 1 === preg_match( '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $value );
	}

	private static function digest( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	private static function refuse(): WP_Error {
		return new WP_Error( 'cb_core_resource_package_invalid', 'Template resource package failed verification.' );
	}
}
