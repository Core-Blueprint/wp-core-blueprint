<?php
declare(strict_types=1);

final class Gate3SettingsOwnershipContractTest extends WP_UnitTestCase {

	public function test_settings_defaults_are_owned_by_dedicated_base_schema(): void {
		$settings = file_get_contents( CB_CORE_DIR . 'src/Settings.php' );
		$defaults = file_get_contents( CB_CORE_DIR . 'src/SettingsDefaults.php' );

		$this->assertIsString( $settings );
		$this->assertIsString( $defaults );

		$this->assertStringContainsString( 'return SettingsDefaults::all();', $settings );
		$this->assertStringNotContainsString( 'cb_core_default_settings', $settings );
		$this->assertStringContainsString( "'login_shield'", $defaults );
		$this->assertStringContainsString( "'integrity'", $defaults );
		$this->assertStringContainsString( "'notes'", $defaults );
		$this->assertStringContainsString( "'reports'", $defaults );
		$this->assertStringContainsString( "'permissions'", $defaults );
		$this->assertStringContainsString( "'schema_version' => Settings::SCHEMA_VERSION", $defaults );
	}

	public function test_settings_defaults_public_facade_matches_internal_schema(): void {
		$this->assertSame( \CoreBlueprint\Core\SettingsDefaults::all(), \CoreBlueprint\Core\Settings::defaults() );
	}
}
