<?php
declare(strict_types=1);

use CB\Core\HUD\Bootstrap;

final class CB_Base_HUD_Update_Boundary_Test extends WP_UnitTestCase {

	public function test_hud_runtime_never_primes_update_cache_and_keeps_item_registration_behind_runtime_boot(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/HUD/Bootstrap.php' );
		self::assertIsString( $source );

		self::assertFalse( method_exists( Bootstrap::class, 'prime_update_cache' ) );
		self::assertFalse( has_action( 'init', [ Bootstrap::class, 'prime_update_cache' ] ) );
		self::assertStringContainsString(
			"add_action( 'init', [ self::class, 'register_items' ], 10 );",
			$source,
			'HUD item registration must remain part of enabled HUD runtime boot.'
		);
		self::assertStringContainsString(
			'if ( ! Settings::is_enabled() )',
			$source,
			'Disabled HUD runtime must still short-circuit before presentation hooks are registered.'
		);
	}

	public function test_hud_bootstrap_does_not_touch_wordpress_update_transient_storage(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/HUD/Bootstrap.php' );
		self::assertIsString( $source );

		foreach ( [
			'_site_transient_update_plugins',
			'_site_transient_update_themes',
			'_site_transient_update_core',
			'wp_prime_site_option_caches',
		] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}
}
