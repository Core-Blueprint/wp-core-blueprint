<?php
declare(strict_types=1);
/**
 * Media Replace subsystem bootstrap.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\MediaReplace;

use CB\Core\Admin\PageRegistry;
use CB\Core\RequestContext;
use CB\Core\MediaReplace\Admin\Page;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function boot(): void {
		Capabilities::init();
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );

		if ( RequestContext::is_admin_screen() ) {
			// First-party admin page. Using PageRegistry keeps the module inside
			// Core Blueprint's normal submenu, page-title, theme, and asset pipeline.
			add_action( 'cb_core_register_pages', static function (): void {
				if ( ! State::is_enabled() ) {
					return;
				}
				PageRegistry::register_base( new Page() );
			} );
			if ( State::is_enabled() ) {
				AdminIntegration::init_screen();
			}
			return;
		}

		if ( RequestContext::is_ajax() && State::is_enabled() ) {
			AdminIntegration::init_ajax();
			return;
		}

		if ( RequestContext::is_admin_post() && State::is_enabled() ) {
			AdminIntegration::init_admin_post();
		}
	}

	/** Register translation-bearing metadata filters after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		add_filter( 'cb_core_capability_catalog', [ Capabilities::class, 'register_catalog' ] );
		\CB\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/**
	 * @param array<string,string> $labels Existing labels.
	 * @return array<string,string>
	 */
	public static function register_event_labels( array $labels ): array {
		$labels['media.replace.subsystem.enabled'] = __( 'Media Replace: subsystem enabled', 'core-blueprint' );
		$labels['media.replace.subsystem.disabled'] = __( 'Media Replace: subsystem disabled', 'core-blueprint' );
		$labels['media.file.replaced']          = __( 'Media: file replaced', 'core-blueprint' );
		$labels['media.replace.acknowledged']     = __( 'Media Replace: backup and recovery responsibility acknowledged', 'core-blueprint' );
		$labels['media.replace.failed']          = __( 'Media: replacement failed', 'core-blueprint' );
		$labels['media.post.replace.hook.failed'] = __( 'Media: post-replace hook failed', 'core-blueprint' );
		return $labels;
	}
}
