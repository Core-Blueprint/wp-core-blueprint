<?php
declare(strict_types=1);
/**
 * Package Downloads subsystem bootstrap.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\PackageDownload;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\RequestContext;
use CoreBlueprint\Core\PackageDownload\Admin\Page;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );

		if ( RequestContext::is_admin_screen() ) {
			add_action( 'core_blueprint_register_pages', static function (): void {
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

		if ( RequestContext::is_admin_post() && State::is_enabled() ) {
			AdminIntegration::init_admin_post();
		}
	}

	/** Register translation-bearing metadata filters after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CoreBlueprint\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/**
	 * @param array<string,string> $labels Existing labels.
	 * @return array<string,string>
	 */
	public static function register_event_labels( array $labels ): array {
		$labels['package.download.subsystem.enabled'] = __( 'Package Downloads: subsystem enabled', 'core-blueprint' );
		$labels['package.download.subsystem.disabled'] = __( 'Package Downloads: subsystem disabled', 'core-blueprint' );
		$labels['package.plugin.downloaded'] = __( 'Packages: plugin downloaded', 'core-blueprint' );
		$labels['package.theme.downloaded']  = __( 'Packages: theme downloaded', 'core-blueprint' );
		$labels['package.download.failed']   = __( 'Packages: download failed', 'core-blueprint' );
		return $labels;
	}
}
