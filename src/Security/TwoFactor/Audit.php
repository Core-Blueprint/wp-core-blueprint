<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use CB\Core\Log\AuditLog;
use CB\Core\Security\Failsafe;

defined( 'ABSPATH' ) || exit;

final class Audit {

	public const EVENT_ENROLLMENT_COMPLETED = 'security.twofactor.enrollment.completed';
	public const EVENT_RECOVERY_CODE_USED   = 'security.twofactor.recovery.used';
	public const EVENT_AUTHENTICATED        = 'security.twofactor.authenticated';
	public const EVENT_BYPASS_USED          = 'security.twofactor.bypass.used';
	public const EVENT_MIGRATION_RESET      = 'security.twofactor.migration.reset';

	/** @var array<string,bool> */
	private static array $bypass_logged = [];

	public static function enrollment_completed( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		AuditLog::log( self::EVENT_ENROLLMENT_COMPLETED, 'notice', [ 'user_id' => $user_id ] );
	}

	public static function recovery_code_used( int $user_id, int $remaining ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		AuditLog::log( self::EVENT_RECOVERY_CODE_USED, 'warning', [
			'user_id'   => $user_id,
			'remaining' => max( 0, $remaining ),
		] );
	}

	public static function authenticated( int $user_id, string $method ): void {
		if ( $user_id <= 0 || ! in_array( $method, [ 'totp', 'recovery' ], true ) ) {
			return;
		}
		AuditLog::log( self::EVENT_AUTHENTICATED, 'info', [
			'user_id' => $user_id,
			'method'  => $method,
		] );
	}

	public static function bypass_used( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$source = self::bypass_source();
		$key = $user_id . '|' . $source;
		if ( isset( self::$bypass_logged[ $key ] ) ) {
			return;
		}
		self::$bypass_logged[ $key ] = true;

		AuditLog::log( self::EVENT_BYPASS_USED, 'critical', [
			'user_id' => $user_id,
			'source'  => $source,
		] );
	}

	/** @param array{users:int,meta_records:int,challenge_records:int} $stats */
	public static function migration_reset( array $stats ): void {
		AuditLog::log( self::EVENT_MIGRATION_RESET, 'warning', [
			'users'             => max( 0, (int) ( $stats['users'] ?? 0 ) ),
			'meta_records'      => max( 0, (int) ( $stats['meta_records'] ?? 0 ) ),
			'challenge_records' => max( 0, (int) ( $stats['challenge_records'] ?? 0 ) ),
		] );
	}

	/** @internal */
	public static function reset_request_state(): void {
		self::$bypass_logged = [];
	}

	private static function bypass_source(): string {
		$active = array_keys( array_filter( Failsafe::active_layers() ) );
		return [] === $active ? 'request_scoped' : implode( ',', $active );
	}
}
