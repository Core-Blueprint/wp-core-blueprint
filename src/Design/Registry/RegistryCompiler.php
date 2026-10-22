<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Registry;

use CoreBlueprint\Core\Design\Kernel\AuthorizationRegistry;
use CoreBlueprint\Core\Design\Kernel\CapabilityRegistry;
use CoreBlueprint\Core\Design\Kernel\DesignTypeRegistry;
use CoreBlueprint\Core\Design\Kernel\Identifier;
use CoreBlueprint\Core\Design\Kernel\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * One registration transaction: COLLECT -> VALIDATE -> RESOLVE -> SORT -> FREEZE.
 * All participants are installed PHP declarations; no browser JSON is accepted.
 */
final class RegistryCompiler {
	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly DesignTypeRegistry $design_types,
		private readonly CapabilityRegistry $capabilities,
		private readonly AuthorizationRegistry $authorizations,
		private readonly DependencySorter $sorter = new DependencySorter()
	) {}

	/**
	 * @throws \DomainException REGISTRY_INVALID/COLLISION/DEPENDENCY_MISSING/CYCLE
	 */
	public function compile(
		ProfileRegistry $profiles,
		ComponentRegistry $components,
		ControlRegistry $controls,
		ModuleCatalog $catalog
	): FrozenRegistry {
		if ( array_merge( $profiles->failures(), $components->failures(), $controls->failures(), $catalog->failures() ) ) {
			$failures = array_merge( $profiles->failures(), $components->failures(), $controls->failures(), $catalog->failures() );
			throw new \DomainException( in_array( 'REGISTRY_COLLISION', $failures, true ) ? 'REGISTRY_COLLISION' : 'REGISTRY_INVALID' );
		}
		$provider_ids = $this->providers->providers();
		sort( $provider_ids, SORT_STRING );
		$types = $this->design_types->definitions();
		$capabilities = $this->capabilities->definitions();
		$grants = $this->authorizations->definitions();
		// Reject any refused Base-owned registration claim before publication.
		$kernel_failures = array_merge(
			$this->design_types->failures(),
			$this->capabilities->failures(),
			$this->authorizations->failures()
		);
		if ( $kernel_failures ) {
			throw new \DomainException( in_array( 'REGISTRY_COLLISION', $kernel_failures, true ) ? 'REGISTRY_COLLISION' : 'REGISTRY_INVALID' );
		}
		$module_declarations = $catalog->all();
		$module_map = [];
		$dependencies = [];
		foreach ( $module_declarations as $module ) {
			self::keys( $module, [ 'id', 'owner', 'dependencies', 'exports', 'stamp' ] );
			if ( ! $this->providers->has_canonical( $module['owner'] ) || ! self::strings( $module['dependencies'] ) || ! self::strings( $module['exports'] )
				|| ! is_string( $module['stamp'] ) || ! preg_match( '/^[a-z0-9._:-]{1,128}$/D', $module['stamp'] ) ) {
				self::invalid();
			}
			foreach ( $module['exports'] as $export ) {
				if ( ! preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/D', $export ) ) {
					self::invalid();
				}
			}
			$module_map[ $module['id'] ] = $module;
			$dependencies[ $module['id'] ] = $module['dependencies'];
		}
		$order = $this->sorter->sort( $dependencies );
		$modules = array_map( static fn( string $id ): array => $module_map[ $id ], $order );
		$profile_list = $profiles->all();
		$profile_ids = array_column( $profile_list, null, 'id' );
		foreach ( $profile_list as &$profile ) {
			self::keys( $profile, [ 'id', 'owner', 'designType', 'projectSchema', 'schemaSnapshot', 'moduleId', 'requiredCapabilities', 'persistedFieldDefinitions', 'dependencies' ], [ 'dependencies' ] );
			if ( ! $this->providers->has_canonical( $profile['owner'] ) || ! is_string( $profile['designType'] )
				|| ! Identifier::design_type( $profile['designType'] ) || ! is_array( $profile['schemaSnapshot'] )
				|| ! is_string( $profile['projectSchema'] ) || ! preg_match( '~^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*/[1-9][0-9]*$~D', $profile['projectSchema'] )
				|| ! is_string( $profile['moduleId'] ) || ! isset( $module_map[ $profile['moduleId'] ] )
				|| ! self::strings( $profile['requiredCapabilities'] ) || ! is_array( $profile['persistedFieldDefinitions'] )
				|| ! array_is_list( $profile['persistedFieldDefinitions'] ) ) {
				self::invalid();
			}
			$type = $this->design_types->definition( $profile['designType'] );
			if ( null === $type || $type['owner_provider'] !== $profile['owner'] || $module_map[ $profile['moduleId'] ]['owner'] !== $profile['owner'] ) {
				self::invalid();
			}
			$snapshot = $profile['schemaSnapshot'];
			self::keys( $snapshot, [ 'contractVersion', 'schemaGeneration', 'profileId', 'projectSchema', 'providers', 'designTypes', 'nodeTypes', 'domainSchemas' ] );
			if ( 1 !== $snapshot['contractVersion'] || 1 !== $snapshot['schemaGeneration']
				|| $snapshot['profileId'] !== $profile['id'] || $snapshot['projectSchema'] !== $profile['projectSchema']
				|| $snapshot['providers'] !== $provider_ids || $snapshot['designTypes'] !== self::types_for_snapshot( $types )
				|| ! is_array( $snapshot['nodeTypes'] ) || ! array_is_list( $snapshot['nodeTypes'] )
				|| ! is_array( $snapshot['domainSchemas'] ) || ! array_is_list( $snapshot['domainSchemas'] ) ) {
				self::invalid();
			}
			// The schema owner supplies validated stable-v1 node/domain declarations;
			// never fabricate empty schema definitions on missing handshake data.
			foreach ( [ 'nodeTypes', 'domainSchemas' ] as $key ) {
				$previous = null;
				foreach ( $snapshot[ $key ] as $definition ) {
					if ( ! is_array( $definition ) || ! is_string( $definition['id'] ?? null ) || ( null !== $previous && strcmp( $previous, $definition['id'] ) >= 0 ) ) {
						self::invalid();
					}
					$previous = $definition['id'];
					if ( 'nodeTypes' === $key ) {
						self::keys( $definition, [ 'id', 'provider', 'allowedParents', 'propertiesSchemaId' ] );
						if ( ! Identifier::node_type( $definition['id'] ) || ! self::strings( $definition['allowedParents'] )
							|| ( null !== $definition['propertiesSchemaId'] && ! is_string( $definition['propertiesSchemaId'] ) ) ) {
							self::invalid();
						}
					} else {
						self::keys( $definition, [ 'id', 'ownerProvider', 'schema' ] );
						if ( ! is_array( $definition['schema'] ) || [] === $definition['schema'] || array_is_list( $definition['schema'] ) ) {
							self::invalid();
						}
					}
					$owner = $definition['provider'] ?? $definition['ownerProvider'] ?? null;
					if ( ! is_string( $owner ) || ! in_array( $owner, $provider_ids, true ) ) {
						self::invalid();
					}
				}
			}
			$node_ids = array_column( $snapshot['nodeTypes'], 'id' );
			$schema_ids = array_column( $snapshot['domainSchemas'], 'id' );
			foreach ( $snapshot['nodeTypes'] as $node ) {
				foreach ( $node['allowedParents'] as $parent ) {
					if ( ! in_array( $parent, $node_ids, true ) ) {
						self::invalid();
					}
				}
				if ( null !== $node['propertiesSchemaId'] && ! in_array( $node['propertiesSchemaId'], $schema_ids, true ) ) {
					self::invalid();
				}
			}
			foreach ( $profile['requiredCapabilities'] as $capability ) {
				if ( null === $this->capabilities->canonical_class_for( $profile['owner'], $capability )
					|| ! $this->authorizations->is_authorized_canonical( $profile['designType'], $profile['owner'], $capability ) ) {
					self::invalid();
				}
			}
			$fields = [];
			$ids = [];
			foreach ( $profile['persistedFieldDefinitions'] as $field ) {
				if ( ! is_array( $field ) ) {
					self::invalid();
				}
				self::keys( $field, [ 'contractVersion', 'id', 'saveKey', 'labelKey', 'valueSchema', 'placement', 'requiredCapability' ], [ 'requiredCapability' ] );
				if ( 1 !== $field['contractVersion'] || ! self::field_id( $field['id'] ) || ! self::field_id( $field['saveKey'] )
					|| ! is_string( $field['labelKey'] ) || '' === $field['labelKey'] || ! is_array( $field['valueSchema'] )
					|| ! in_array( $field['placement'], [ 'header', 'settings' ], true ) ) {
					self::invalid();
				}
				if ( isset( $fields[ $field['saveKey'] ] ) || isset( $ids[ $field['id'] ] ) ) {
					throw new \DomainException( 'REGISTRY_COLLISION' );
				}
				if ( isset( $field['requiredCapability'] ) && ( ! is_string( $field['requiredCapability'] )
					|| null === $this->capabilities->canonical_class_for( $profile['owner'], $field['requiredCapability'] ) ) ) {
					self::invalid();
				}
				$fields[ $field['saveKey'] ] = $field;
				$ids[ $field['id'] ] = true;
			}
			ksort( $fields, SORT_STRING );
			$profile['persistedFieldDefinitions'] = array_values( $fields );
			$profile['dependencies'] = $profile['dependencies'] ?? [];
			if ( ! self::strings( $profile['dependencies'] ) ) {
				self::invalid();
			}
		}
		unset( $profile );
		$profile_map = array_column( $profile_list, null, 'id' );
		$profile_order = $this->sorter->sort( array_column( $profile_list, 'dependencies', 'id' ) );
		$profile_list = array_map( static fn( string $id ): array => $profile_map[ $id ], $profile_order );
		foreach ( $components->all() as $component ) {
			self::keys( $component, [ 'id', 'owner', 'profileId', 'moduleId', 'schemaId', 'requires' ], [ 'requires' ] );
			if ( ! $this->providers->has_canonical( $component['owner'] ) || ! isset( $profile_ids[ $component['profileId'] ] )
				|| $profile_ids[ $component['profileId'] ]['owner'] !== $component['owner'] || ! isset( $module_map[ $component['moduleId'] ] )
				|| $module_map[ $component['moduleId'] ]['owner'] !== $component['owner']
				|| ! is_string( $component['schemaId'] ) || ! self::strings( $component['requires'] ?? [] ) ) {
				self::invalid();
			}
		}
		$component_list = $components->all();
		$component_ids = array_column( $component_list, null, 'id' );
		foreach ( $component_list as $component ) {
			$schema = $profile_ids[ $component['profileId'] ]['schemaSnapshot'];
			$registered_schema_ids = array_column( $schema['domainSchemas'], 'id' );
			$registered_schema_ids = array_merge( $registered_schema_ids, array_filter( array_column( $schema['nodeTypes'], 'propertiesSchemaId' ), 'is_string' ) );
			if ( ! in_array( $component['schemaId'], $registered_schema_ids, true ) ) {
				self::invalid();
			}
		}
		$component_list = array_map( static fn( array $item ): array => array_replace( [ 'requires' => [] ], $item ), $component_list );
		$component_map = array_column( $component_list, null, 'id' );
		$component_order = $this->sorter->sort( array_column( $component_list, 'requires', 'id' ) );
		$component_list = array_map( static fn( string $id ): array => $component_map[ $id ], $component_order );
		foreach ( $controls->all() as $control ) {
			self::keys( $control, [ 'id', 'owner', 'profileId', 'componentId', 'moduleId', 'requires' ], [ 'requires' ] );
			if ( ! $this->providers->has_canonical( $control['owner'] ) || ! isset( $profile_ids[ $control['profileId'] ] )
				|| $profile_ids[ $control['profileId'] ]['owner'] !== $control['owner'] || ! isset( $component_ids[ $control['componentId'] ] )
				|| ! isset( $module_map[ $control['moduleId'] ] )
				|| $module_map[ $control['moduleId'] ]['owner'] !== $control['owner']
				|| $component_ids[ $control['componentId'] ]['profileId'] !== $control['profileId']
				|| ! self::strings( $control['requires'] ?? [] ) ) {
				self::invalid();
			}
		}
		$control_list = array_map( static fn( array $item ): array => array_replace( [ 'requires' => [] ], $item ), $controls->all() );
		$control_map = array_column( $control_list, null, 'id' );
		$control_order = $this->sorter->sort( array_column( $control_list, 'requires', 'id' ) );
		$control_list = array_map( static fn( string $id ): array => $control_map[ $id ], $control_order );
		$graph = [
			'contractVersion' => 1,
			'schemaGeneration' => 1,
			'providers' => $provider_ids,
			'designTypes' => $types,
			'capabilities' => $capabilities,
			'grants' => $grants,
			'profiles' => $profile_list,
			'components' => $component_list,
			'controls' => $control_list,
			'modules' => $modules,
		];
		if ( ! FrozenRegistry::pure( $graph ) ) {
			self::invalid();
		}
		$frozen = new FrozenRegistry( $graph );
		$this->design_types->seal();
		$this->capabilities->seal();
		$this->authorizations->seal();
		return $frozen;
	}

	private static function types_for_snapshot( array $types ): array {
		return array_map( static fn( array $t ): array => [
			'id' => $t['id'], 'ownerProvider' => $t['owner_provider'],
			'allowSitePresentational' => $t['allow_site_presentational'],
		], $types );
	}

	private static function field_id( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)*$/D', $value );
	}

	private static function strings( mixed $value ): bool {
		return is_array( $value ) && array_is_list( $value )
			&& count( array_filter( $value, 'is_string' ) ) === count( $value )
			&& count( array_unique( $value ) ) === count( $value );
	}

	private static function keys( array $value, array $allowed, array $optional = [] ): void {
		foreach ( $value as $key => $_ ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				self::invalid();
			}
		}
		foreach ( array_diff( $allowed, $optional ) as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				self::invalid();
			}
		}
	}

	private static function invalid(): never {
		throw new \DomainException( 'REGISTRY_INVALID' );
	}
}
