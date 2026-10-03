<?php
declare(strict_types=1);

final class CB_Base_Snippets_Extraction_Contract_Test extends WP_UnitTestCase {

	public function test_base_no_longer_ships_executable_snippets_runtime_or_assets(): void {
		self::assertDirectoryDoesNotExist( CB_CORE_DIR . 'src/Snippets' );
		self::assertFileDoesNotExist( CB_CORE_DIR . 'assets/css/pages/snippets.css' );
		self::assertFileDoesNotExist( CB_CORE_DIR . 'assets/js/features/snippets.js' );

		$core = (string) file_get_contents( CB_CORE_DIR . 'src/Core.php' );
		self::assertStringNotContainsString( 'Core\\Snippets\\Bootstrap', $core );
		self::assertStringNotContainsString( 'managed PHP/CSS/JavaScript/HTML snippets', $core );
	}

	public function test_base_registries_release_snippets_to_the_extension_boundary(): void {
		$activation = (string) file_get_contents( CB_CORE_DIR . 'src/Modules/ActivationRegistry.php' );
		$status     = (string) file_get_contents( CB_CORE_DIR . 'src/Modules/Status.php' );
		$pages      = (string) file_get_contents( CB_CORE_DIR . 'src/Admin/PageRegistry.php' );
		$assets     = (string) file_get_contents( CB_CORE_DIR . 'src/Admin/ScreenAssetRegistry.php' );
		$setup      = (string) file_get_contents( CB_CORE_DIR . 'src/Setup/Registry.php' );

		self::assertStringNotContainsString( "'snippets' =>", $activation );
		self::assertStringNotContainsString( 'SnippetsState::class', $activation );
		self::assertStringNotContainsString( 'SnippetsStatus::class', $status );
		self::assertStringNotContainsString( "'core-blueprint-snippets'", $pages );
		self::assertStringNotContainsString( "'core-blueprint-snippets'", $assets );
		self::assertStringNotContainsString( 'SnippetsCheck', $setup );
	}

	public function test_base_retains_only_cross_extension_governance_compatibility(): void {
		$events     = (string) file_get_contents( CB_CORE_DIR . 'src/Governance/EventRegistry.php' );
		$roles      = (string) file_get_contents( CB_CORE_DIR . 'src/Permissions/Roles.php' );
		$privileged = (string) file_get_contents( CB_CORE_DIR . 'src/Permissions/PrivilegedAccessPolicy.php' );
		$uninstall  = (string) file_get_contents( CB_CORE_DIR . 'uninstall.php' );

		// Historical event identities stay readable in Base audit history.
		self::assertStringContainsString( "'snippet.created'", $events );
		self::assertStringContainsString( "'snippets.imported'", $events );

		// The executable-code capability stays inside Base's signed trust model.
		self::assertStringContainsString( "'cb_manage_snippets'", $roles );
		self::assertStringContainsString( "'cb_manage_snippets'", $privileged );

		// Base may remove its own role capability on uninstall, but no longer owns
		// Snippets settings, transient UI state or generated runtime files.
		self::assertStringContainsString( "'cb_manage_snippets'", $uninstall );
		self::assertStringNotContainsString( "'cb_core_snippets_settings'", $uninstall );
		self::assertStringNotContainsString( "'cb_core_snippets_result_'", $uninstall );
		self::assertStringNotContainsString( "WP_CONTENT_DIR ) . 'cb-snippets'", $uninstall );
	}
}
