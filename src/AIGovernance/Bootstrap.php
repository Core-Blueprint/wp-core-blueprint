<?php
declare(strict_types=1);
/**
 * AI Governance subsystem bootstrap.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\AIGovernance;

use CoreBlueprint\Core\Admin\Pages\Logs\TabRegistry;
use CoreBlueprint\Core\Admin\Pages\Logs\Tabs\AIActivityTab;
use CoreBlueprint\Core\AIGovernance\Admin\Actions;
use CoreBlueprint\Core\Governance\EventRegistry;
use CoreBlueprint\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'plugins_loaded', [ Repository::class, 'register_schema' ], 4 );
		add_action( 'init', [ Repository::class, 'register_retention_store' ], 2 );
		add_action( 'init', [ __CLASS__, 'register_event_metadata' ], 5 );
		add_action( 'core_blueprint_logs_register_tabs', [ __CLASS__, 'register_log_tab' ] );

		AbilityObserver::boot();
		AIClientObserver::boot();
		MCPIntegration::boot();

		if ( RequestContext::is_admin_post() ) {
			Actions::boot();
		}
	}

	public static function register_log_tab(): void {
		TabRegistry::register( AIActivityTab::SLUG, [
			'label'    => __( 'AI Activity', 'core-blueprint' ),
			'priority' => 50,
			'renderer' => [ AIActivityTab::class, 'render' ],
		] );
	}

	public static function register_event_metadata(): void {
		EventRegistry::register_core( [
			'id'                 => 'ai.activity.exported',
			'label'              => __( 'AI Governance: activity exported', 'core-blueprint' ),
			'retention_category' => 'security',
		] );
		EventRegistry::register_core( [
			'id'                 => 'ai.retention.updated',
			'label'              => __( 'AI Governance: retention updated', 'core-blueprint' ),
			'retention_category' => 'settings',
		] );
	}
}
