<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Optional resource-bearing export. Asset-free templates keep the existing
 * Data Exchange JSON export. This source remains owner of media admissions,
 * template revision, licensing and resource byte reads.
 */
interface TemplateResourceExportSourceInterface extends TemplateSourceInterface {
	/** Registered Data Exchange entity identifier, never client-supplied. */
	public function resource_export_entity(): string;

	/** Revalidate one exact template/version for the current actor. */
	public function can_export_resource_package( string $template_id, string $version, int $user_id ): bool;

	/**
	 * Return the domain-owned canonical Engine export context.
	 * Must include templateId, version and actorId matching this request.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function resource_export_context( string $template_id, string $version, int $user_id ): array|WP_Error;

	/**
	 * Supply local bytes only after domain authorization and rights checks.
	 * No URLs, attachment IDs, untrusted original filenames or network reads.
	 *
	 * @param array{key:string,media_type:string,size:int,sha256:string} $resource
	 */
	public function read_export_resource(
		string $template_id, string $version, array $resource, int $user_id
	): string|WP_Error;
}
