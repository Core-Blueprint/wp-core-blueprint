<?php
declare(strict_types=1);
/**
 * Canonical URL Governance policy stored inside Base settings.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Routing;

use CB\Core\Settings;

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

		Settings::set_key( self::SETTINGS_KEY, $policy, $actor );

		return self::enabled() === $enabled;
	}

	private function __construct() {}
}
