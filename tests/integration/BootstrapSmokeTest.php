<?php
declare(strict_types=1);

final class CB_Base_Bootstrap_Smoke_Test extends WP_UnitTestCase {

    public function test_plugin_boots_with_expected_versions(): void {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $data = get_plugin_data( CB_CORE_FILE, false, false );

        self::assertSame( CB_CORE_VERSION, $data['Version'] );
        self::assertSame( '1.1', CB_CORE_API_VERSION );
        self::assertSame( '8.4', CB_CORE_MIN_PHP );
        self::assertSame( 'core-blueprint/core-blueprint.php', CB_CORE_BASENAME );
        self::assertTrue( class_exists( \CB\Core\Core::class ) );
    }

    public function test_bootstrap_requirements_are_satisfied_in_ci(): void {
        self::assertSame( [], cb_core_get_requirement_errors() );
        self::assertTrue( function_exists( 'sodium_crypto_secretbox' ) );
    }

    public function test_core_booted_lifecycle_has_fired(): void {
        self::assertGreaterThanOrEqual( 1, did_action( 'cb_core_booted' ) );
    }
}
