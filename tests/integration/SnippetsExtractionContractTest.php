<?php
declare(strict_types=1);

final class CB_Base_Snippets_Extraction_Contract_Test extends WP_UnitTestCase {

	public function test_base_no_longer_ships_executable_snippets_runtime_or_assets(): void {
		self::assertDirectoryDoesNotExist( CB_CORE_DIR . 'src/Snippets' );
		self::assertFileDoesNotExist( CB_CORE_DIR . 'assets/css/pages/snippets.css' );
		self::assertFileDoesNotExist( CB_CORE_DIR . 'assets/js/features/snippets.js' );

		$core = (string) file_get_contents( CB_CORE_DIR . 'src/Core.php' );
		$dashboard = (string) file_get_contents( CB_CORE_DIR . 'src/Admin/Pages/Dashboard.php' );
		self::assertStringNotContainsString( 'Core\\Snippets\\Bootstrap', $core );
		self::assertStringNotContainsString( 'managed PHP/CSS/JavaScript/HTML snippets', $core );
		self::assertStringNotContainsString( 'CoreBlueprint\\Core\\Snippets', $dashboard );
		self::assertStringNotContainsString( 'cb_manage_snippets', $dashboard );
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

		$checks = \CoreBlueprint\Core\Setup\Registry::all();
		$sections = \CoreBlueprint\Core\Setup\Registry::sections();
		self::assertCount( 29, $checks );
		self::assertArrayHasKey( 'cms-tools', $sections );
		self::assertCount( 9, $sections['cms-tools'] );
		self::assertArrayNotHasKey( 'snippets', $checks );
	}


	public function test_public_documentation_does_not_claim_snippets_as_base_runtime(): void {
		$readme    = (string) file_get_contents( CB_CORE_DIR . 'readme.txt' );
		$readme_md = (string) file_get_contents( CB_CORE_DIR . 'README.md' );
		$dashboard = (string) file_get_contents( CB_CORE_DIR . 'docs/DASHBOARD-CARD-API.md' );

		self::assertStringNotContainsString( '= Managed Snippets =', $readme );
		self::assertStringNotContainsString( 'managed snippets', strtolower( $readme ) );
		self::assertStringNotContainsString( 'Managed Snippets source files', $readme );
		self::assertStringNotContainsString( 'Managed Snippets source files', $readme_md );
		self::assertStringNotContainsString( "- `snippets`", $dashboard );
	}

	public function test_base_retains_only_cross_extension_governance_compatibility(): void {
		$events        = (string) file_get_contents( CB_CORE_DIR . 'src/Governance/EventRegistry.php' );
		$roles         = (string) file_get_contents( CB_CORE_DIR . 'src/Permissions/Roles.php' );
		$privileged    = (string) file_get_contents( CB_CORE_DIR . 'src/Permissions/PrivilegedAccessPolicy.php' );
		$option_policy = (string) file_get_contents( CB_CORE_DIR . 'src/OptionPolicy.php' );
		$uninstall     = (string) file_get_contents( CB_CORE_DIR . 'uninstall.php' );

		// Historical event identities stay readable in Base audit history.
		self::assertStringContainsString( "'snippet.created'", $events );
		self::assertStringContainsString( "'snippets.imported'", $events );

		// The executable-code capability stays inside Base's signed trust model.
		self::assertStringContainsString( "'cb_manage_snippets'", $roles );
		self::assertStringContainsString( "'cb_manage_snippets'", $privileged );

		// Snippets owns its own settings option after extraction. Base must not
		// prime, mutate or otherwise retain runtime ownership of that option.
		self::assertStringNotContainsString( "'cb_core_snippets_settings'", $option_policy );

		// Base may remove its own role capability on uninstall, but no longer owns
		// Snippets settings, transient UI state or generated runtime files.
		self::assertStringContainsString( "'cb_manage_snippets'", $uninstall );
		self::assertStringNotContainsString( "'cb_core_snippets_settings'", $uninstall );
		self::assertStringNotContainsString( "'cb_core_snippets_result_'", $uninstall );
		self::assertStringNotContainsString( "WP_CONTENT_DIR ) . 'cb-snippets'", $uninstall );
	}
}
