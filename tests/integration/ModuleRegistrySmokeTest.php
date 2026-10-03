<?php
declare(strict_types=1);

use CoreBlueprint\Core\Modules\ActivationRegistry;
use CoreBlueprint\Core\Modules\ModuleStateInterface;

final class CB_Base_Module_Registry_Smoke_Test extends WP_UnitTestCase {

    public function test_canonical_base_module_set_is_registered(): void {
        $expected = [
            'login-shield',
            'core-shield',
            'core-scanner',
            'content-models',
            'notes',
            'reports',
            'mail',
            'media-replace',
            'media-formats',
            'package-downloads',
            'user-roles',
            'snippets',
        ];

        $actual = ActivationRegistry::slugs();
        sort( $expected );
        sort( $actual );

        self::assertSame( $expected, $actual );
    }

    public function test_every_module_definition_has_a_state_contract_and_capability(): void {
        foreach ( ActivationRegistry::definitions() as $id => $definition ) {
            self::assertTrue( ActivationRegistry::is_valid_id( $id ), $id );
            self::assertArrayHasKey( 'state', $definition );
            self::assertArrayHasKey( 'capability', $definition );
            self::assertTrue( is_subclass_of( $definition['state'], ModuleStateInterface::class ), $id );
            self::assertNotSame( '', $definition['capability'], $id );
            self::assertSame( sanitize_key( $definition['capability'] ), $definition['capability'], $id );
        }
    }
}
