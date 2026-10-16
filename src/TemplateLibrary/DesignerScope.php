<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Short-lived, session-bound, server-issued Designer identity.
 * The browser may submit this opaque token, but may never choose scope strings.
 */
final class DesignerScope {
	private const TTL = 900;

	public static function issue( string $extension, string $designer ): ?string {
		if ( ! is_admin() || ! is_user_logged_in()
			|| ! TemplateQuery::valid_id( $extension ) || ! TemplateQuery::valid_id( $designer )
			|| ! self::authorized( $extension, $designer ) ) {
			return null;
		}
		$payload = [
			'v' => 1, 'user' => get_current_user_id(),
			'session' => hash( 'sha256', wp_get_session_token() ),
			'extension' => $extension, 'designer' => $designer,
			'expires' => time() + self::TTL,
		];
		$encoded = self::encode( $payload );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}

	/** @return array{extension:string,designer:string}|null */
	public static function verify( string $token ): ?array {
		if ( ! is_user_logged_in() || strlen( $token ) > 1024
			|| ! preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $token ) ) {
			return null;
		}
		[ $encoded, $mac ] = explode( '.', $token, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ), $mac ) ) {
			return null;
		}
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );
		if ( false === $decoded ) {
			return null;
		}
		try {
			$data = json_decode( $decoded, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return null;
		}
		if ( ! is_array( $data )
			|| 1 !== ( $data['v'] ?? null )
			|| get_current_user_id() !== ( $data['user'] ?? null )
			|| ! hash_equals( hash( 'sha256', wp_get_session_token() ), (string) ( $data['session'] ?? '' ) )
			|| ! is_int( $data['expires'] ?? null ) || $data['expires'] < time()
			|| ! is_string( $data['extension'] ?? null ) || ! TemplateQuery::valid_id( $data['extension'] )
			|| ! is_string( $data['designer'] ?? null ) || ! TemplateQuery::valid_id( $data['designer'] )
			|| ! self::authorized( $data['extension'], $data['designer'] ) ) {
			return null;
		}
		return [ 'extension' => $data['extension'], 'designer' => $data['designer'] ];
	}

	private static function authorized( string $extension, string $designer ): bool {
		foreach ( TemplateSourceRegistry::sources() as $source ) {
			if ( $source['extension'] === $extension && $source['designer'] === $designer ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,int|string> $data */
	private static function encode( array $data ): string {
		return rtrim( strtr( base64_encode( json_encode( $data, JSON_THROW_ON_ERROR ) ), '+/', '-_' ), '=' );
	}
}
