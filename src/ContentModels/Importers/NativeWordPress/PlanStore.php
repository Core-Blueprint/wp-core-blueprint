<?php
declare(strict_types=1);
/**
 * User-scoped short-lived discovery/import-plan storage.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Importers\NativeWordPress;

use CoreBlueprint\Core\ContentModels\Repository;

defined( 'ABSPATH' ) || exit;

final class PlanStore {
	private const DISCOVERY_TTL = 15 * MINUTE_IN_SECONDS;
	private const PLAN_TTL      = 20 * MINUTE_IN_SECONDS;
	private const PLAN_VERSION  = 1;

	/** @param array<string,mixed> $snapshot */
	public static function save_discovery( array $snapshot ): void {
		$payload = [
			'user_id'    => get_current_user_id(),
			'created_at' => time(),
			'expires_at' => time() + self::DISCOVERY_TTL,
			'snapshot'   => $snapshot,
		];
		$payload['digest'] = self::sign( $payload );
		set_transient( self::discovery_key(), $payload, self::DISCOVERY_TTL );
	}

	/** @return array<string,mixed>|null */
	public static function discovery(): ?array {
		$payload = get_transient( self::discovery_key() );
		if ( ! self::valid_payload( $payload, false ) || ! is_array( $payload['snapshot'] ?? null ) ) {
			return null;
		}
		return $payload['snapshot'];
	}

	/** @param array<string,mixed> $plan @return array<string,mixed> */
	public static function save_plan( array $plan ): array {
		$payload = array_merge( $plan, [
			'plan_version'      => self::PLAN_VERSION,
			'plan_id'           => (string) ( $plan['plan_id'] ?? wp_generate_uuid4() ),
			'user_id'           => get_current_user_id(),
			'created_at'        => time(),
			'expires_at'        => time() + self::PLAN_TTL,
			'repository_digest' => self::repository_digest(),
		] );
		$payload['digest'] = self::sign( $payload );
		set_transient( self::plan_key(), $payload, self::PLAN_TTL );
		return $payload;
	}

	/** @return array<string,mixed>|null */
	public static function plan(): ?array {
		$payload = get_transient( self::plan_key() );
		if ( ! self::valid_payload( $payload, true ) ) {
			return null;
		}
		return $payload;
	}

	public static function repository_unchanged( array $plan ): bool {
		return isset( $plan['repository_digest'] ) && hash_equals( (string) $plan['repository_digest'], self::repository_digest() );
	}

	public static function clear(): void {
		delete_transient( self::discovery_key() );
		delete_transient( self::plan_key() );
	}

	public static function clear_plan(): void {
		delete_transient( self::plan_key() );
	}

	private static function discovery_key(): string {
		return 'cb_cm_native_discovery_' . get_current_user_id();
	}

	private static function plan_key(): string {
		return 'cb_cm_native_plan_' . get_current_user_id();
	}

	/** @param mixed $payload */
	private static function valid_payload( $payload, bool $plan ): bool {
		if ( ! is_array( $payload ) || ! isset( $payload['digest'], $payload['user_id'], $payload['expires_at'] ) ) {
			return false;
		}
		if ( (int) $payload['user_id'] !== get_current_user_id() || (int) $payload['expires_at'] < time() ) {
			return false;
		}
		if ( $plan && self::PLAN_VERSION !== (int) ( $payload['plan_version'] ?? 0 ) ) {
			return false;
		}
		$digest = (string) $payload['digest'];
		$unsigned = $payload;
		unset( $unsigned['digest'] );
		return '' !== $digest && hash_equals( $digest, self::sign( $unsigned ) );
	}

	/** @param array<string,mixed> $payload */
	private static function sign( array $payload ): string {
		return hash_hmac( 'sha256', self::encode( $payload ), wp_salt( 'nonce' ) );
	}

	private static function repository_digest(): string {
		return hash( 'sha256', self::encode( Repository::all() ) );
	}

	/** @param mixed $value */
	private static function encode( $value ): string {
		return (string) wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** @param mixed $value @return mixed */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ __CLASS__, 'canonicalize' ], $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
