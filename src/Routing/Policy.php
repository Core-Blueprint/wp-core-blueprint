<?php
declare(strict_types=1);
/**
 * Canonical URL Governance policy stored inside Base settings.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Routing;

use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Policy {

	public const SETTINGS_KEY       = 'routing';
	public const CLEAN_ARCHIVE_URLS = 'clean_archive_urls';

	/** @return array{clean_archive_urls:bool} */
	public static function defaults(): array {
		return [
			self::CLEAN_ARCHIVE_URLS => false,
		];
	}

	/** @return array{clean_archive_urls:bool} */
	public static function get(): array {
		$settings = Settings::get();
		$stored   = $settings[ self::SETTINGS_KEY ] ?? [];

		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		return [
			self::CLEAN_ARCHIVE_URLS => ! empty( $stored[ self::CLEAN_ARCHIVE_URLS ] ),
		];
	}

	public static function enabled(): bool {
		return true === self::get()[ self::CLEAN_ARCHIVE_URLS ];
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): bool {
		$policy = self::get();
		$policy[ self::CLEAN_ARCHIVE_URLS ] = $enabled;

		$saved = Settings::set_key( self::SETTINGS_KEY, $policy, $actor );
		if ( ! $saved && 'unchanged' !== Settings::last_write_status() ) {
			return false;
		}

		return Settings::matches_paths( [
			self::SETTINGS_KEY . '.' . self::CLEAN_ARCHIVE_URLS => $enabled,
		] );
	}

	private function __construct() {}
}
