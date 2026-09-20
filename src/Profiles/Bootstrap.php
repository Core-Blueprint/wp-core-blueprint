<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

use CB\Core\Admin\PageRegistry;
use CB\Core\Admin\Pages\Profiles as ProfilesPage;
use CB\Core\Governance\EventRegistry;
use CB\Core\Profiles\Admin\Actions;
use CB\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		add_action( 'init', [ self::class, 'register_events' ], 1 );
		add_action( 'cb_core_register_pages', [ self::class, 'register_page' ] );
		if ( RequestContext::is_admin_post() ) {
			Actions::boot();
		}
	}

	public static function register_page(): void {
		PageRegistry::register_base( new ProfilesPage(), [
			'components' => [ 'cards', 'notices', 'state-badges', 'fields', 'actions', 'panels' ],
		] );
	}

	public static function register_events(): void {
		EventRegistry::register_core_many( [
			'profiles.exported'        => __( 'Profiles: configuration exported', 'core-blueprint' ),
			'profiles.previewed'       => __( 'Profiles: import preview created', 'core-blueprint' ),
			'profiles.applied'         => __( 'Profiles: configuration applied', 'core-blueprint' ),
			'profiles.apply_failed'    => __( 'Profiles: apply failed and was rolled back', 'core-blueprint' ),
			'profiles.rollback_failed' => __( 'Profiles: rollback requires attention', 'core-blueprint' ),
		] );
	}
}
