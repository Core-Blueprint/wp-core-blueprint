<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use InvalidArgumentException;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Request-local TOTP provisioning metadata for authenticator applications.
 *
 * This class only composes the standard otpauth URI. It does not persist,
 * transmit or log the secret and deliberately has no QR rendering concerns.
 *
 * @internal
 * @package Core_Blueprint
 * @since   1.0.0
 */
final class Provisioning {

	private const ISSUER = 'Core Blueprint';

	public static function uri( WP_User $user, string $secret ): string {
		$secret = strtoupper( trim( $secret ) );
		if ( $user->ID <= 0 || 1 !== preg_match( '/^[A-Z2-7]+$/', $secret ) ) {
			throw new InvalidArgumentException( 'Invalid TOTP provisioning material.' );
		}

		$account = self::account_label( $user );
		$label   = rawurlencode( self::ISSUER ) . ':' . rawurlencode( $account );
		$query   = http_build_query(
			[
				'secret'    => $secret,
				'issuer'    => self::ISSUER,
				'algorithm' => 'SHA1',
				'digits'    => Totp::DIGITS,
				'period'    => Totp::PERIOD,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return 'otpauth://totp/' . $label . '?' . $query;
	}

	private static function account_label( WP_User $user ): string {
		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$site_name = preg_replace( '/\s+/u', ' ', trim( $site_name ) ) ?? '';
		if ( '' === $site_name ) {
			$host      = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$site_name = '' !== $host ? $host : 'WordPress';
		}

		$login = trim( (string) $user->user_login );
		if ( '' === $login ) {
			$login = 'user-' . (int) $user->ID;
		}

		return sprintf( '%s (%s)', $site_name, $login );
	}
}
