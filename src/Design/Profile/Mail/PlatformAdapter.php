<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Mail;

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
 * First-party Base Mail profile, StableSchemaV1 ONLY.
 * Existing experimental Mail projects are not imported or rewritten.
 */
final class PlatformAdapter {
	public const PROVIDER = 'core.blueprint';
	public const TYPE = 'core.blueprint.mail';
	public const PROFILE = 'base/mail';
	public const SCHEMA = 'base/mail/1';
	public const MODULE = '@cb-core/mail-platform';
	public const PATH = 'assets/js/design/mail/platform-adapter.js';

	public static function declare_type(
		DesignTypeRegistry $types, CapabilityRegistry $caps, AuthorizationRegistry $grants
	): void {
		if ( ! $types->register( self::TYPE, self::PROVIDER )
			|| ! $caps->register( self::PROVIDER, 'mail.edit', CapabilityClass::Data )
			|| ! $grants->grant_consumer( self::TYPE, self::PROVIDER, 'mail.edit' ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
	}

	/**
	 * Register only installed, precisely attested first-party module exports.
	 * Snapshot identities are derived from the ONE finalized PHP type registry.
	 */
	public static function register(
		DesignTypeRegistry $types, ProfileRegistry $profiles,
		ComponentRegistry $components, ControlRegistry $controls,
		ModuleCatalog $modules, array $artifact
	): void {
		$types_snapshot = array_map( static fn( array $t ): array => [
			'id' => $t['id'], 'ownerProvider' => $t['owner_provider'],
			'allowSitePresentational' => $t['allow_site_presentational'],
		], $types->definitions() );
		$property_schemas = [
			self::TYPE => self::object_schema( [ 'background' => self::string_schema( 32 ) ] ),
			'mail.button' => self::object_schema( [
				'label' => self::string_schema( 160 ), 'href' => self::string_schema( 2048 ),
			] ),
			'mail.image' => self::object_schema( [
				'src' => self::string_schema( 2048 ), 'alt' => self::string_schema( 255 ),
			] ),
			'mail.section' => self::object_schema( [ 'title' => self::string_schema( 160 ) ] ),
			'mail.text' => self::object_schema( [ 'text' => self::string_schema( 100000 ) ] ),
		];
		$nodes = [];
		$domains = [];
		foreach ( $property_schemas as $type => $schema ) {
			$id = $type . '.props';
			$parents = match ( $type ) {
				self::TYPE => [],
				'mail.section' => [ self::TYPE, 'mail.section' ],
				default => [ 'mail.section' ],
			};
			$nodes[] = [
				'id' => $type, 'provider' => self::PROVIDER,
				'allowedParents' => $parents, 'propertiesSchemaId' => $id,
			];
			$domains[] = [ 'id' => $id, 'ownerProvider' => self::PROVIDER, 'schema' => $schema ];
		}
		$snapshot = [
			'contractVersion' => 1, 'schemaGeneration' => 1,
			'profileId' => self::PROFILE, 'projectSchema' => self::SCHEMA,
			'providers' => [ self::PROVIDER ], 'designTypes' => $types_snapshot,
			'nodeTypes' => $nodes, 'domainSchemas' => $domains,
		];
		$fields = [
			[
				'contractVersion' => 1, 'id' => 'mail.preheader', 'saveKey' => 'preheader',
				'labelKey' => 'Mail preheader', 'valueSchema' => self::string_schema( 500 ),
				'placement' => 'settings', 'requiredCapability' => 'mail.edit',
			],
			[
				'contractVersion' => 1, 'id' => 'mail.subject', 'saveKey' => 'subject',
				'labelKey' => 'Mail subject', 'valueSchema' => [
					'type' => 'string', 'minLength' => 1, 'maxLength' => 255,
				],
				'placement' => 'header', 'requiredCapability' => 'mail.edit',
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
			'schemaSnapshot' => $snapshot, 'moduleId' => self::MODULE,
			'requiredCapabilities' => [ 'mail.edit' ],
			'persistedFieldDefinitions' => $fields, 'dependencies' => [],
		] ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
		foreach ( [ 'mail.button', 'mail.image', 'mail.section', 'mail.text' ] as $type ) {
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
				'properties' => [ 'background' => '#ffffff' ],
				'children' => [ [
					'type' => 'mail.section', 'provider' => self::PROVIDER,
					'properties' => [ 'title' => 'Content' ],
					'children' => [ [
						'type' => 'mail.text', 'provider' => self::PROVIDER,
						'properties' => [ 'text' => '' ], 'children' => [],
					] ],
				] ],
			],
		];
	}
	public static function default_fields(): array {
		return [ 'preheader' => '', 'subject' => 'Untitled email' ];
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
