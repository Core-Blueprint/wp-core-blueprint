<?php
declare(strict_types=1);
/**
 * One-shot rewrite-rule refresh for Content Models schema/state changes.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels;

defined( 'ABSPATH' ) || exit;

final class Rewrite {
	private const OPTION = 'cb_core_content_models_rewrite_dirty';

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'maybe_flush' ], 99 );
	}

	public static function mark_dirty(): void {
		update_option( self::OPTION, '1', false );
	}

	public static function maybe_flush(): void {
		if ( ! get_option( self::OPTION, false ) ) {
			return;
		}

		flush_rewrite_rules( false );
		delete_option( self::OPTION );
	}
}
