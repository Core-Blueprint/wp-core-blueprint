<?php
declare(strict_types=1);

use CoreBlueprint\Core\Database\SchemaRegistry;
use CoreBlueprint\Core\ExtensionRegistry;
use CoreBlueprint\Core\Governance\EventRegistry;
use CoreBlueprint\Core\Modules\ActivationRegistry;

final class CB_Base_Fail_Closed_Contract_Test extends WP_UnitTestCase {

    public function test_unknown_or_invalid_module_ids_fail_closed(): void {
        self::assertFalse( ActivationRegistry::is_valid_id( 'Invalid_Module' ) );
        self::assertFalse( ActivationRegistry::is_enabled( 'not-a-real-module' ) );
        self::assertNull( ActivationRegistry::definition( 'not-a-real-module' ) );
    }

    public function test_public_event_ids_reject_base_namespaces_and_malformed_ids(): void {
        self::assertFalse( EventRegistry::is_public_id( 'plugin.changed' ) );
        self::assertFalse( EventRegistry::is_public_id( 'vendor-invalid' ) );
        self::assertTrue( EventRegistry::is_public_id( 'vendor.item.updated' ) );
    }

    public function test_extension_ids_require_namespaced_kebab_case(): void {
        self::assertFalse( ExtensionRegistry::is_valid_id( 'vendor' ) );
        self::assertFalse( ExtensionRegistry::is_valid_id( 'Vendor-Feature' ) );
        self::assertTrue( ExtensionRegistry::is_valid_id( 'vendor-feature' ) );
    }

    public function test_malformed_schema_definition_is_rejected_without_registration(): void {
        self::assertFalse(
            SchemaRegistry::register(
                [
                    'id'         => 'invalid_schema',
                    'version'    => '1.0',
                    'option_key' => 'vendor_schema_version',
                    'tables'     => [],
                    'install'    => static function (): void {},
                ]
            )
        );
    }
}
