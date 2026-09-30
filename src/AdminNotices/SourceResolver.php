<?php
declare(strict_types=1);
/**
 * Resolve one WordPress callback to a bounded notice source identity.
 *
 * Identity is intentionally source-level rather than notice-HTML-level.
 * Runtime governance never parses or rewrites arbitrary third-party markup.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\AdminNotices;

defined( 'ABSPATH' ) || exit;

final class SourceResolver {

	public const KIND_WORDPRESS = 'wordpress';
	public const KIND_PLUGIN    = 'plugin';
	public const KIND_MU_PLUGIN = 'mu-plugin';
	public const KIND_THEME     = 'theme';
	public const KIND_UNKNOWN   = 'unknown';

	/** @var null|callable */
	private static $testing_resolver = null;

	/**
	 * @return array{id:string,label:string,kind:string,manageable:bool,protected:bool,callback:string}
	 */
	public static function resolve( mixed $callback ): array {
		if ( is_callable( self::$testing_resolver ) ) {
			$result = ( self::$testing_resolver )( $callback );
			if ( is_array( $result ) ) {
				return self::normalize_testing_result( $result, $callback );
			}
		}

		$file = self::callback_file( $callback );
		$label = self::callback_label( $callback );

		if ( is_string( $file ) && '' !== $file ) {
			$file = wp_normalize_path( $file );

			$relative = self::relative_to( $file, defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' );
			if ( null !== $relative ) {
				$slug = self::source_slug( $relative );
				return self::known( 'mu-plugin:' . $slug, $slug, self::KIND_MU_PLUGIN, $label );
			}

			$relative = self::relative_to( $file, defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' );
			if ( null !== $relative ) {
				$slug = self::source_slug( $relative );
				return self::known( 'plugin:' . $slug, $slug, self::KIND_PLUGIN, $label );
			}

			$theme_root = function_exists( 'get_theme_root' ) ? (string) get_theme_root() : '';
			$relative = self::relative_to( $file, $theme_root );
			if ( null !== $relative ) {
				$slug = self::source_slug( $relative );
				return self::known( 'theme:' . $slug, $slug, self::KIND_THEME, $label );
			}

			$content_root = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
			if ( null === self::relative_to( $file, $content_root ) && null !== self::relative_to( $file, ABSPATH ) ) {
				return [
					'id'         => 'wordpress:core',
					'label'      => 'WordPress',
					'kind'       => self::KIND_WORDPRESS,
					'manageable' => true,
					'protected'  => true,
					'callback'   => $label,
				];
			}
		}

		$basis = ( is_string( $file ) && '' !== $file ? $file : 'internal' ) . '|' . $label;
		return [
			'id'         => 'unknown:' . substr( hash( 'sha256', $basis ), 0, 16 ),
			'label'      => 'Unknown source',
			'kind'       => self::KIND_UNKNOWN,
			'manageable' => false,
			'protected'  => true,
			'callback'   => $label,
		];
	}

	public static function is_manageable_id( mixed $source_id ): bool {
		if ( ! is_string( $source_id ) || strlen( $source_id ) > 191 ) {
			return false;
		}
		if ( 'wordpress:core' === $source_id ) {
			return true;
		}
		return 1 === preg_match( '/^(?:plugin|mu-plugin|theme):[a-z0-9][a-z0-9_-]{0,119}$/', $source_id );
	}

	public static function is_protected_id( string $source_id ): bool {
		return 'wordpress:core' === $source_id || 'plugin:core-blueprint' === $source_id;
	}

	/** @internal Integration tests only. */
	public static function _set_resolver_for_testing( ?callable $resolver ): void {
		self::$testing_resolver = $resolver;
	}

	private static function known( string $id, string $slug, string $kind, string $callback ): array {
		return [
			'id'         => $id,
			'label'      => self::humanize( $slug ),
			'kind'       => $kind,
			'manageable' => true,
			'protected'  => self::is_protected_id( $id ),
			'callback'   => $callback,
		];
	}

	private static function callback_file( mixed $callback ): ?string {
		try {
			if ( $callback instanceof \Closure ) {
				$file = ( new \ReflectionFunction( $callback ) )->getFileName();
				return is_string( $file ) ? $file : null;
			}
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$file = ( new \ReflectionFunction( $callback ) )->getFileName();
				return is_string( $file ) ? $file : null;
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$file = ( new \ReflectionMethod( $callback[0], (string) $callback[1] ) )->getFileName();
				return is_string( $file ) ? $file : null;
			}
			if ( is_object( $callback ) && is_callable( $callback ) ) {
				$file = ( new \ReflectionMethod( $callback, '__invoke' ) )->getFileName();
				return is_string( $file ) ? $file : null;
			}
		} catch ( \ReflectionException ) {
			return null;
		}
		return null;
	}

	private static function callback_label( mixed $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $class . '::' . (string) $callback[1];
		}
		if ( $callback instanceof \Closure ) {
			try {
				$reflection = new \ReflectionFunction( $callback );
				return 'Closure@' . basename( (string) $reflection->getFileName() ) . ':' . $reflection->getStartLine();
			} catch ( \ReflectionException ) {
				return 'Closure';
			}
		}
		if ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}
		return 'Unknown callback';
	}

	private static function relative_to( string $file, string $root ): ?string {
		if ( '' === $root ) {
			return null;
		}
		$root = trailingslashit( wp_normalize_path( $root ) );
		$file = wp_normalize_path( $file );
		if ( ! str_starts_with( $file, $root ) ) {
			return null;
		}
		$relative = ltrim( substr( $file, strlen( $root ) ), '/' );
		return '' === $relative ? null : $relative;
	}

	private static function source_slug( string $relative ): string {
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		$parts = explode( '/', $relative );
		$slug = 1 < count( $parts )
			? (string) $parts[0]
			: (string) pathinfo( $parts[0], PATHINFO_FILENAME );
		$slug = strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $slug ) ?? '' );
		$slug = trim( $slug, '-' );
		return '' !== $slug ? substr( $slug, 0, 120 ) : 'unknown';
	}

	private static function humanize( string $slug ): string {
		return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	private static function normalize_testing_result( array $result, mixed $callback ): array {
		$id = (string) ( $result['id'] ?? '' );
		return [
			'id'         => $id,
			'label'      => (string) ( $result['label'] ?? $id ),
			'kind'       => (string) ( $result['kind'] ?? self::KIND_UNKNOWN ),
			'manageable' => ! empty( $result['manageable'] ),
			'protected'  => ! empty( $result['protected'] ),
			'callback'   => (string) ( $result['callback'] ?? self::callback_label( $callback ) ),
		];
	}

	private function __construct() {}
}
