<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION = 'cb_core_snippets_settings';

	public static function defaults(): array {
		return [
			'enabled' => false,
		];
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		return array_replace( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	public static function save( array $settings ): bool {
		$next = [
			'enabled' => ! empty( $settings['enabled'] ),
		];
		return update_option( self::OPTION, $next, true );
	}
}
