<?php
declare(strict_types=1);

final class CB_Base_Public_API_Smoke_Test extends WP_UnitTestCase {

    public function test_documented_v1_facades_exist(): void {
        $contracts = [
            \CoreBlueprint\Core\ExtensionRegistry::class => [ 'register', 'snapshot', 'get', 'identity', 'is_first_party' ],
            \CoreBlueprint\Core\Modules\ActivationRegistry::class => [ 'definitions', 'is_enabled', 'slugs' ],
            \CoreBlueprint\Core\Modules\Status::class => [ 'get' ],
            \CoreBlueprint\Core\Admin\PageRegistry::class => [ 'register', 'hook_suffix' ],
            \CoreBlueprint\Core\Admin\SettingsRegistry::class => [ 'register', 'all', 'get', 'url' ],
            \CoreBlueprint\Core\UI\AdminTheme::class => [ 'register_screen', 'is_registered_screen' ],
            \CoreBlueprint\Core\UI\PrimaryNav::class => [ 'render' ],
            \CoreBlueprint\Core\UI\SectionNav::class => [ 'render' ],
            \CoreBlueprint\Core\UI\Tile::class => [ 'render' ],
            \CoreBlueprint\Core\UI\Assets::class => [ 'enqueue_admin_navigation', 'enqueue_tiles' ],
            \CoreBlueprint\Core\UI\IntegrationGrid::class => [ 'render' ],
            \CoreBlueprint\Core\UI\DetailRows::class => [ 'render' ],
            \CoreBlueprint\Core\Governance\Audit::class => [ 'record' ],
            \CoreBlueprint\Core\Governance\EventRegistry::class => [ 'register' ],
            \CoreBlueprint\Core\Governance\RetentionPolicy::class => [ 'days', 'all', 'category_for_event' ],
            \CoreBlueprint\Core\Governance\RetentionStoreRegistry::class => [ 'register' ],
            \CoreBlueprint\Core\Database\SchemaRegistry::class => [ 'register' ],
            \CoreBlueprint\Core\ContentModels\Api::class => [],
            \CoreBlueprint\Core\Design\Profile\Document\Flow\Api\FlowRenderApi::class => [ 'preview_html', 'pdf', 'is_pdf_available' ],
        ];

        foreach ( $contracts as $class => $methods ) {
            self::assertTrue( class_exists( $class ), $class );
            foreach ( $methods as $method ) {
                self::assertTrue( method_exists( $class, $method ), $class . '::' . $method );
            }
        }
    }
}
