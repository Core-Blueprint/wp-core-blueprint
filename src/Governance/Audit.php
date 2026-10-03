<?php
declare(strict_types=1);
/**
 * Public Governance/Audit write facade.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\Governance;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Audit {
	/** @param array<string|int,mixed> $context */
	public static function record( string $event_id, string $severity = 'info', array $context = [] ): bool {
		if ( ! EventRegistry::is_public_id( $event_id ) || ! EventRegistry::claim( $event_id ) ) {
			return false;
		}
		if ( ! in_array( $severity, AuditLog::SEVERITIES, true ) ) {
			return false;
		}
		try {
			$result = AuditLog::log( $event_id, $severity, ContextSanitizer::sanitize( $context ) );
			return false !== $result;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}
}
