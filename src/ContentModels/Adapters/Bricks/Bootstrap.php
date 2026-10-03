<?php
declare(strict_types=1);
/**
 * Conditional Bricks adapter bootstrap.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Adapters\Bricks;

use CoreBlueprint\Core\ContentModels\State;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	private static bool $registered = false;

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 20 );
	}

	public static function register(): void {
		if ( self::$registered || ! State::is_enabled() || ( ! defined( 'BRICKS_VERSION' ) && ! class_exists( '\\Bricks\\Helpers' ) ) ) {
			return;
		}
		self::$registered = true;
		add_filter( 'bricks/dynamic_tags_list', [ DynamicData::class, 'register_tags' ] );
		add_filter( 'bricks/dynamic_data/render_tag', [ DynamicData::class, 'render_tag' ], 10, 3 );
		add_filter( 'bricks/dynamic_data/render_content', [ DynamicData::class, 'render_content' ], 10, 3 );
		add_filter( 'bricks/frontend/render_data', [ DynamicData::class, 'render_content' ], 10, 3 );
	}
}
