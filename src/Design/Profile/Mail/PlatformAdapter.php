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

/** First-party native base/mail profile; one stable v1 registry and Save authority. */
final class PlatformAdapter {
	public const PROVIDER = Contract::CORE_PROVIDER;
	public const TYPE = Contract::DESIGN_TYPE;
	public const PROFILE = 'base/mail';
	public const SCHEMA = 'base/mail/1';
	public const MODULE = '@cb-core/mail-platform';
	public const PATH = 'assets/js/design/mail/platform-adapter.js';

	public static function declare_type( DesignTypeRegistry $types, CapabilityRegistry $caps, AuthorizationRegistry $grants ): void {
		if ( ! $types->register( self::TYPE, self::PROVIDER )
			|| ! $caps->register( self::PROVIDER, 'mail.edit', CapabilityClass::Data )
			|| ! $grants->grant_consumer( self::TYPE, self::PROVIDER, 'mail.edit' ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
	}

	public static function register(
		DesignTypeRegistry $types, ProfileRegistry $profiles, ComponentRegistry $components,
		ControlRegistry $controls, ModuleCatalog $modules, array $artifact
	): void {
		$types_snapshot = array_map( static fn( array $type ): array => [
			'id' => $type['id'], 'ownerProvider' => $type['owner_provider'],
			'allowSitePresentational' => $type['allow_site_presentational'],
		], $types->definitions() );
		$node_types = [];
		$domains = [];
		$definitions = Contract::definitions();
		foreach ( $definitions as $type => $definition ) {
			$schema_id = $type . '.props';
			$node_types[] = [
				'id' => $type, 'provider' => self::PROVIDER,
				'allowedParents' => $definition['parents'], 'propertiesSchemaId' => $schema_id,
			];
			$domains[] = [ 'id' => $schema_id, 'ownerProvider' => self::PROVIDER,
				'schema' => [ 'type' => 'object', 'properties' => $definition['properties'],
					'required' => array_keys( $definition['properties'] ), 'additionalProperties' => false ] ];
		}
		usort( $node_types, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );
		usort( $domains, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );
		$snapshot = [
			'contractVersion' => 1, 'schemaGeneration' => 1,
			'profileId' => self::PROFILE, 'projectSchema' => self::SCHEMA,
			'providers' => [ self::PROVIDER ], 'designTypes' => $types_snapshot,
			'nodeTypes' => $node_types, 'domainSchemas' => $domains,
		];
		$fields = [
			[ 'contractVersion' => 1, 'id' => 'mail.preheader', 'saveKey' => 'preheader',
				'labelKey' => 'Mail preheader', 'valueSchema' => [ 'type' => 'string', 'maxLength' => 500 ],
				'placement' => 'settings', 'requiredCapability' => 'mail.edit' ],
			[ 'contractVersion' => 1, 'id' => 'mail.subject', 'saveKey' => 'subject',
				'labelKey' => 'Mail subject', 'valueSchema' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
				'placement' => 'header', 'requiredCapability' => 'mail.edit' ],
		];
		if ( ! $modules->register( [
			'id' => self::MODULE, 'owner' => self::PROVIDER,
			'dependencies' => [], 'exports' => [
				'authorizeCommand', 'createRenderAdapter', 'layerPolicyFor', 'resolveCommand',
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
		foreach ( $definitions as $type => $definition ) {
			if ( self::TYPE === $type || 'mail.column' === $type ) continue;
			$id = 'base/' . str_replace( '.', '-', $type );
			if ( ! $components->register( [
				'id' => $id, 'owner' => self::PROVIDER, 'profileId' => self::PROFILE,
				'moduleId' => self::MODULE, 'schemaId' => $type . '.props', 'requires' => [],
			] ) || ! $controls->register( [
				'id' => $id . '-primary', 'owner' => self::PROVIDER, 'profileId' => self::PROFILE,
				'componentId' => $id, 'moduleId' => self::MODULE, 'requires' => [],
			] ) ) throw new \DomainException( 'REGISTRY_INVALID' );
		}
	}

	public static function default_project(): array { return Contract::default_document()['project']; }
	public static function default_fields(): array { return Contract::default_document()['fields']; }

	/**
	 * Resource-specific defaults. The trusted context loader must call this for
	 * missing base.mail-template rows; an invalid resource MUST NOT get a generic
	 * "Untitled email" document.
	 */
	public static function default_for_resource( string $resource_id ): ?array {
		return \CoreBlueprint\Core\Mail\Designer\WordPressTemplates::default_document( $resource_id );
	}

	private function __construct() {}
}
