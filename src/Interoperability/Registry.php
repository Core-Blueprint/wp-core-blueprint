<?php
declare(strict_types=1);
/**
 * Generic interoperability contract and implementation registry.
 *
 * Domain extensions own contract semantics. Base only owns the neutral
 * registration, discovery and resolution boundary. Third-party implementations
 * use the same public lifecycle and admission rules as first-party extensions.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Interoperability;

use CoreBlueprint\Core\ExtensionRegistry;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Registry {

	private const BASE_OWNER      = 'core-blueprint';
	private const ID_PATTERN      = '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D';
	private const VERSION_PATTERN = '/^[1-9][0-9]*$/D';

	/** @var array<string,array{owner:string,id:string,version:string,label:string,description:string,interface:string}> */
	private static array $contracts = [];

	/** @var array<string,array{provider:string,id:string,label:string,description:string,contract_owner:string,contract:string,contract_version:string,supports:list<string>}> */
	private static array $implementations = [];

	/** @var array<string,callable> */
	private static array $factories = [];

	private static bool $collected                  = false;
	private static bool $collecting_contracts       = false;
	private static bool $collecting_implementations = false;
	private static bool $frozen                     = false;

	/**
	 * Whether Base's canonical extension-admission lifecycle has completed.
	 *
	 * Interoperability discovery must never pull extension registration forward
	 * during plugin loading or an earlier init callback.
	 */
	public static function is_ready(): bool {
		return self::$collected || ( did_action( 'init' ) > 0 && ! doing_action( 'init' ) );
	}

	/**
	 * Collect Base-owned contracts, extension contracts, then implementations,
	 * exactly once per request.
	 *
	 * @return bool True when collection is complete, false when called too early.
	 */
	public static function collect(): bool {
		if ( self::$collected ) {
			return true;
		}
		if ( ! self::is_ready() ) {
			return false;
		}

		// Mark collected before dispatch so recursive discovery never dispatches
		// either public registration lifecycle twice.
		self::$collected = true;
		ExtensionRegistry::collect();

		try {
			foreach ( BaseContractCatalog::definitions() as $definition ) {
				self::register_base_contract_definition( $definition );
			}

			self::$collecting_contracts = true;
			do_action( 'cb_core_register_interoperability_contracts' );
			self::$collecting_contracts = false;

			self::$collecting_implementations = true;
			do_action( 'cb_core_register_interoperability_implementations' );
		} finally {
			self::$collecting_contracts       = false;
			self::$collecting_implementations = false;
			self::$frozen                     = true;
		}

		return true;
	}

	/**
	 * Register one extension-owned, versioned interoperability contract.
	 *
	 * @param array<string,mixed> $definition
	 */
	public static function register_contract( array $definition ): bool {
		if (
			self::$frozen
			|| ! self::$collecting_contracts
			|| ! doing_action( 'cb_core_register_interoperability_contracts' )
		) {
			self::diagnostic( 'Contract registration refused outside the controlled interoperability lifecycle.' );
			return false;
		}

		return self::register_contract_definition( $definition, false );
	}

	/**
	 * Register one implementation for a previously collected contract.
	 *
	 * The runtime factory is deliberately stored outside the public descriptor so
	 * discovery never leaks callbacks, service objects or implementation state.
	 *
	 * @param array<string,mixed> $definition
	 */
	public static function register_implementation( array $definition ): bool {
		if (
			self::$frozen
			|| ! self::$collecting_implementations
			|| ! doing_action( 'cb_core_register_interoperability_implementations' )
		) {
			self::diagnostic( 'Implementation registration refused outside the controlled interoperability lifecycle.' );
			return false;
		}

		$normalized = self::normalize_implementation( $definition );
		if ( null === $normalized ) {
			return false;
		}

		$factory = $normalized['factory'];
		unset( $normalized['factory'] );

		$key = self::implementation_key(
			$normalized['contract_owner'],
			$normalized['contract'],
			$normalized['contract_version'],
			$normalized['provider'],
			$normalized['id']
		);
		if ( isset( self::$implementations[ $key ] ) ) {
			self::diagnostic( sprintf( 'Duplicate interoperability implementation refused: %s.', $key ) );
			return false;
		}

		self::$implementations[ $key ] = $normalized;
		self::$factories[ $key ]       = $factory;
		return true;
	}

	/** @return array<string,array{owner:string,id:string,version:string,label:string,description:string,interface:string}> */
	public static function contracts(): array {
		self::collect();
		return self::$contracts;
	}

	/** @return array<string,array{provider:string,id:string,label:string,description:string,contract_owner:string,contract:string,contract_version:string,supports:list<string>}> */
	public static function implementations(): array {
		self::collect();
		return self::$implementations;
	}

	/** @return array{owner:string,id:string,version:string,label:string,description:string,interface:string}|null */
	public static function contract( string $owner, string $id, string $version ): ?array {
		self::collect();
		$key = self::contract_key_if_valid( $owner, $id, $version );
		return null !== $key ? ( self::$contracts[ $key ] ?? null ) : null;
	}

	/**
	 * Discover every implementation matching an exact contract version and all
	 * requested support tokens.
	 *
	 * @param list<string> $supports
	 * @return array<string,array{provider:string,id:string,label:string,description:string,contract_owner:string,contract:string,contract_version:string,supports:list<string>}>
	 */
	public static function discover( string $owner, string $contract, string $version, array $supports = [] ): array {
		self::collect();
		$contract_key = self::contract_key_if_valid( $owner, $contract, $version );
		$required     = self::normalize_supports( $supports );
		if ( null === $contract_key || null === $required || ! isset( self::$contracts[ $contract_key ] ) ) {
			return [];
		}

		$matches = [];
		foreach ( self::$implementations as $key => $implementation ) {
			if (
				$implementation['contract_owner'] !== $owner
				|| $implementation['contract'] !== $contract
				|| $implementation['contract_version'] !== $version
				|| [] !== array_diff( $required, $implementation['supports'] )
			) {
				continue;
			}
			$matches[ $key ] = $implementation;
		}

		ksort( $matches );
		return $matches;
	}

	/** @return array{provider:string,id:string,label:string,description:string,contract_owner:string,contract:string,contract_version:string,supports:list<string>}|null */
	public static function implementation(
		string $owner,
		string $contract,
		string $version,
		string $provider,
		string $id
	): ?array {
		self::collect();
		$key = self::implementation_key_if_valid( $owner, $contract, $version, $provider, $id );
		return null !== $key ? ( self::$implementations[ $key ] ?? null ) : null;
	}

	/**
	 * Resolve an implementation through its domain-owned PHP interface.
	 *
	 * Resolution is intentionally explicit and separate from discovery. Public
	 * descriptors remain serializable and cannot expose factories or objects.
	 *
	 * @return object Resolved implementation, or WP_Error on failure.
	 */
	public static function resolve(
		string $owner,
		string $contract,
		string $version,
		string $provider,
		string $id
	): object {
		self::collect();
		$key = self::implementation_key_if_valid( $owner, $contract, $version, $provider, $id );
		if ( null === $key || ! isset( self::$implementations[ $key ], self::$factories[ $key ] ) ) {
			return new WP_Error( 'cb_core_interop_unknown_implementation', 'Unknown interoperability implementation.' );
		}

		$contract_key        = self::contract_key( $owner, $contract, $version );
		$contract_definition = self::$contracts[ $contract_key ] ?? null;
		if ( null === $contract_definition ) {
			return new WP_Error( 'cb_core_interop_unknown_contract', 'Unknown interoperability contract.' );
		}

		try {
			$instance = ( self::$factories[ $key ] )();
		} catch ( Throwable $throwable ) {
			self::diagnostic( sprintf( 'Interoperability factory failed for %s: %s', $key, $throwable->getMessage() ) );
			return new WP_Error( 'cb_core_interop_resolution_failed', 'Interoperability implementation could not be resolved.' );
		}

		$interface = $contract_definition['interface'];
		if ( ! is_object( $instance ) || ! $instance instanceof $interface ) {
			self::diagnostic( sprintf( 'Interoperability contract violation for %s.', $key ) );
			return new WP_Error( 'cb_core_interop_contract_violation', 'Resolved implementation does not satisfy its contract.' );
		}

		return $instance;
	}

	/** @internal Reset request-local state for contract tests. */
	public static function _reset_for_testing(): void {
		self::$contracts                  = [];
		self::$implementations            = [];
		self::$factories                  = [];
		self::$collected                  = false;
		self::$collecting_contracts       = false;
		self::$collecting_implementations = false;
		self::$frozen                     = false;
	}

	/** @param array<string,mixed> $definition */
	private static function register_base_contract_definition( array $definition ): bool {
		if ( self::$frozen ) {
			return false;
		}
		return self::register_contract_definition( $definition, true );
	}

	/** @param array<string,mixed> $definition */
	private static function register_contract_definition( array $definition, bool $base_owned ): bool {
		$normalized = self::normalize_contract( $definition, $base_owned );
		if ( null === $normalized ) {
			return false;
		}

		$key = self::contract_key( $normalized['owner'], $normalized['id'], $normalized['version'] );
		if ( isset( self::$contracts[ $key ] ) ) {
			self::diagnostic( sprintf( 'Duplicate interoperability contract refused: %s.', $key ) );
			return false;
		}

		self::$contracts[ $key ] = $normalized;
		return true;
	}

	/** @param array<string,mixed> $definition
	 *  @return array{owner:string,id:string,version:string,label:string,description:string,interface:string}|null
	 */
	private static function normalize_contract( array $definition, bool $base_owned ): ?array {
		$allowed = [ 'owner', 'id', 'version', 'label', 'description', 'interface' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed ) ) {
			return null;
		}

		$owner       = $base_owned
			? self::BASE_OWNER
			: ( isset( $definition['owner'] ) && is_string( $definition['owner'] ) ? trim( $definition['owner'] ) : '' );
		$id          = isset( $definition['id'] ) && is_string( $definition['id'] ) ? trim( $definition['id'] ) : '';
		$version     = isset( $definition['version'] ) && is_string( $definition['version'] ) ? trim( $definition['version'] ) : '';
		$label       = isset( $definition['label'] ) && is_string( $definition['label'] ) ? trim( wp_strip_all_tags( $definition['label'] ) ) : '';
		$description = isset( $definition['description'] ) && is_string( $definition['description'] ) ? trim( wp_strip_all_tags( $definition['description'] ) ) : '';
		$interface   = isset( $definition['interface'] ) && is_string( $definition['interface'] ) ? ltrim( trim( $definition['interface'] ), '\\' ) : '';

		$owner_valid = $base_owned
			? self::BASE_OWNER === $owner
			: self::BASE_OWNER !== $owner
				&& ExtensionRegistry::is_valid_id( $owner )
				&& null !== ExtensionRegistry::definition( $owner );

		if (
			! $owner_valid
			|| 1 !== preg_match( self::ID_PATTERN, $id )
			|| 1 !== preg_match( self::VERSION_PATTERN, $version )
			|| '' === $label
			|| strlen( $label ) > 120
			|| strlen( $description ) > 500
			|| '' === $interface
			|| ! interface_exists( $interface )
		) {
			self::diagnostic( sprintf( 'Invalid interoperability contract refused: %s::%s@%s.', $owner, $id, $version ) );
			return null;
		}

		return [
			'owner'       => $owner,
			'id'          => $id,
			'version'     => $version,
			'label'       => $label,
			'description' => $description,
			'interface'   => $interface,
		];
	}

	/** @param array<string,mixed> $definition
	 *  @return array{provider:string,id:string,label:string,description:string,contract_owner:string,contract:string,contract_version:string,supports:list<string>,factory:callable}|null
	 */
	private static function normalize_implementation( array $definition ): ?array {
		$allowed = [ 'provider', 'id', 'label', 'description', 'contract_owner', 'contract', 'contract_version', 'supports', 'factory' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed ) ) {
			return null;
		}

		$provider         = isset( $definition['provider'] ) && is_string( $definition['provider'] ) ? trim( $definition['provider'] ) : '';
		$id               = isset( $definition['id'] ) && is_string( $definition['id'] ) ? trim( $definition['id'] ) : '';
		$label            = isset( $definition['label'] ) && is_string( $definition['label'] ) ? trim( wp_strip_all_tags( $definition['label'] ) ) : '';
		$description      = isset( $definition['description'] ) && is_string( $definition['description'] ) ? trim( wp_strip_all_tags( $definition['description'] ) ) : '';
		$contract_owner   = isset( $definition['contract_owner'] ) && is_string( $definition['contract_owner'] ) ? trim( $definition['contract_owner'] ) : '';
		$contract         = isset( $definition['contract'] ) && is_string( $definition['contract'] ) ? trim( $definition['contract'] ) : '';
		$contract_version = isset( $definition['contract_version'] ) && is_string( $definition['contract_version'] ) ? trim( $definition['contract_version'] ) : '';
		$supports         = isset( $definition['supports'] ) && is_array( $definition['supports'] ) ? self::normalize_supports( $definition['supports'] ) : null;
		$factory          = $definition['factory'] ?? null;

		$contract_key = self::contract_key_if_valid( $contract_owner, $contract, $contract_version );
		if (
			! ExtensionRegistry::is_valid_id( $provider )
			|| null === ExtensionRegistry::definition( $provider )
			|| 1 !== preg_match( self::ID_PATTERN, $id )
			|| '' === $label
			|| strlen( $label ) > 120
			|| strlen( $description ) > 500
			|| null === $contract_key
			|| ! isset( self::$contracts[ $contract_key ] )
			|| null === $supports
			|| ! is_callable( $factory )
		) {
			self::diagnostic( sprintf( 'Invalid interoperability implementation refused: %s::%s.', $provider, $id ) );
			return null;
		}

		return [
			'provider'         => $provider,
			'id'               => $id,
			'label'            => $label,
			'description'      => $description,
			'contract_owner'   => $contract_owner,
			'contract'         => $contract,
			'contract_version' => $contract_version,
			'supports'         => $supports,
			'factory'          => $factory,
		];
	}

	/** @param array<mixed> $supports @return list<string>|null */
	private static function normalize_supports( array $supports ): ?array {
		if ( ! array_is_list( $supports ) || count( $supports ) > 64 ) {
			return null;
		}

		$normalized = [];
		foreach ( $supports as $support ) {
			if ( ! is_string( $support ) ) {
				return null;
			}
			$support = trim( $support );
			if ( 1 !== preg_match( self::ID_PATTERN, $support ) ) {
				return null;
			}
			$normalized[ $support ] = true;
		}

		$out = array_keys( $normalized );
		sort( $out );
		return $out;
	}

	private static function contract_key( string $owner, string $id, string $version ): string {
		return $owner . '::' . $id . '@' . $version;
	}

	private static function contract_key_if_valid( string $owner, string $id, string $version ): ?string {
		$owner   = trim( $owner );
		$id      = trim( $id );
		$version = trim( $version );
		if (
			( self::BASE_OWNER !== $owner && ! ExtensionRegistry::is_valid_id( $owner ) )
			|| 1 !== preg_match( self::ID_PATTERN, $id )
			|| 1 !== preg_match( self::VERSION_PATTERN, $version )
		) {
			return null;
		}
		return self::contract_key( $owner, $id, $version );
	}

	private static function implementation_key(
		string $owner,
		string $contract,
		string $version,
		string $provider,
		string $id
	): string {
		return self::contract_key( $owner, $contract, $version ) . '::' . $provider . '::' . $id;
	}

	private static function implementation_key_if_valid(
		string $owner,
		string $contract,
		string $version,
		string $provider,
		string $id
	): ?string {
		$contract_key = self::contract_key_if_valid( $owner, $contract, $version );
		$provider     = trim( $provider );
		$id           = trim( $id );
		if (
			null === $contract_key
			|| ! ExtensionRegistry::is_valid_id( $provider )
			|| 1 !== preg_match( self::ID_PATTERN, $id )
		) {
			return null;
		}
		return $contract_key . '::' . $provider . '::' . $id;
	}

	private static function diagnostic( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Core Blueprint Interoperability] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only contract diagnostic.
		}
	}
}
