<?php
declare(strict_types=1);

final class CB_Base_Multisite_Activation_Boundary_Contract_Test extends WP_UnitTestCase {

	public function test_multisite_activation_is_blocked_before_base_state_initialization(): void {
		$core = (string) file_get_contents( CB_CORE_DIR . 'src/Core.php' );

		$activate_start = strpos( $core, 'public static function activate( bool $network_wide = false ): void {' );
		$deactivate_start = strpos( $core, 'public static function deactivate(): void {' );

		self::assertNotFalse( $activate_start, 'Activation boundary not found.' );
		self::assertNotFalse( $deactivate_start, 'Deactivation boundary not found.' );

		$activation = substr( $core, $activate_start, $deactivate_start - $activate_start );
		$guard = strpos( $activation, 'if ( is_multisite() ) {' );
		$first_state_read = strpos( $activation, "get_option( 'cb_core_first_activated_at', false )" );

		self::assertNotFalse( $guard, 'Multisite activation guard is missing.' );
		self::assertNotFalse( $first_state_read, 'Expected first activation state read is missing.' );
		self::assertLessThan(
			$first_state_read,
			$guard,
			'Multisite must fail closed before Base reads or writes activation state.'
		);
		self::assertStringContainsString( 'wp_die(', $activation );
		self::assertStringContainsString(
			'Core Blueprint 1.0 does not support WordPress Multisite.',
			$activation
		);
	}

	public function test_wordpress_org_readme_declares_multisite_boundary(): void {
		$readme = (string) file_get_contents( CB_CORE_DIR . 'readme.txt' );

		self::assertStringContainsString(
			'WordPress Multisite is not supported in Core Blueprint 1.0.',
			$readme
		);
		self::assertStringContainsString(
			'Activation is blocked on Multisite installations, including both per-site and network activation.',
			$readme
		);
	}
}
