<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * Standalone CAS regression seam only.
 *
 * The real PlatformTransport and FrozenRegistry require a WordPress runtime.
 * This narrow fake accepts the canonical native project+fields storage shape,
 * keeping Repository CAS/row/transaction tests executable in isolation.
 * Domain admission is independently covered by Mail/Reports source and
 * installed WordPress tests; this stub is not a production dependency.
 */
final class PlatformTransport {
	public static function valid_native_document( string $resource_type, array $document ): bool {
		if ( ! in_array( $resource_type, [
			'base.mail-template', 'base.maintenance-report-design',
		], true ) || count( $document ) !== 2
			|| ! is_array( $document['project'] ?? null )
			|| ! is_array( $document['fields'] ?? null ) ) {
			return false;
		}
		$project = $document['project'];
		return ( $project['schema_version'] ?? null ) === 1
			&& is_array( $project['root'] ?? null )
			&& is_string( $project['root']['type'] ?? null )
			&& is_array( $project['root']['children'] ?? null );
	}
}
