<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Reports;

use CoreBlueprint\Core\Design\Kernel\AuthorizationRegistry;
use CoreBlueprint\Core\Design\Kernel\CapabilityClass;
use CoreBlueprint\Core\Design\Kernel\CapabilityRegistry;
use CoreBlueprint\Core\Design\Kernel\DesignTypeRegistry;
use CoreBlueprint\Core\Design\Registry\ProfileRegistry;
use CoreBlueprint\Core\Design\Registry\ComponentRegistry;
use CoreBlueprint\Core\Design\Registry\ControlRegistry;
use CoreBlueprint\Core\Design\Registry\ModuleCatalog;
use CoreBlueprint\Core\Design\Integrity\ApprovedManifestSource;

defined( 'ABSPATH' ) || exit;

/**
 * First-party Reports Designer v1. Branding is persisted via schema-bound
 * fields; Composer blocks are native StableSchemaV1 nodes, never older JSON.
 */
final class PlatformAdapter {
	public const PROVIDER = 'core.blueprint';
	public const TYPE = 'core.blueprint.reports';
	public const PROFILE = 'base/reports';
	public const SCHEMA = 'base/reports/1';
	public const MODULE = '@cb-core/reports-platform';
	public const PATH = 'assets/js/design/reports/platform-adapter.js';

	public static function declare_type(
		DesignTypeRegistry $types, CapabilityRegistry $caps, AuthorizationRegistry $grants
	): void {
		if ( ! $types->register( self::TYPE, self::PROVIDER )
			|| ! $caps->register( self::PROVIDER, 'reports.edit', CapabilityClass::Data )
			|| ! $grants->grant_consumer( self::TYPE, self::PROVIDER, 'reports.edit' ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
	}

	public static function register(
		DesignTypeRegistry $types, ProfileRegistry $profiles,
		ComponentRegistry $components, ControlRegistry $controls,
		ModuleCatalog $modules, array $artifact
	): void {
		$types_snapshot = array_map( static fn( array $t ): array => [
			'id' => $t['id'], 'ownerProvider' => $t['owner_provider'],
			'allowSitePresentational' => $t['allow_site_presentational'],
		], $types->definitions() );
		$schemas = [
			self::TYPE => self::object_schema( [ 'title' => self::string_schema( 200 ) ] ),
			'reports.heading' => self::object_schema( [
				'text' => self::string_schema( 500 ),
			] ),
			'reports.metric' => self::object_schema( [
				'label' => self::string_schema( 160 ), 'value' => self::string_schema( 200 ),
			] ),
			'reports.paragraph' => self::object_schema( [
				'text' => self::string_schema( 20000 ),
			] ),
			'reports.section' => self::object_schema( [
				'title' => self::string_schema( 160 ),
			] ),
		];
		$nodes = [];
		$domains = [];
		foreach ( $schemas as $type => $schema ) {
			$id = $type . '.props';
			$nodes[] = [
				'id' => $type, 'provider' => self::PROVIDER,
				'allowedParents' => match ( $type ) {
					self::TYPE => [],
					'reports.section' => [ self::TYPE, 'reports.section' ],
					default => [ 'reports.section' ],
				},
				'propertiesSchemaId' => $id,
			];
			$domains[] = [ 'id' => $id, 'ownerProvider' => self::PROVIDER, 'schema' => $schema ];
		}
		$fields = [
			[
				'contractVersion' => 1, 'id' => 'reports.accent', 'saveKey' => 'accentColor',
				'labelKey' => 'Accent color', 'valueSchema' => self::string_schema( 32 ),
				'placement' => 'settings', 'requiredCapability' => 'reports.edit',
			],
			[
				'contractVersion' => 1, 'id' => 'reports.brand', 'saveKey' => 'brandName',
				'labelKey' => 'Brand name', 'valueSchema' => self::string_schema( 200 ),
				'placement' => 'settings', 'requiredCapability' => 'reports.edit',
			],
			[
				'contractVersion' => 1, 'id' => 'reports.logo', 'saveKey' => 'logoUrl',
				'labelKey' => 'Logo URL', 'valueSchema' => self::string_schema( 2048 ),
				'placement' => 'settings', 'requiredCapability' => 'reports.edit',
			],
		];
		if ( ! $modules->register( [
			'id' => self::MODULE, 'owner' => self::PROVIDER,
			'dependencies' => [], 'exports' => [
				'authorizeCommand', 'createRenderAdapter', 'resolveCommand',
				'validateCommandInput', 'validateProject',
			],
			'stamp' => ApprovedManifestSource::module_stamp( $artifact, self::PATH ),
			'packagePath' => 'base/' . self::PATH,
		] ) || ! $profiles->register( [
			'id' => self::PROFILE, 'owner' => self::PROVIDER,
			'designType' => self::TYPE, 'projectSchema' => self::SCHEMA,
			'schemaSnapshot' => [
				'contractVersion' => 1, 'schemaGeneration' => 1,
				'profileId' => self::PROFILE, 'projectSchema' => self::SCHEMA,
				'providers' => [ self::PROVIDER ], 'designTypes' => $types_snapshot,
				'nodeTypes' => $nodes, 'domainSchemas' => $domains,
			],
			'moduleId' => self::MODULE, 'requiredCapabilities' => [ 'reports.edit' ],
			'persistedFieldDefinitions' => $fields, 'dependencies' => [],
		] ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
		foreach ( [ 'reports.heading', 'reports.metric', 'reports.paragraph', 'reports.section' ] as $type ) {
			$id = 'base/' . str_replace( '.', '-', $type );
			if ( ! $components->register( [
				'id' => $id, 'owner' => self::PROVIDER, 'profileId' => self::PROFILE,
				'moduleId' => self::MODULE, 'schemaId' => $type . '.props', 'requires' => [],
			] ) || ! $controls->register( [
				'id' => $id . '-primary', 'owner' => self::PROVIDER, 'profileId' => self::PROFILE,
				'componentId' => $id, 'moduleId' => self::MODULE, 'requires' => [],
			] ) ) {
				throw new \DomainException( 'REGISTRY_INVALID' );
			}
		}
	}

	public static function default_project(): array {
		return [
			'schema_version' => 1, 'design_type' => self::TYPE,
			'root' => [
				'type' => self::TYPE, 'provider' => self::PROVIDER,
				'properties' => [ 'title' => 'Maintenance report' ],
				'children' => [ [
					'type' => 'reports.section', 'provider' => self::PROVIDER,
					'properties' => [ 'title' => 'Overview' ],
					'children' => [ [
						'type' => 'reports.heading', 'provider' => self::PROVIDER,
						'properties' => [ 'text' => 'Summary' ], 'children' => [],
					], [
						'type' => 'reports.paragraph', 'provider' => self::PROVIDER,
						'properties' => [ 'text' => '' ], 'children' => [],
					] ],
				] ],
			],
		];
	}

	public static function default_fields(): array {
		return [ 'accentColor' => '#3159a5', 'brandName' => '', 'logoUrl' => '' ];
	}
	private static function string_schema( int $max ): array {
		return [ 'type' => 'string', 'maxLength' => $max ];
	}
	private static function object_schema( array $properties ): array {
		return [ 'type' => 'object', 'properties' => $properties,
			'required' => array_keys( $properties ), 'additionalProperties' => false ];
	}

	private function __construct() {}
}
