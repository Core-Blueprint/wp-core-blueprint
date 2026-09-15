<?php
declare(strict_types=1);
/**
 * Optional CSV mapping contract for Data Exchange entities.
 *
 * CSV transport mechanics stay Base-owned. Extensions only map between their
 * canonical record shape and flat, schema-versioned CSV rows.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface CsvEntityInterface extends EntityInterface {

	/** @return list<string> Stable extension-owned column ids for one schema version. */
	public function csv_columns( int $schema_version ): array;

	/** @return array<string,scalar|null>|WP_Error */
	public function to_csv_row( array $record ): array|WP_Error;

	/** @return array<string,mixed>|WP_Error */
	public function from_csv_row( array $row, int $schema_version ): array|WP_Error;
}
