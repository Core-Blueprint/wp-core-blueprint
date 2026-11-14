<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\DataExchange;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates one offline, resource-bearing package from the canonical export Engine.
 * The caller owns download authorization, delivery and cleanup of the returned file.
 * The owning entity supplies already authorized, local resource bytes; Base never
 * resolves URLs, WordPress attachments or domain document references.
 */
final class ResourcePackageExport {
	private const MAX_PACKAGE = 24 * 1024 * 1024;
	private const MAX_RESOURCE = 5 * 1024 * 1024;
	private const MAX_RESOURCE_TOTAL = 20 * 1024 * 1024;
	private const MAX_RESOURCES = 16;

	/**
	 * @param callable(array{key:string,media_type:string,size:int,sha256:string}):string|WP_Error $read_resource
	 * @return array{path:string,package_sha256:string,document_sha256:string,resources:array}|WP_Error
	 */
	public static function create(
		string $extension_id,
		string $entity_id,
		array $context,
		callable $read_resource,
		string $private_root
	): array|WP_Error {
		if ( ! class_exists( \ZipArchive::class, false ) ) {
			return new WP_Error( 'cb_core_resource_package_unavailable', 'ZIP package export requires PHP ZipArchive.' );
		}
		$root = ResourcePackageStage::private_root_path( $private_root );
		$actor = get_current_user_id();
		if ( null === $root || $actor < 1 ) {
			return self::refuse();
		}
		$json = Engine::export_json( $extension_id, $entity_id, $context );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$document = self::document( $json, $extension_id, $entity_id );
		if ( null === $document ) {
			return self::refuse();
		}
		$resources = $document['records'][0]['resources'];
		if ( [] === $resources || count( $resources ) > self::MAX_RESOURCES ) {
			return self::refuse(); // Use asset-free JSON when no resources are needed.
		}
		$manifest_resources = [];
		$bytes_by_path = [];
		$last_key = '';
		$total = 0;
		foreach ( $resources as $resource ) {
			if ( ! self::resource( $resource ) || $resource['key'] <= $last_key ) {
				return self::refuse();
			}
			$last_key = $resource['key'];
			$total += $resource['size'];
			$path = 'resources/sha256/' . $resource['sha256'];
			if ( $total > self::MAX_RESOURCE_TOTAL || isset( $bytes_by_path[ $path ] ) ) {
				return self::refuse();
			}
			try {
				$bytes = $read_resource( $resource );
			} catch ( \Throwable ) {
				return self::refuse();
			}
			if ( is_wp_error( $bytes ) ) {
				return self::refuse();
			}
			if ( ! is_string( $bytes ) || strlen( $bytes ) !== $resource['size']
				|| ! hash_equals( $resource['sha256'], hash( 'sha256', $bytes ) ) ) {
				return self::refuse();
			}
			$manifest_resources[] = [
				'key' => $resource['key'], 'path' => $path,
				'media_type' => $resource['media_type'], 'size' => $resource['size'],
				'sha256' => $resource['sha256'],
			];
			$bytes_by_path[ $path ] = $bytes;
		}
		// A provider may revoke access or mutate the record while assets are read.
		// A second canonical export must authorize the same actor and same records.
		if ( get_current_user_id() !== $actor ) {
			return self::refuse();
		}
		$rechecked = Engine::export_json( $extension_id, $entity_id, $context );
		if ( is_wp_error( $rechecked ) ) {
			return self::refuse();
		}
		$again = self::document( $rechecked, $extension_id, $entity_id );
		if ( null === $again || $again['records'] !== $document['records']
			|| $again['schema_version'] !== $document['schema_version'] ) {
			return self::refuse();
		}
		// The second, freshly authorized envelope owns the exported_at timestamp.
		$json = $rechecked;
		$manifest = [
			'package_format' => 'core-blueprint-template-resources',
			'package_version' => 1,
			'extension_id' => $extension_id,
			'entity' => $entity_id,
			'record_count' => 1,
			'document' => [
				'path' => 'data-exchange.json',
				'size' => strlen( $json ),
				'sha256' => hash( 'sha256', $json ),
			],
			'resources' => $manifest_resources,
		];
		try {
			$manifest_json = json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$id = bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable ) {
			return self::refuse();
		}
		if ( strlen( $manifest_json ) > 64 * 1024 ) {
			return self::refuse();
		}
		$path = $root . '/' . $id . '.zip';
		$old_umask = umask( 0077 );
		try {
			$handle = @fopen( $path, 'xb' );
		} finally {
			umask( $old_umask );
		}
		if ( false === $handle ) {
			return self::refuse();
		}
		fclose( $handle );
		$complete = false;
		$opened = false;
		$zip = new \ZipArchive();
		try {
			if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return self::refuse();
			}
			$opened = true;
			$entries = [ 'manifest.json' => $manifest_json, 'data-exchange.json' => $json ] + $bytes_by_path;
			foreach ( $entries as $name => $contents ) {
				if ( ! $zip->addFromString( $name, $contents )
					|| ! $zip->setCompressionName( $name, \ZipArchive::CM_DEFLATE ) ) {
					return self::refuse();
				}
			}
			$closed = $zip->close();
			$opened = false;
			if ( ! $closed || ! chmod( $path, 0600 ) ) {
				return self::refuse();
			}
			$size = filesize( $path );
			if ( ! is_int( $size ) || $size > self::MAX_PACKAGE ) {
				return self::refuse();
			}
			$verified = ResourcePackageVerifier::verify_file( $path );
			if ( is_wp_error( $verified ) || $verified['document_sha256'] !== hash( 'sha256', $json )
				|| $verified['extension_id'] !== $extension_id || $verified['entity'] !== $entity_id ) {
				return self::refuse();
			}
			$complete = true;
			return [
				'path' => $path,
				'package_sha256' => $verified['package_sha256'],
				'document_sha256' => $verified['document_sha256'],
				'resources' => $verified['resources'],
			];
		} catch ( \Throwable ) {
			return self::refuse();
		} finally {
			if ( $opened ) {
				$zip->close();
			}
			if ( ! $complete ) {
				@unlink( $path );
			}
		}
	}

	private static function document( string $json, string $extension_id, string $entity_id ): ?array {
		if ( strlen( $json ) > Foundation::MAX_INPUT_BYTES ) {
			return null;
		}
		try {
			$value = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			return null;
		}
		$flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		return is_array( $value ) && ! array_is_list( $value )
			&& array_keys( $value ) === [ 'format', 'format_version', 'extension_id', 'entity', 'schema_version', 'exported_at', 'records' ]
			&& json_encode( $value, $flags ) === $json
			&& ( $value['format'] ?? null ) === Foundation::FORMAT_ID
			&& ( $value['format_version'] ?? null ) === Foundation::FORMAT_VERSION
			&& ( $value['extension_id'] ?? null ) === $extension_id
			&& ( $value['entity'] ?? null ) === $entity_id
			&& is_int( $value['schema_version'] ?? null ) && $value['schema_version'] >= 1
			&& is_array( $value['records'] ?? null ) && count( $value['records'] ) === 1
			&& array_is_list( $value['records'] ) && is_array( $value['records'][0] )
			&& is_array( $value['records'][0]['resources'] ?? null )
			&& array_is_list( $value['records'][0]['resources'] )
			? $value : null;
	}

	private static function resource( mixed $value ): bool {
		return is_array( $value ) && ! array_is_list( $value )
			&& array_keys( $value ) === [ 'key', 'sha256', 'size', 'media_type' ]
			&& is_string( $value['key'] ) && strlen( $value['key'] ) <= 96
			&& 1 === preg_match( '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $value['key'] )
			&& is_string( $value['sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value['sha256'] )
			&& is_int( $value['size'] ) && $value['size'] > 0 && $value['size'] <= self::MAX_RESOURCE
			&& in_array( $value['media_type'], [ 'image/png', 'image/jpeg', 'image/webp' ], true );
	}

	private static function refuse(): WP_Error {
		return new WP_Error( 'cb_core_resource_package_export_invalid', 'Template resource package export is unavailable or invalid.' );
	}
}
