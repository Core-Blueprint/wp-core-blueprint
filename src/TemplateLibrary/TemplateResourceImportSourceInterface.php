<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, server-declared import destination for the global Template Library.
 *
 * This is discoverability, NEVER an authorization token. Import is still
 * admitted by the canonical Data Exchange entity and ResourcePackageImport
 * on each preview and apply with the signed actor-bound staged ZIP.
 */
interface TemplateResourceImportSourceInterface extends TemplateSourceInterface {
	/** One stable, provider-owned destination identifier (not a path or URL). */
	public function resource_import_destination(): string;

	/** Recheck the current actor before advertising a destination. */
	public function can_import_resource_package( int $user_id ): bool;
}
