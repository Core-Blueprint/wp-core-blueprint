<?php
declare(strict_types=1);
/**
 * Admin Notices Governance bootstrap.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\AdminNotices;

use CoreBlueprint\Core\Governance\EventRegistry;
use CoreBlueprint\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function boot(): void {
		if ( RequestContext::is_admin_screen() ) {
			Runtime::boot();
		}

		if ( RequestContext::is_admin_post() || RequestContext::is_ajax() ) {
			Admin::boot();
		}

		add_action( 'init', [ __CLASS__, 'register_event_labels' ], 1 );
	}

	public static function register_event_labels(): void {
		EventRegistry::register_core_many( [
			'ui.admin.notices.policy.changed' => __( 'Admin Notices: audience policy changed', 'core-blueprint' ),
		] );
	}

	private function __construct() {}
}
