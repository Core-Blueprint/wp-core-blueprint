<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

use CoreBlueprint\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

/**
 * Short-lived, session-bound, server-issued Designer identity.
 * The browser may submit this opaque token, but may never choose scope strings.
 */
final class DesignerScope {
	private const TTL = 900;
	private const MAX_TOKEN_LENGTH = 2048;

	public static function issue( string $source_id, string $resource_type, string $resource_key ): ?string {
		$user = get_current_user_id();
		$session = self::valid_session( $user );
		$site = (int) get_current_blog_id();
		if ( ! RequestContext::is_admin_screen() || null === $session || $site < 1
			|| ! TemplateQuery::valid_id( $source_id )
			|| ! self::valid_resource( $resource_type, $resource_key ) ) {
			return null;
		}
		$scope = TemplateSourceRegistry::authorized_designer_scope( $source_id, $resource_type, $resource_key, true );
		if ( null === $scope ) {
			return null;
		}
		$payload = [
			'v' => 2, 'site' => $site, 'user' => $user,
			'session' => hash( 'sha256', $session ),
			'source' => $source_id, 'extension' => $scope['extension'],
			'designer' => $scope['designer'], 'resource_type' => $resource_type,
			'resource_key' => $resource_key, 'expires' => time() + self::TTL,
		];
		$encoded = self::encode( $payload );
		$token = $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		return strlen( $token ) <= self::MAX_TOKEN_LENGTH ? $token : null;
	}

	/** @return array{extension:string,designer:string}|null */
	public static function verify( string $token, ?string $expected_type = null, ?string $expected_key = null ): ?array {
		$user = get_current_user_id();
		$session = self::valid_session( $user );
		if ( null === $session || strlen( $token ) > self::MAX_TOKEN_LENGTH
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
		if ( ! is_array( $data ) || 2 !== ( $data['v'] ?? null )
			|| (int) get_current_blog_id() !== ( $data['site'] ?? null )
			|| $user !== ( $data['user'] ?? null )
			|| ! is_string( $data['session'] ?? null )
			|| ! hash_equals( hash( 'sha256', $session ), $data['session'] )
			|| ! is_int( $data['expires'] ?? null ) || $data['expires'] < time()
			|| ! is_string( $data['source'] ?? null ) || ! TemplateQuery::valid_id( $data['source'] )
			|| ! is_string( $data['extension'] ?? null ) || ! TemplateQuery::valid_id( $data['extension'] )
			|| ! is_string( $data['designer'] ?? null ) || ! TemplateQuery::valid_id( $data['designer'] )
			|| ! is_string( $data['resource_type'] ?? null ) || ! is_string( $data['resource_key'] ?? null )
			|| ! self::valid_resource( $data['resource_type'], $data['resource_key'] ) ) {
			return null;
		}
		// A document Apply must be bound to the exact existing Designer target.
		if ( ( null !== $expected_type && ! hash_equals( $data['resource_type'], $expected_type ) )
			|| ( null !== $expected_key && ! hash_equals( $data['resource_key'], $expected_key ) ) ) {
			return null;
		}
		$scope = TemplateSourceRegistry::authorized_designer_scope(
			$data['source'], $data['resource_type'], $data['resource_key'], false
		);
		if ( null === $scope || ! hash_equals( $scope['extension'], $data['extension'] )
			|| ! hash_equals( $scope['designer'], $data['designer'] ) ) {
			return null;
		}
		return $scope;
	}

	private static function valid_resource( string $type, string $key ): bool {
		return TemplateQuery::valid_id( $type ) && '' !== $key && strlen( $key ) <= 191
			&& trim( $key ) === $key && 1 === preg_match( '//u', $key )
			&& ! preg_match( '/[\x00-\x1f\x7f]/', $key );
	}

	private static function valid_session( int $user ): ?string {
		if ( $user < 1 || ! is_user_logged_in() || ! class_exists( \WP_Session_Tokens::class ) ) {
			return null;
		}
		$token = wp_get_session_token();
		if ( ! is_string( $token ) || '' === $token ) {
			return null;
		}
		try {
			return \WP_Session_Tokens::get_instance( $user )->verify( $token ) ? $token : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/** @param array<string,int|string> $data */
	private static function encode( array $data ): string {
		return rtrim( strtr( base64_encode( json_encode( $data, JSON_THROW_ON_ERROR ) ), '+/', '-_' ), '=' );
	}
}
