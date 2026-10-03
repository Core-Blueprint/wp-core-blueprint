<?php
declare(strict_types=1);
/**
 * AI Governance settings.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const RETENTION_OPTION = 'cb_core_ai_activity_retention_days';
	public const DEFAULT_RETENTION_DAYS = 365;
	public const MAX_RETENTION_DAYS = 3650;

	public static function retention_days(): int {
		$stored = get_option( self::RETENTION_OPTION, null );
		if ( null === $stored || false === $stored || '' === $stored ) {
			return self::DEFAULT_RETENTION_DAYS;
		}
		return min( self::MAX_RETENTION_DAYS, max( 0, (int) $stored ) );
	}

	public static function update_retention_days( int $days ): bool {
		$days = min( self::MAX_RETENTION_DAYS, max( 0, $days ) );
		return update_option( self::RETENTION_OPTION, $days, false );
	}
}
