<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design;

use CoreBlueprint\Core\Design\Integrity\ApprovedManifestSource;

defined( 'ABSPATH' ) || exit;

/**
 * One installed, server-declared native Designer consumer per Host context.
 *
 * A plugin registers an entry through the 'cb_core_designer_consumer_profiles'
 * filter. This is NOT a browser registry, asset URL resolver, capability grant
 * or persistence adapter. Installed WordPress PHP is trusted to declare intent,
 * but its exact packaged Designer JS/CSS must still pass artifact verification.
 */
final class ConsumerAdmission {
	/** @return array{profileId:string,packageId:string,installedRoot:string,declare:\Closure,register:\Closure,artifact:array<string,mixed>} */
	public static function resolve( string $profile_id ): array {
		if ( ! preg_match( '~^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$~D', $profile_id )
			|| str_starts_with( $profile_id, 'base/' ) || ! function_exists( 'apply_filters' ) ) {
			throw new \DomainException( 'HOST_CONTEXT_REFUSED' );
		}
		$entries = apply_filters( 'cb_core_designer_consumer_profiles', [] );
		if ( ! is_array( $entries ) || ! array_is_list( $entries ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
		$matches = array_values( array_filter( $entries, static fn ( mixed $entry ): bool =>
			is_array( $entry ) && ( $entry['profileId'] ?? null ) === $profile_id
		) );
		if ( 1 !== count( $matches ) ) {
			throw new \DomainException( count( $matches ) > 1 ? 'REGISTRY_COLLISION' : 'HOST_CONTEXT_REFUSED' );
		}
		$entry = $matches[0];
		$keys = array_keys( $entry );
		sort( $keys, SORT_STRING );
		if ( $keys !== [ 'declare', 'installedRoot', 'packageId', 'profileId', 'register' ]
			|| ! is_string( $entry['packageId'] )
			|| ! preg_match( '~^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$~D', $entry['packageId'] )
			|| ! is_string( $entry['installedRoot'] )
			|| ! defined( 'WP_PLUGIN_DIR' ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}

		// Native consumers are installed normal plugins in v1. Symlinks,
		// arbitrary paths and a caller-selected Base root are not admitted.
		$plugins_root = realpath( WP_PLUGIN_DIR );
		$root = realpath( $entry['installedRoot'] );
		if ( false === $plugins_root || false === $root || is_link( $entry['installedRoot'] )
			|| dirname( $root ) !== $plugins_root || $root === realpath( CB_CORE_DIR ) ) {
			throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
		}

		$callbacks = [];
		foreach ( [ 'declare', 'register' ] as $name ) {
			if ( ! is_callable( $entry[$name] ) ) {
				throw new \DomainException( 'REGISTRY_INVALID' );
			}
			try {
				$callback = \Closure::fromCallable( $entry[$name] );
				$file = ( new \ReflectionFunction( $callback ) )->getFileName();
				$source = is_string( $file ) ? realpath( $file ) : false;
			} catch ( \Throwable $error ) {
				throw new \DomainException( 'REGISTRY_INVALID' );
			}
			if ( false === $source || ! str_starts_with( $source, $root . DIRECTORY_SEPARATOR ) ) {
				throw new \DomainException( 'REGISTRY_INVALID' );
			}
			$callbacks[$name] = $callback;
		}
		$artifact = ApprovedManifestSource::consumer( $root, $entry['packageId'] );
		return [
			'profileId' => $profile_id,
			'packageId' => $entry['packageId'],
			'installedRoot' => $root,
			'declare' => $callbacks['declare'],
			'register' => $callbacks['register'],
			'artifact' => $artifact,
		];
	}

	private function __construct() {}
}
