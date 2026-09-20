<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

final class PreviewStore {
	private const TTL = 900;
	private const PREFIX = 'cb_core_profile_preview_';

	public static function put( int $user_id, array $document, array $preview ): string {
		if ( $user_id <= 0 ) {
			throw new \RuntimeException( __( 'A signed-in operator is required.', 'core-blueprint' ) );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$key = self::key( $user_id, $token );
		$payload = [
			'document'    => $document,
			'preview'     => $preview,
			'created_at'  => time(),
		];
		if ( ! set_transient( $key, $payload, self::TTL ) ) {
			throw new \RuntimeException( __( 'Core Blueprint could not store the profile preview.', 'core-blueprint' ) );
		}
		return $token;
	}

	public static function get( int $user_id, string $token ): ?array {
		if ( $user_id <= 0 || 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$value = get_transient( self::key( $user_id, $token ) );
		return is_array( $value ) ? $value : null;
	}

	public static function delete( int $user_id, string $token ): void {
		if ( $user_id > 0 && 1 === preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			delete_transient( self::key( $user_id, $token ) );
		}
	}

	private static function key( int $user_id, string $token ): string {
		return self::PREFIX . $user_id . '_' . $token;
	}
}
