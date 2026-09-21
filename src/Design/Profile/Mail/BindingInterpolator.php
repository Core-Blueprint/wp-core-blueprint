<?php
declare(strict_types=1);
/**
 * Canonical binding interpolation for Design Foundation Mail values.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Design\Profile\Mail;

defined( 'ABSPATH' ) || exit;

final class BindingInterpolator {
	/**
	 * Replace supported {{ binding.key }} tokens with scalar values.
	 *
	 * Unknown or null bindings resolve to an empty string. Unsupported token
	 * syntax is left untouched so callers do not silently broaden the binding
	 * contract.
	 *
	 * @param array<string,scalar|null> $bindings
	 */
	public static function interpolate( string $value, array $bindings ): string {
		$result = preg_replace_callback(
			'/\{\{\s*([a-z][a-z0-9]*(?:[._-][a-z0-9]+)*)\s*\}\}/',
			static function ( array $match ) use ( $bindings ): string {
				$key = (string) ( $match[1] ?? '' );
				$resolved = $bindings[ $key ] ?? '';
				return is_scalar( $resolved ) ? (string) $resolved : '';
			},
			$value
		);

		return is_string( $result ) ? $result : $value;
	}

	private function __construct() {}
}
