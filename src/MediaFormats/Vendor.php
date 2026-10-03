<?php
declare(strict_types=1);
/**
 * Isolated loader for the vendored SVG sanitizer dependency.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats;

defined( 'ABSPATH' ) || exit;

final class Vendor {
	private static bool $registered = false;

	public static function register_svg_sanitizer(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$prefix = 'CoreBlueprint\\Core\\MediaFormats\\Vendor\\SvgSanitize\\';
		$base   = __DIR__ . '/lib/svg-sanitizer/src/';

		spl_autoload_register( static function ( string $class ) use ( $prefix, $base ): void {
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			if ( false === $relative || '' === $relative || str_contains( $relative, '..' ) ) {
				return;
			}
			$file = $base . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		} );
	}
}
