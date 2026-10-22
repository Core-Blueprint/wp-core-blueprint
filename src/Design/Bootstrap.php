<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design;

use CoreBlueprint\Core\Database\SchemaRegistry;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Integrity\ArtifactEvidence;
use CoreBlueprint\Core\Design\Integrity\ApprovedManifestSource;
use CoreBlueprint\Core\Design\Profile\Mail\PlatformAdapter as MailPlatform;
use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter as ReportsPlatform;
use CoreBlueprint\Core\Design\Kernel\AuthorizationRegistry;
use CoreBlueprint\Core\Design\Kernel\CapabilityRegistry;
use CoreBlueprint\Core\Design\Kernel\DesignTypeRegistry;
use CoreBlueprint\Core\Design\Kernel\ProviderRegistry;
use CoreBlueprint\Core\Design\Persistence\OperationSchema;
use CoreBlueprint\Core\Design\Profile\Document\Bootstrap as DocumentBootstrap;
use CoreBlueprint\Core\Design\Registry\ComponentRegistry;
use CoreBlueprint\Core\Design\Registry\ContextBootstrap;
use CoreBlueprint\Core\Design\Registry\ControlRegistry;
use CoreBlueprint\Core\Design\Registry\FrozenRegistry;
use CoreBlueprint\Core\Design\Registry\ModuleCatalog;
use CoreBlueprint\Core\Design\Registry\ProfileRegistry;
use CoreBlueprint\Core\Design\Registry\RegistryCompiler;

defined( 'ABSPATH' ) || exit;

/**
 * The ONE WordPress entry for trusted, server-side Designer registration,
 * installed artifact verification and authorized context minting.
 */
final class Bootstrap {
	public static function boot(): void {
		DocumentBootstrap::boot();
		\CoreBlueprint\Core\Design\Editor\PlatformTransport::boot();
	}

	/** Register Base's operation ledger before the existing DB schema sweep. */
	public static function register_schema(): void {
		if ( ! SchemaRegistry::register_base( [
			'id' => 'design-save-operations',
			'version' => OperationSchema::VERSION,
			'option_key' => 'cb_core_design_operations_db_version',
			'tables' => [ [ OperationSchema::class, 'table_name' ] ],
			'install' => static function (): bool {
				// DDL runs exclusively inside Base's canonical migration owner.
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( OperationSchema::create_table_sql() );
				return OperationSchema::healthy();
			},
		] ) ) {
			throw new \LogicException( 'SAVE_SCHEMA_UNAVAILABLE' );
		}
	}

	/**
	 * Compile one request-scoped FrozenRegistry from trusted installed PHP.
	 * Consumer callbacks may register data-only descriptors during this hook.
	 * There is no browser registration or second registry authority.
	 */
	public static function frozen_registry(): FrozenRegistry {
		$providers = new ProviderRegistry();
		$types = new DesignTypeRegistry( $providers );
		$capabilities = new CapabilityRegistry( $providers );
		$authorizations = new AuthorizationRegistry( $providers, $types, $capabilities );
		$profiles = new ProfileRegistry();
		$components = new ComponentRegistry();
		$controls = new ControlRegistry();
		$modules = new ModuleCatalog();
		// Only independently approved installed artifacts can declare trusted
		// executable profile modules. No empty or dummy registry is published.
		$artifact = ApprovedManifestSource::base();
		if ( ! $providers->register( MailPlatform::PROVIDER ) ) {
			throw new \DomainException( 'REGISTRY_COLLISION' );
		}
		MailPlatform::declare_type( $types, $capabilities, $authorizations );
		ReportsPlatform::declare_type( $types, $capabilities, $authorizations );
		MailPlatform::register( $types, $profiles, $components, $controls, $modules, $artifact );
		ReportsPlatform::register( $types, $profiles, $components, $controls, $modules, $artifact );
		// Third-party installed packages require a separate Base+consumer
		// attestation; the first Base-only release does not grant that scope.
		// A later explicit integration may provide additional trusted sources.
		return ( new RegistryCompiler( $providers, $types, $capabilities, $authorizations ) )
			->compile( $profiles, $components, $controls, $modules );
	}

	/**
	 * Mint context only after WordPress, domain and every required installed
	 * artifact authority has accepted the same actor/site/resource/profile.
	 *
	 * All approved roots, manifest digests, registered capability lists and
	 * the resource-authorizer must come from installed PHP configuration.
	 * Only the nonce can originate from the request. The caller must first
	 * resolve the resource type/key and authorization policy on the server.
	 *
	 * @param array<string,array<string,string>> $approved
	 * @param array{base:list<string>,consumer:list<string>} $capabilities
	 * @param callable(array<string,mixed>,array<string,mixed>):bool $authorize
	 */
	public static function mint_context(
		FrozenRegistry $registry,
		string $profile_id,
		string $resource_type,
		string $resource_key,
		int $context_epoch,
		string $nonce,
		?string $consumer_installed_root,
		array $approved,
		array $capabilities,
		callable $authorize
	): array {
		$profile = $registry->profile( $profile_id );
		$site = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( null === $profile || $site < 1 || $actor < 1 || $context_epoch < 0
			|| ! Repository::valid_identity( $resource_type, $resource_key )
			|| ! function_exists( 'current_user_can' )
			|| ! \CoreBlueprint\Core\Design\Editor\PlatformTransport::authorized_resource( $profile_id, $resource_type, $resource_key )
			|| ! function_exists( 'wp_verify_nonce' )
			|| false === wp_verify_nonce( $nonce, 'cb_designer_' . $profile_id . '_' . $resource_type . '_' . $resource_key )
			|| ( null !== $consumer_installed_root && ! is_dir( $consumer_installed_root ) ) ) {
			throw new \DomainException( 'HOST_CONTEXT_REFUSED' );
		}
		$identity = [
			'siteId' => $site,
			'actorId' => $actor,
			'resourceType' => $resource_type,
			'resourceId' => $resource_key,
			'contextEpoch' => $context_epoch,
		];
		if ( ! $authorize( $identity, $profile ) ) {
			throw new \DomainException( 'HOST_CONTEXT_REFUSED' );
		}
		$scope = [
			'authorized' => true,
			'siteBlogId' => $site,
			'actorId' => $actor,
			'resourceType' => $resource_type,
			'resourceKey' => $resource_key,
			'profileId' => $profile_id,
			'registryFingerprint' => $registry->fingerprint(),
		];
		$verified = ArtifactEvidence::collect(
			CB_CORE_DIR, $consumer_installed_root, $approved, $capabilities, $scope, $registry
		);
		if ( true !== ( $verified['valid'] ?? false ) ) {
			throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		return ( new ContextBootstrap() )->mint(
			$registry, $profile_id, $identity, $verified['evidence'], $authorize
		);
	}

	/** Dependency presence is neither authorization nor runtime readiness. */
	public static function assert_platform_dependencies_present(): void {
		foreach ( [
			ContextBootstrap::class,
			RegistryCompiler::class,
			ModuleCatalog::class,
			\CoreBlueprint\Core\Design\Integrity\InstalledVerifier::class,
			\CoreBlueprint\Core\Design\Persistence\TransactionalSave::class,
		] as $dependency ) {
			if ( ! class_exists( $dependency ) ) {
				throw new \LogicException( 'Designer Platform dependency not integrated: ' . $dependency );
			}
		}
	}
}
