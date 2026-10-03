<?php
declare(strict_types=1);
/**
 * Anonymizer - privacy helpers for audit logging.
 *
 * Central authority on how to handle personal data (IP addresses) before
 * storage. Every Audit Log write runs IPs through anonymize_ip() so we
 * never store unanonymized data when the user's policy forbids it.
 *
 * Mode values (stored in cb_core_privacy option, key 'ip_mode'):
 *   - 'anonymized' (default)
 *       IPv4: zero the last octet   (77.174.6.28  → 77.174.6.0)
 *       IPv6: zero the last 80 bits (2a02:..:...  → 2a02:c7e:.../48)
 *   - 'full'
 *       Store as-is. Legitimate for forensics but requires explicit
 *       AVG-justification in the processor agreement.
 *   - 'none'
 *       Store empty string. Maximum privacy; loses all forensic value.
 *
 * Matches Google Analytics 4 / Matomo's "IP anonymization" default.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Privacy;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Anonymizer {

	const OPTION_KEY = 'cb_core_privacy';

	const MODE_ANONYMIZED = 'anonymized';
	const MODE_FULL       = 'full';
	const MODE_NONE       = 'none';

	const MODES = [ self::MODE_ANONYMIZED, self::MODE_FULL, self::MODE_NONE ];

	const DEFAULT_MODE = self::MODE_ANONYMIZED;

	/**
	 * Current IP handling mode. Defaults to anonymized.
	 */
	public static function ip_mode(): string {
		$settings = get_option( self::OPTION_KEY, [] );
		$mode     = is_array( $settings ) && isset( $settings['ip_mode'] ) ? (string) $settings['ip_mode'] : self::DEFAULT_MODE;
		return in_array( $mode, self::MODES, true ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Set the IP mode. Logs the change to the audit log (without actually
	 * needing an IP for this event). Returns false on invalid mode.
	 */
	public static function set_ip_mode( string $mode, string $actor = 'system' ): bool {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return false;
		}
		$settings             = get_option( self::OPTION_KEY, [] );
		$settings             = is_array( $settings ) ? $settings : [];
		$previous             = $settings['ip_mode'] ?? self::DEFAULT_MODE;
		$settings['ip_mode']  = $mode;
		$ok                   = update_option( self::OPTION_KEY, $settings, false );

		// Log the change itself - this is security-relevant governance.
		if ( $ok && $previous !== $mode && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'privacy.ip_mode_changed', 'notice', [
				'from'  => $previous,
				'to'    => $mode,
				'actor' => $actor,
			] );
		}
		return $ok;
	}

	/**
	 * Apply the active anonymization mode to an IP string.
	 *
	 * @param string|null $ip Raw IP or null.
	 * @return string Anonymized / passed-through / empty string.
	 */
	public static function anonymize_ip( ?string $ip ): string {
		if ( null === $ip || '' === $ip ) {
			return '';
		}
		$mode = self::ip_mode();
		if ( self::MODE_NONE === $mode ) {
			return '';
		}
		if ( self::MODE_FULL === $mode ) {
			return $ip;
		}
		return self::apply_anonymization( $ip );
	}

	/**
	 * The actual anonymization algorithm - applies regardless of mode.
	 * Extracted so callers who need a guaranteed-anonymized value can
	 * invoke it directly without going through the mode check.
	 *
	 * IPv4: zero last octet.
	 * IPv6: zero the last 80 bits (keeps the /48 prefix, discards the
	 *       interface identifier per RFC 4291 recommendations).
	 */
	public static function apply_anonymization( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		// IPv4
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			if ( 4 === count( $parts ) ) {
				$parts[3] = '0';
				return implode( '.', $parts );
			}
			return '';
		}
		// IPv6
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// Compact → full, keep first 3 hextets (48 bits), zero rest.
			$packed   = inet_pton( $ip );
			if ( false === $packed ) {
				return '';
			}
			$hex      = bin2hex( $packed );
			$prefix   = substr( $hex, 0, 12 ); // 48 bits / 12 hex chars
			$padded   = str_pad( $prefix, 32, '0' );
			$restored = inet_ntop( hex2bin( $padded ) );
			return $restored ?: '';
		}
		// Not a valid IP - discard.
		return '';
	}
}
