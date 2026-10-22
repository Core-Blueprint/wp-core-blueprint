<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only StableSchemaV1 projection of the ONE trusted FrozenRegistry.
 * This class neither collects registrations nor publishes a second registry.
 * Its input MUST originate from the accepted Base FrozenRegistry, never a
 * stored DesignProject, REST payload, field map, or browser-provided metadata.
 */
final class StableSchemaRegistry {
	/** @var array<string,array{id:string,provider:string,allowedParents:list<string>,propertiesSchemaId:?string}> */
	private array $node_types = [];
	/** @var array<string,array{id:string,ownerProvider:string,schema:array<string,mixed>}> */
	private array $domain_schemas = [];
	/** @var array<string,array{id:string,ownerProvider:string,allowSitePresentational:bool}> */
	private array $design_types = [];
	private string $profile_id;
	private string $project_schema;

	/** @param array<string,mixed> $snapshot Exact project-only RegistrySnapshotV1 from the trusted freeze. */
	public function __construct(
		array $snapshot,
		private readonly ProviderRegistry $providers,
		private readonly DesignTypeRegistry $types
	) {
		$keys = [ 'contractVersion', 'schemaGeneration', 'profileId', 'projectSchema', 'providers', 'designTypes', 'nodeTypes', 'domainSchemas', 'fingerprint' ];
		$actual = array_keys( $snapshot );
		sort( $actual, SORT_STRING );
		$expected = $keys;
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || 1 !== ( $snapshot['contractVersion'] ?? null )
			|| 1 !== ( $snapshot['schemaGeneration'] ?? null )
			|| ! is_string( $snapshot['profileId'] ) || '' === $snapshot['profileId']
			|| ! is_string( $snapshot['projectSchema'] ) || '' === $snapshot['projectSchema']
			|| ! is_string( $snapshot['fingerprint'] )
			|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $snapshot['fingerprint'] )
		) {
			throw new \InvalidArgumentException( 'REGISTRY_INVALID: invalid stable schema snapshot header.' );
		}
		$this->profile_id     = $snapshot['profileId'];
		$this->project_schema = $snapshot['projectSchema'];

		if ( ! is_array( $snapshot['providers'] ) || ! array_is_list( $snapshot['providers'] )
			|| count( $snapshot['providers'] ) !== count( array_unique( $snapshot['providers'] ) ) ) {
			throw new \InvalidArgumentException( 'REGISTRY_INVALID: inconsistent canonical providers.' );
		}
		$prior = '';
		foreach ( $snapshot['providers'] as $id ) {
			if ( ! is_string( $id ) || ! Identifier::provider( $id )
				|| ! $providers->has_canonical( $id )
				|| ( '' !== $prior && strcmp( $prior, $id ) >= 0 ) ) {
				throw new \InvalidArgumentException( 'REGISTRY_INVALID: unregistered or unordered canonical provider.' );
			}
			$prior = $id;
		}
		foreach ( [ 'designTypes', 'nodeTypes', 'domainSchemas' ] as $section ) {
			if ( ! is_array( $snapshot[ $section ] ) || ! array_is_list( $snapshot[ $section ] ) ) {
				throw new \InvalidArgumentException( 'REGISTRY_INVALID: malformed frozen definitions.' );
			}
		}
		$previous = '';
		foreach ( $snapshot['designTypes'] as $item ) {
			if ( ! self::exact_keys( $item, [ 'id', 'ownerProvider', 'allowSitePresentational' ] )
				|| ! is_string( $item['id'] ) || ! Identifier::design_type( $item['id'] )
				|| ! is_string( $item['ownerProvider'] ) || ! $providers->has_canonical( $item['ownerProvider'] )
				|| ! is_bool( $item['allowSitePresentational'] )
				|| ( '' !== $previous && strcmp( $previous, $item['id'] ) >= 0 )
				|| ( $types->definition( $item['id'] )['owner_provider'] ?? null ) !== $item['ownerProvider']
			) {
				throw new \InvalidArgumentException( 'REGISTRY_INVALID: invalid or unordered design type.' );
			}
			$previous = $item['id'];
			$this->design_types[ $item['id'] ] = $item;
		}
		$previous = '';
		foreach ( $snapshot['domainSchemas'] as $item ) {
			if ( ! self::exact_keys( $item, [ 'id', 'ownerProvider', 'schema' ] )
				|| ! is_string( $item['id'] ) || '' === $item['id']
				|| ! is_string( $item['ownerProvider'] ) || ! $providers->has_canonical( $item['ownerProvider'] )
				|| ! is_array( $item['schema'] )
				|| ( '' !== $previous && strcmp( $previous, $item['id'] ) >= 0 )
			) {
				throw new \InvalidArgumentException( 'REGISTRY_INVALID: invalid or unordered domain schema.' );
			}
			$previous = $item['id'];
			$this->domain_schemas[ $item['id'] ] = $item;
		}
		$previous = '';
		foreach ( $snapshot['nodeTypes'] as $item ) {
			if ( ! self::exact_keys( $item, [ 'id', 'provider', 'allowedParents', 'propertiesSchemaId' ] )
				|| ! is_string( $item['id'] ) || ! Identifier::node_type( $item['id'] )
				|| ! is_string( $item['provider'] ) || ! $providers->has_canonical( $item['provider'] )
				|| ! is_array( $item['allowedParents'] ) || ! array_is_list( $item['allowedParents'] )
				|| ( null !== $item['propertiesSchemaId'] && ! is_string( $item['propertiesSchemaId'] ) )
				|| ( '' !== $previous && strcmp( $previous, $item['id'] ) >= 0 )
			) {
				throw new \InvalidArgumentException( 'REGISTRY_INVALID: invalid or unordered node type.' );
			}
			foreach ( $item['allowedParents'] as $parent ) {
				if ( ! is_string( $parent ) || ! Identifier::node_type( $parent ) ) {
					throw new \InvalidArgumentException( 'REGISTRY_INVALID: invalid parent type.' );
				}
			}
			if ( null !== $item['propertiesSchemaId']
				&& ( ! isset( $this->domain_schemas[ $item['propertiesSchemaId'] ] )
					|| $this->domain_schemas[ $item['propertiesSchemaId'] ]['ownerProvider'] !== $item['provider'] )
			) {
				throw new \InvalidArgumentException( 'REGISTRY_INVALID: missing or foreign property schema.' );
			}
			$previous = $item['id'];
			$this->node_types[ $item['id'] ] = $item;
		}
		foreach ( $this->node_types as $item ) {
			foreach ( $item['allowedParents'] as $parent ) {
				if ( ! isset( $this->node_types[ $parent ] ) ) {
					throw new \InvalidArgumentException( 'REGISTRY_INVALID: unknown allowed parent.' );
				}
			}
		}
	}

	/** @param mixed $value @param list<string> $keys */
	private static function exact_keys( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}

	public function matches( string $profile_id, string $project_schema ): bool {
		return $this->profile_id === $profile_id && $this->project_schema === $project_schema;
	}

	public function has_canonical_provider( string $id ): bool {
		return $this->providers->has_canonical( $id );
	}

	/** @return array<string,mixed>|null */
	public function design_type( string $id ): ?array {
		return $this->design_types[ $id ] ?? null;
	}

	/** @return array<string,mixed>|null */
	public function node_type( string $id ): ?array {
		return $this->node_types[ $id ] ?? null;
	}

	/** @return array<string,mixed>|null */
	public function domain_schema( string $id ): ?array {
		return $this->domain_schemas[ $id ] ?? null;
	}
}
