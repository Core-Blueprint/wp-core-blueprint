<?php
declare(strict_types=1);
/**
 * Admin Columns Governance bootstrap.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\AdminColumns;

use CB\Core\AdminColumns\Admin\Ajax;
use CB\Core\AdminColumns\Admin\ScreenSettings;
use CB\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		static $booted_admin = false;
		static $booted_ajax = false;

		if ( RequestContext::is_ajax() ) {
			if ( ! $booted_ajax ) {
				$booted_ajax = true;
				Ajax::boot();
			}
			return;
		}
		if ( ! RequestContext::is_admin_screen() || $booted_admin ) {
			return;
		}
		$booted_admin = true;
		add_action( 'current_screen', [ self::class, 'on_current_screen' ], 20 );
	}

	public static function on_current_screen( \WP_Screen $screen ): void {
		if ( ! SupportedScreen::is_supported( $screen ) ) {
			return;
		}
		TaxonomyColumns::attach( $screen );
		RegisteredMetaColumns::attach( $screen );
		Runtime::attach( $screen );
		ScreenSettings::attach( $screen );
	}

	private function __construct() {}
}
