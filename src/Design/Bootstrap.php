<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design;

use CoreBlueprint\Core\Design\Profile\Document\Bootstrap as DocumentBootstrap;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		DocumentBootstrap::boot();
	}

	/**
	 * Source-only prerequisite check for the later server-trusted platform
	 * bootstrap. Class presence is NOT permission, a frozen registry, verified
	 * ArtifactEvidence or runtime READY. Never called by the legacy boot path.
	 */
	public static function assert_platform_dependencies_present(): void {
		$required = [
			\CoreBlueprint\Core\Design\Registry\ContextBootstrap::class,
			\CoreBlueprint\Core\Design\Registry\RegistryCompiler::class,
			\CoreBlueprint\Core\Design\Registry\ModuleCatalog::class,
			\CoreBlueprint\Core\Design\Integrity\InstalledVerifier::class,
			\CoreBlueprint\Core\Design\Persistence\TransactionalSave::class,
		];
		foreach ( $required as $dependency ) {
			if ( ! class_exists( $dependency ) ) {
				throw new \LogicException( 'Designer Platform dependency not integrated: ' . $dependency );
			}
		}
	}
}
