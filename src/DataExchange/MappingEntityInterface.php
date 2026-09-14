<?php
declare(strict_types=1);
/**
 * Optional Data Mapper schema contract for one Data Exchange entity.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface MappingEntityInterface extends EntityInterface {

	/**
	 * Return the extension-owned field schema for one supported entity schema.
	 *
	 * Base normalizes this declarative shape for Data Mapper presentation and
	 * matching. Field meaning, validation and mutation remain extension-owned.
	 *
	 * Each field may contain:
	 * - id: stable field identifier.
	 * - label: human-readable label.
	 * - type: string|integer|number|boolean|date|datetime|enum|reference|json.
	 * - required: whether a mapped import target is required.
	 * - readable: whether the field may be used as an export/source field.
	 * - writable: whether the field may be used as an import/target field.
	 * - aliases: optional source/target names used for deterministic auto-match.
	 * - description: optional human-readable context.
	 *
	 * @return list<array<string,mixed>>|WP_Error
	 */
	public function mapping_fields( int $schema_version ): array|WP_Error;
}
