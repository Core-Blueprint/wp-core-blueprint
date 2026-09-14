<?php
declare(strict_types=1);
/**
 * Runtime contract for one extension-owned Data Exchange entity.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface EntityInterface {

	/** Whether the concrete entity implementation can service requests now. */
	public function is_available(): bool;

	/** Current extension-owned schema version used for new exports. */
	public function schema_version(): int;

	/** Whether this provider can import the given source schema version. */
	public function supports_schema_version( int $schema_version ): bool;

	/** Authorization decision for the current actor and export context. */
	public function can_export( array $context = [] ): bool;

	/** Authorization decision for the current actor and import context. */
	public function can_import( array $context = [] ): bool;

	/**
	 * Return current-schema records for export.
	 *
	 * Records remain extension-owned associative arrays. Base validates only the
	 * transport-safe shape and bounds; it does not interpret business fields.
	 *
	 * @return iterable<array<string,mixed>>|WP_Error
	 */
	public function export_records( array $context = [] ): iterable|WP_Error;

	/**
	 * Produce the canonical import plan for one record without mutating state.
	 *
	 * The returned payload is opaque to Base and is passed back only after the
	 * full import has preflighted successfully and its plan fingerprint matches.
	 *
	 * @return array{operation:string,reference:string,payload:array<string,mixed>,warnings?:list<string>}|WP_Error
	 */
	public function plan_import( array $record, string $mode, int $source_schema_version, array $context = [] ): array|WP_Error;

	/**
	 * Apply one previously recomputed canonical plan through the owning domain.
	 *
	 * Implementations should use their canonical domain service/action and keep
	 * retries safe through stable portable identity where the domain permits it.
	 *
	 * @return array{reference?:string}|WP_Error
	 */
	public function apply_import( array $plan, array $context = [] ): array|WP_Error;
}
