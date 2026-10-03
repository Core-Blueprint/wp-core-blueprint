<?php
declare(strict_types=1);

final class CB_Base_Golden_Standard_RC4_Contract_Test extends WP_UnitTestCase {

    public function test_all_shipped_languages_are_selectable(): void {
        self::assertSame(
            [ 'auto', 'en_US', 'nl_NL', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_PT' ],
            \CoreBlueprint\Core\Locale::allowed()
        );

        foreach ( [ 'en_US', 'nl_NL', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_PT' ] as $locale ) {
            self::assertNotSame( '', \CoreBlueprint\Core\Locale::label( $locale ), $locale );
        }
    }

    public function test_unregistered_sibling_pattern_screen_is_not_core_admin_owned(): void {
        $previous_get = $_GET;

        try {
            $_GET = [ 'page' => 'cb-unregistered-test-page' ];
            $context = \CoreBlueprint\Core\Admin\ScreenContext::from_request( CB_CORE_PARENT_MENU . '_page_cb-unregistered-test-page' );

            self::assertSame( '', $context->registered_slug() );
            self::assertFalse( \CoreBlueprint\Core\Admin\ScreenAssetRegistry::owns( $context ) );
            self::assertFalse( \CoreBlueprint\Core\Admin\ScreenAssetRegistry::requires_full_set( $context ) );
        } finally {
            $_GET = $previous_get;
        }
    }

    public function test_split_admin_pages_still_resolve_through_psr4(): void {
        self::assertTrue( class_exists( \CoreBlueprint\Core\ContentModels\Admin\Page::class ) );
        self::assertTrue( class_exists( \CoreBlueprint\Core\Integrity\Admin\Page::class ) );
    }
}
