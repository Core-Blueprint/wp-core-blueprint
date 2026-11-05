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

	/**
	 * Checks one regular local file; performs no extraction, staging, writes or imports.
	 * Results are evidence about bytes read NOW, not a reusable trust or authorization token.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function verify_file( string $path ): array|WP_Error {
		if ( ! class_exists( \ZipArchive::class, false ) ) {
			return new WP_Error( 'cb_core_resource_package_unavailable', 'ZIP package verification requires PHP ZipArchive.' );
		}
		if ( '' === $path || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return self::refuse();
		}
		$size = filesize( $path );
		if ( ! is_int( $size ) || $size < 22 || $size > self::MAX_PACKAGE ) {
			return self::refuse();
		}
		$before = hash_file( 'sha256', $path );
		if ( ! is_string( $before ) ) {
			return self::refuse();
		}
		$files = self::read_archive( $path );
		$after = hash_file( 'sha256', $path );
		if ( null === $files || ! is_string( $after ) || ! hash_equals( $before, $after ) ) {
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
			'package_sha256' => $before,
			'document_sha256' => $manifest['document']['sha256'],
			'document_json' => $files['data-exchange.json'],
			'extension_id' => $manifest['extension_id'],
			'entity' => $manifest['entity'],
			'resources' => $projection,
		];
	}

	/** @return array<string,string>|null */
	private static function read_archive( string $path ): ?array {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CHECKCONS ) ) {
			return null;
		}

		try {
			if ( $zip->numFiles < 2 || $zip->numFiles > self::MAX_RESOURCES + 2
				|| ! in_array( $zip->getArchiveComment(), [ '', false ], true ) ) {
				return null;
			}
			$files = [];
			$total_resource = 0;
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$stat = $zip->statIndex( $index );
				if ( false === $stat || ! is_string( $stat['name'] ?? null )
					|| ! is_int( $stat['size'] ?? null ) || ! is_int( $stat['comp_size'] ?? null )
					|| ! is_int( $stat['comp_method'] ?? null ) || ! is_int( $stat['crc'] ?? null ) ) {
					return null;
				}
				$name = $stat['name'];
				if ( ! in_array( $name, [ 'manifest.json', 'data-exchange.json' ], true )
					&& 1 !== preg_match( '#^resources/sha256/[a-f0-9]{64}$#D', $name ) ) {
					return null;
				}
				if ( array_key_exists( $name, $files )
					|| ! in_array( $stat['comp_method'], [ \ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE ], true )
					|| $stat['comp_size'] > self::MAX_PACKAGE
					|| ! in_array( $zip->getCommentIndex( $index ), [ '', false ], true ) ) {
					return null;
				}
				$opsys = 0;
				$attributes = 0;
				if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attributes ) ) {
					return null;
				}
				$mode = \ZipArchive::OPSYS_UNIX === $opsys ? ( ( $attributes >> 16 ) & 0xffff ) : 0;
				if ( 0 !== ( $mode & 0170000 ) && 0100000 !== ( $mode & 0170000 ) ) {
					return null; // Reject symlinks and non-regular archive members.
				}
				$limit = 'manifest.json' === $name
					? self::MAX_MANIFEST
					: ( 'data-exchange.json' === $name ? Foundation::MAX_INPUT_BYTES : self::MAX_RESOURCE );
				if ( $stat['size'] > $limit ) {
					return null;
				}
				if ( str_starts_with( $name, 'resources/' ) ) {
					$total_resource += $stat['size'];
					if ( $total_resource > self::MAX_RESOURCE_TOTAL ) {
						return null;
					}
				}
				// Bound decompressed bytes even if ZIP metadata understates actual size.
				$bytes = $zip->getFromIndex( $index, $limit + 1 );
				if ( ! is_string( $bytes ) || strlen( $bytes ) !== $stat['size']
					|| crc32( $bytes ) !== $stat['crc'] ) {
					return null;
				}
				$files[ $name ] = $bytes;
			}

			return isset( $files['manifest.json'], $files['data-exchange.json'] ) ? $files : null;
		} finally {
			$zip->close();
		}
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
