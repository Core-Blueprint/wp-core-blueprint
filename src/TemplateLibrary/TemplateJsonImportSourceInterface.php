<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Optional v1 JSON-import capability for a registered template source.
 *
 * A source is a provider-owned writer. The global Library and scoped Browser
 * discover only capability metadata and forward bounded, version-fenced
 * preview/apply requests. No source can import another source's payload.
 * ZIP resource import is an independent, separately authorized capability.
 */
interface TemplateJsonImportSourceInterface {
	/** A namespaced create-only ID prefix, for example "mail.saved.". */
	public function json_import_destination_prefix(): string;

	/** Explicit actor admission; registration alone is not import authority. */
	public function can_import_json( int $user_id ): bool;

	/**
	 * Validate a provider-owned file and create-only destination without writes.
	 * Return only metadata: status=ready, preview={valid,record_count,counts,
	 * fingerprint,...} or status=invalid|refused|unsupported.
	 *
	 * @return array<string,mixed>
	 */
	public function preview_json_import( string $file, string $destination_id, int $user_id ): array;

	/** A single explicitly confirmed create; unknown is not safe to retry. */
	public function apply_json_import(
		string $file, string $destination_id, string $fingerprint, int $user_id
	): array;
}
