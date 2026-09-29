<?php
declare(strict_types=1);
/**
 * Core Setup request wiring.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup;

use CB\Core\Admin\PageRegistry;
use CB\Core\Governance\EventRegistry;
use CB\Core\RequestContext;
use CB\Core\Setup\Admin\Actions;
use CB\Core\Setup\Admin\Page;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register_event_labels' ], 1 );

		if ( RequestContext::is_admin_screen() ) {
			add_action( 'cb_core_register_pages', [ __CLASS__, 'register_admin_page' ] );
		}

		if ( RequestContext::is_admin_post() ) {
			Actions::boot();
		}
	}

	public static function register_admin_page(): void {
		PageRegistry::register_base(
			new Page(),
			[
				'components' => [
					'actions',
					'cards',
					'fields',
					'form-controls',
					'nav-tabs',
					'notices',
					'overview',
					'panels',
					'state-badges',
				],
			]
		);
	}

	public static function register_event_labels(): void {
		EventRegistry::register_core_many( [
			'core.setup.started'        => __( 'Core Setup started', 'core-blueprint' ),
			'core.setup.reviewed'       => __( 'Core Setup check reviewed', 'core-blueprint' ),
			'core.setup.deferred'       => __( 'Core Setup check deferred', 'core-blueprint' ),
			'core.setup.not.applicable' => __( 'Core Setup check marked not applicable', 'core-blueprint' ),
			'core.setup.review.cleared' => __( 'Core Setup review cleared', 'core-blueprint' ),
			'core.setup.note.updated'   => __( 'Core Setup section note updated', 'core-blueprint' ),
		] );
	}

	private function __construct() {}
}
