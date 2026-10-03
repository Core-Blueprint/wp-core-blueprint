<?php
declare(strict_types=1);
/**
 * WordPress-native environment governance.
 *
 * WordPress environment identity remains canonical through
 * wp_get_environment_type(). Core Blueprint stores only portable policy and
 * never persists, infers, or rewrites the machine-local environment type.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Environment;

use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Governance {

	public const POLICY_KEY = 'environment_governance';
	public const PROTECT_SEARCH_INDEXING = 'protect_search_indexing';

	/** @return array{protect_search_indexing:bool} */
	public static function default_policy(): array {
		return [
			self::PROTECT_SEARCH_INDEXING => true,
		];
	}

	/**
	 * Register non-production search-indexing safeguards.
	 *
	 * Production is deliberately a no-op: no robots or response-header filters
	 * are registered. Environment identity is read only from WordPress.
	 */
	public static function boot(): void {
		if ( is_admin() ) {
			Admin::boot();
		}

		if ( ! self::should_protect_search_indexing() ) {
			return;
		}

		add_filter( 'wp_robots', [ self::class, 'filter_robots' ], 100 );
		add_filter( 'wp_headers', [ self::class, 'filter_headers' ], 100 );
	}

	public static function current_type(): string {
		return wp_get_environment_type();
	}

	public static function is_non_production(): bool {
		return 'production' !== self::current_type();
	}

	/** @return array{protect_search_indexing:bool} */
	public static function policy(): array {
		$defaults = self::default_policy();
		$settings = Settings::get();
		$stored   = $settings[ self::POLICY_KEY ] ?? [];

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$value = $stored[ self::PROTECT_SEARCH_INDEXING ] ?? $defaults[ self::PROTECT_SEARCH_INDEXING ];
		return [
			self::PROTECT_SEARCH_INDEXING => is_bool( $value )
				? $value
				: $defaults[ self::PROTECT_SEARCH_INDEXING ],
		];
	}

	public static function should_protect_search_indexing(): bool {
		if ( ! self::is_non_production() ) {
			return false;
		}

		return self::policy()[ self::PROTECT_SEARCH_INDEXING ];
	}

	/**
	 * Add only the noindex directive. Existing WordPress/plugin directives stay
	 * untouched; in particular this policy does not add follow/nofollow.
	 *
	 * @param array<string,mixed> $robots
	 * @return array<string,mixed>
	 */
	public static function filter_robots( array $robots ): array {
		if ( ! self::should_protect_search_indexing() ) {
			return $robots;
		}

		$robots['noindex'] = true;
		return $robots;
	}

	/**
	 * Add noindex to an existing X-Robots-Tag value without replacing other
	 * directives. Header-name and directive matching are case-insensitive.
	 *
	 * @param array<string,mixed> $headers
	 * @return array<string,mixed>
	 */
	public static function filter_headers( array $headers ): array {
		if ( ! self::should_protect_search_indexing() ) {
			return $headers;
		}

		$x_robots_keys = [];
		foreach ( $headers as $name => $value ) {
			if ( 0 !== strcasecmp( (string) $name, 'X-Robots-Tag' ) ) {
				continue;
			}

			$x_robots_keys[] = (string) $name;
			if ( is_scalar( $value ) && 1 === preg_match( '/\\bnoindex\\b/i', (string) $value ) ) {
				return $headers;
			}
		}

		if ( [] === $x_robots_keys ) {
			$headers['X-Robots-Tag'] = 'noindex';
			return $headers;
		}

		$key = $x_robots_keys[0];
		$value = $headers[ $key ];
		if ( ! is_scalar( $value ) ) {
			return $headers;
		}

		$value = trim( (string) $value );
		$headers[ $key ] = '' === $value
			? 'noindex'
			: rtrim( $value, " \t\n\r\0\x0B," ) . ', noindex';

		return $headers;
	}
}
