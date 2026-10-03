<?php
declare(strict_types=1);
/**
 * Media Formats subsystem bootstrap.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\RequestContext;
use CoreBlueprint\Core\MediaFormats\Admin\Actions;
use CoreBlueprint\Core\MediaFormats\Admin\Page;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );

		if ( State::is_enabled() ) {
			Runtime::boot();
		}

		add_action( 'core_blueprint_register_pages', static function (): void {
			if ( State::is_enabled() ) {
				PageRegistry::register_base( new Page() );
			}
		} );

		if ( RequestContext::is_admin_post() ) {
			Actions::boot();
		}
	}

	public static function register_i18n_filters(): void {
		add_filter( 'core_blueprint_capability_catalog', [ Capabilities::class, 'register_catalog' ] );
		\CoreBlueprint\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/** @param array<string,string> $labels @return array<string,string> */
	public static function register_event_labels( array $labels ): array {
		$labels['media.formats.subsystem.enabled']  = __( 'Media Formats: subsystem enabled', 'core-blueprint' );
		$labels['media.formats.subsystem.disabled'] = __( 'Media Formats: subsystem disabled', 'core-blueprint' );
		$labels['media.formats.settings.changed']   = __( 'Media Formats: settings changed', 'core-blueprint' );
		$labels['media.formats.svg.sanitized']      = __( 'Media Formats: SVG sanitized', 'core-blueprint' );
		$labels['media.formats.svg.rejected']       = __( 'Media Formats: SVG rejected', 'core-blueprint' );
		return $labels;
	}
}
