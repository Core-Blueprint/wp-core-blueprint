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
 * First-party native Reports StableSchemaV1 profile. No Composer document,
 * URLs or separate persistence authority are registered here.
 *
 * Values are bounded strings because the frozen Foundation Inspector and
 * Settings controls currently only support strings. Semantic enums, logo ID
 * and node invariants are verified in Reports\Composer\NativeComposition.
 */
final class PlatformAdapter {
	public const PROVIDER = 'core.blueprint';
	public const TYPE = 'core.blueprint.reports';
	public const PROFILE = 'base/reports';
	public const SCHEMA = 'base/reports/1';
	public const MODULE = '@cb-core/reports-platform';
	public const PATH = 'assets/js/design/reports/platform-adapter.js';

	/** @return array<string,array{parents:list<string>,properties:array<string,array<string,mixed>>}> */
	public static function node_definitions(): array {
		$root = self::TYPE;
		return [
			$root => [
				'parents' => [],
				'properties' => [ 'title' => self::string_schema( 200 ) ],
			],
			'reports.activity' => [
				'parents' => [ $root ],
				'properties' => [ 'enabled' => self::string_schema( 3 ), 'showIntro' => self::string_schema( 3 ) ],
			],
			'reports.current-state' => [
				'parents' => [ $root ],
				'properties' => [ 'enabled' => self::string_schema( 3 ), 'showNotes' => self::string_schema( 3 ) ],
			],
			'reports.footer' => [
				'parents' => [ $root ],
				'properties' => [ 'showPageNumber' => self::string_schema( 3 ) ],
			],
			'reports.header' => [
				'parents' => [ $root ],
				'properties' => [ 'showSiteUrl' => self::string_schema( 3 ), 'showMetadata' => self::string_schema( 3 ) ],
			],
			'reports.heading' => [
				'parents' => [ $root, 'reports.section' ],
				'properties' => [ 'text' => self::string_schema( 500 ) ],
			],
			'reports.kpis' => [
				'parents' => [ $root ],
				'properties' => [ 'enabled' => self::string_schema( 3 ), 'showDetails' => self::string_schema( 3 ) ],
			],
			'reports.metric' => [
				'parents' => [ $root, 'reports.section' ],
				'properties' => [ 'label' => self::string_schema( 160 ), 'value' => self::string_schema( 200 ) ],
			],
			'reports.notes' => [
				'parents' => [ $root ],
				'properties' => [ 'enabled' => self::string_schema( 3 ), 'showHeading' => self::string_schema( 3 ) ],
			],
			'reports.paragraph' => [
				'parents' => [ $root, 'reports.section' ],
				'properties' => [ 'text' => self::string_schema( 20000 ) ],
			],
			'reports.section' => [
				'parents' => [ $root ],
				'properties' => [ 'title' => self::string_schema( 160 ), 'enabled' => self::string_schema( 3 ) ],
			],
			'reports.status' => [
				'parents' => [ $root ],
				'properties' => [ 'enabled' => self::string_schema( 3 ), 'showDetails' => self::string_schema( 3 ) ],
			],
			'reports.summary' => [
				'parents' => [ $root ],
				'properties' => [
					'enabled' => self::string_schema( 3 ), 'showSecurity' => self::string_schema( 3 ),
					'showBackups' => self::string_schema( 3 ),
				],
			],
		];
	}

	/** @return array<string,array{max:int,default:string}> */
	public static function branding_definitions(): array {
		return [
			'accent_color' => [ 'max' => 7, 'default' => '#0064c8' ],
			'corner_style' => [ 'max' => 7, 'default' => 'soft' ],
			'density' => [ 'max' => 11, 'default' => 'comfortable' ],
			'logo_attachment_id' => [ 'max' => 12, 'default' => '0' ],
			'page_format' => [ 'max' => 6, 'default' => 'a4' ],
			'page_orientation' => [ 'max' => 9, 'default' => 'portrait' ],
			'page_margin_mm' => [ 'max' => 2, 'default' => '12' ],
			'provider_contact' => [ 'max' => 200, 'default' => '' ],
			'provider_name' => [ 'max' => 120, 'default' => '' ],
			'section_spacing_mm' => [ 'max' => 2, 'default' => '5' ],
			'show_logo' => [ 'max' => 3, 'default' => 'on' ],
			'surface_style' => [ 'max' => 5, 'default' => 'cards' ],
			'text_scale' => [ 'max' => 8, 'default' => 'standard' ],
		];
	}

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
		$types_snapshot = array_map( static fn ( array $t ): array => [
			'id' => $t['id'], 'ownerProvider' => $t['owner_provider'],
			'allowSitePresentational' => $t['allow_site_presentational'],
		], $types->definitions() );
		$nodes = [];
		$domains = [];
		foreach ( self::node_definitions() as $type => $definition ) {
			$id = $type . '.props';
			$nodes[] = [
				'id' => $type, 'provider' => self::PROVIDER,
				'allowedParents' => $definition['parents'],
				'propertiesSchemaId' => $id,
			];
			$domains[] = [
				'id' => $id, 'ownerProvider' => self::PROVIDER,
				'schema' => self::object_schema( $definition['properties'] ),
			];
		}
		$fields = [];
		foreach ( self::branding_definitions() as $key => $definition ) {
			$fields[] = [
				'contractVersion' => 1, 'id' => 'reports.' . strtolower( $key ),
				'saveKey' => $key, 'labelKey' => $key,
				'valueSchema' => self::string_schema( $definition['max'] ),
				'placement' => 'settings', 'requiredCapability' => 'reports.edit',
			];
		}
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
		foreach ( array_keys( self::node_definitions() ) as $type ) {
			if ( self::TYPE === $type ) {
				continue;
			}
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

	/** Native, fully populated baseline: a layout, not historical report data. */
	public static function default_project(): array {
		$types = [
			'reports.header' => [ 'showSiteUrl' => 'on', 'showMetadata' => 'on' ],
			'reports.status' => [ 'enabled' => 'on', 'showDetails' => 'on' ],
			'reports.kpis' => [ 'enabled' => 'on', 'showDetails' => 'on' ],
			'reports.current-state' => [ 'enabled' => 'on', 'showNotes' => 'on' ],
			'reports.activity' => [ 'enabled' => 'on', 'showIntro' => 'on' ],
			'reports.summary' => [ 'enabled' => 'on', 'showSecurity' => 'on', 'showBackups' => 'on' ],
			'reports.notes' => [ 'enabled' => 'on', 'showHeading' => 'on' ],
			'reports.footer' => [ 'showPageNumber' => 'on' ],
		];
		$children = [];
		foreach ( $types as $type => $properties ) {
			$children[] = self::node( $type, $properties );
		}
		return [
			'schema_version' => 1, 'design_type' => self::TYPE,
			'root' => self::node( self::TYPE, [ 'title' => 'Maintenance Report' ], $children ),
		];
	}

	public static function default_fields(): array {
		$fields = [];
		foreach ( self::branding_definitions() as $key => $definition ) {
			$fields[$key] = $definition['default'];
		}
		return $fields;
	}

	private static function node( string $type, array $properties, array $children = [] ): array {
		return [
			'type' => $type, 'provider' => self::PROVIDER,
			'properties' => $properties, 'children' => $children,
		];
	}
	private static function string_schema( int $max ): array {
		return [ 'type' => 'string', 'maxLength' => $max ];
	}
	private static function object_schema( array $properties ): array {
		return [
			'type' => 'object', 'properties' => $properties,
			'required' => array_keys( $properties ), 'additionalProperties' => false,
		];
	}
	private function __construct() {}
}
