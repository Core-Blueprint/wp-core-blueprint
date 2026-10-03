<?php
declare(strict_types=1);

final class Gate3ArchitectureBoundaryTest extends WP_UnitTestCase {

	public function test_access_mode_public_facade_remains_available(): void {
		$this->assertTrue( method_exists( \CoreBlueprint\Core\Security\AccessMode::class, 'current' ) );
		$this->assertTrue( method_exists( \CoreBlueprint\Core\Security\AccessMode::class, 'config' ) );
		$this->assertTrue( method_exists( \CoreBlueprint\Core\Security\AccessMode::class, 'is_admin_only' ) );
		$this->assertTrue( method_exists( \CoreBlueprint\Core\Security\AccessMode::class, 'register_bypass' ) );
		$this->assertTrue( method_exists( \CoreBlueprint\Core\Security\AccessMode::class, 'should_bypass_request' ) );
		$this->assertTrue( method_exists( \CoreBlueprint\Core\Security\AccessMode::class, 'picker_selected_page' ) );
	}

	public function test_access_mode_runtime_no_longer_owns_admin_transport(): void {
		$runtime = file_get_contents( CB_CORE_DIR . 'src/Security/AccessMode.php' );
		$admin   = file_get_contents( CB_CORE_DIR . 'src/Security/AccessModeAdmin.php' );
		$state   = file_get_contents( CB_CORE_DIR . 'src/Security/AccessModeState.php' );

		$this->assertIsString( $runtime );
		$this->assertIsString( $admin );
		$this->assertIsString( $state );

		$this->assertStringContainsString( 'AccessModeAdmin::boot();', $runtime );
		$this->assertStringContainsString( 'AccessModeState::current()', $runtime );
		$this->assertStringContainsString( 'AccessModeState::config()', $runtime );
		$this->assertStringNotContainsString( 'wp_ajax_cb_core_set_access_mode', $runtime );
		$this->assertStringNotContainsString( 'Request::nonce', $runtime );
		$this->assertStringNotContainsString( 'AuditLog::log', $runtime );
		$this->assertStringNotContainsString( 'WP_Admin_Bar', $runtime );

		$this->assertStringContainsString( 'wp_ajax_cb_core_set_access_mode', $admin );
		$this->assertStringContainsString( 'Request::nonce', $admin );
		$this->assertStringContainsString( 'AuditLog::log', $admin );
		$this->assertStringContainsString( 'admin_bar_menu', $admin );

		$this->assertStringContainsString( 'get_option( AccessMode::OPTION_KEY', $state );
		$this->assertStringContainsString( 'get_option( AccessMode::CONFIG_OPTION_KEY', $state );
		$this->assertStringContainsString( 'persist_mode', $state );
		$this->assertStringContainsString( 'persist_config', $state );
	}
}
