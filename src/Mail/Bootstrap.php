<?php
declare(strict_types=1);
/**
 * Core Blueprint Mail subsystem bootstrap.
 *
 * Delivery and Designer are independent capabilities under one Mail surface.
 * Base owns the generic Mail Designer; domain extensions register templates,
 * bindings and optional Mail nodes through its public contracts.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\RequestContext;
use CoreBlueprint\Core\Mail\Admin\Actions;
use CoreBlueprint\Core\Mail\Admin\DesignerAjax;
use CoreBlueprint\Core\Mail\Admin\DesignerAssets;
use CoreBlueprint\Core\Mail\Admin\FeatureActions;
use CoreBlueprint\Core\Mail\Admin\LogsTab;
use CoreBlueprint\Core\Mail\Admin\Page;
use CoreBlueprint\Core\Mail\Admin\TemplateActions;
use CoreBlueprint\Core\Mail\Designer\WordPressIntegration;
use CoreBlueprint\Core\Mail\Log\Repository;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function boot(): void {
		// Register the dedicated delivery log before the central DB migration sweep.
		add_action( 'plugins_loaded', [ Repository::class, 'register_schema' ], 4 );
		add_action( 'init', [ Repository::class, 'register_retention_store' ], 2 );

		// Delivery activates after every plugin is loaded so conflict detection
		// sees the complete active-plugin set. Designer never owns transport hooks.
		add_action( 'plugins_loaded', [ Runtime::class, 'boot' ], 30 );

		// WordPress remains authoritative for notification lifecycle. This adapter
		// only replaces presentation when Designer is explicitly enabled.
		add_action( 'init', [ WordPressIntegration::class, 'boot' ], 10 );

		// The Mail configuration surface is always reachable. This is required to
		// enable Designer on sites that intentionally use WordPress/host/third-party
		// delivery instead of Core Blueprint Delivery.
		add_action( 'core_blueprint_register_pages', static function (): void {
			PageRegistry::register_base( new Page(), [
				'components' => [ 'panels', 'state-badges', 'notices' ],
			] );
		} );
		add_action( 'core_blueprint_logs_register_tabs', [ LogsTab::class, 'register' ] );

		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );
		add_action( 'init', [ __CLASS__, 'register_sender_identities' ], 5 );
		add_filter( 'core_blueprint_system_log_event_types', [ __CLASS__, 'register_system_events' ] );

		if ( RequestContext::is_admin_post() ) {
			Actions::boot();
			FeatureActions::boot();
			TemplateActions::boot();
		}
		if ( RequestContext::is_ajax() ) {
			DesignerAjax::boot();
			TemplateActions::boot();
		}
		if ( RequestContext::is_admin_screen() ) {
			add_action( 'admin_enqueue_scripts', [ DesignerAssets::class, 'enqueue' ], 20 );
			add_action( 'admin_notices', [ __CLASS__, 'conflict_notice' ] );
		}
	}

	/** Register translation-bearing metadata after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CoreBlueprint\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/** Public registration lifecycle for extension-owned sender identity slots. */
	public static function register_sender_identities(): void {
		do_action( 'core_blueprint_register_mail_sender_identities' );
	}

	public static function register_event_labels( array $labels ): array {
		$labels['mail.subsystem.enabled']  = __( 'Mail: subsystem enabled', 'core-blueprint' );
		$labels['mail.subsystem.disabled'] = __( 'Mail: subsystem disabled', 'core-blueprint' );
		$labels['mail.delivery.enabled']   = __( 'Mail: delivery enabled', 'core-blueprint' );
		$labels['mail.delivery.disabled']  = __( 'Mail: delivery disabled', 'core-blueprint' );
		$labels['mail.designer.enabled']   = __( 'Mail: designer enabled', 'core-blueprint' );
		$labels['mail.designer.disabled']  = __( 'Mail: designer disabled', 'core-blueprint' );
		$labels['mail.template.saved']     = __( 'Mail: template saved', 'core-blueprint' );
		$labels['mail.template.reset']     = __( 'Mail: template reset', 'core-blueprint' );
		$labels['mail.settings.updated']   = __( 'Mail: settings updated', 'core-blueprint' );
		$labels['mail.test.sent']          = __( 'Mail: test email sent', 'core-blueprint' );
		$labels['mail.log.cleared']        = __( 'Mail: log cleared', 'core-blueprint' );
		return $labels;
	}

	public static function register_system_events( array $types ): array {
		$types['system.mail_failed'] = [
			'description' => 'Mail delivery failed via {provider}: {error_code}',
			'category'    => 'other',
			'severity'    => 'warning',
		];
		return $types;
	}

	public static function conflict_notice(): void {
		if ( ! DeliveryState::is_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$conflicts = ConflictDetector::active();
		if ( empty( $conflicts ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Core Blueprint Mail Delivery is configured but inactive.', 'core-blueprint' ),
			esc_html( sprintf(
				/* translators: %s: comma-separated list of conflicting mail plugins */
				__( 'Another mail transport is active: %s. Core Blueprint will not register transport hooks until the conflict is removed. Mail Designer can continue to work independently.', 'core-blueprint' ),
				implode( ', ', $conflicts )
			) ),
			esc_url( admin_url( 'admin.php?page=' . Page::SLUG . '&tab=settings' ) ),
			esc_html__( 'Review Mail Delivery', 'core-blueprint' )
		);
	}
}
