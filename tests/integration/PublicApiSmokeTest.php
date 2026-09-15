<?php
declare(strict_types=1);

final class CB_Base_Public_API_Smoke_Test extends WP_UnitTestCase {

    public function test_documented_v1_facades_exist(): void {
        $contracts = [
            \CB\Core\ExtensionRegistry::class => [ 'register', 'snapshot', 'get', 'identity', 'is_first_party' ],
            \CB\Core\Modules\ActivationRegistry::class => [ 'definitions', 'is_enabled', 'slugs' ],
            \CB\Core\Modules\Status::class => [ 'get' ],
            \CB\Core\Admin\PageRegistry::class => [ 'register', 'hook_suffix' ],
            \CB\Core\Admin\SettingsRegistry::class => [ 'register', 'all', 'get', 'url' ],
            \CB\Core\UI\IntegrationGrid::class => [ 'render' ],
            \CB\Core\UI\DetailRows::class => [ 'render' ],
            \CB\Core\Governance\Audit::class => [ 'record' ],
            \CB\Core\Governance\EventRegistry::class => [ 'register' ],
            \CB\Core\Governance\RetentionPolicy::class => [ 'days', 'all', 'category_for_event' ],
            \CB\Core\Governance\RetentionStoreRegistry::class => [ 'register' ],
            \CB\Core\Database\SchemaRegistry::class => [ 'register' ],
            \CB\Core\ContentModels\Api::class => [],
            \CB\Core\Design\Profile\Document\Flow\Api\FlowRenderApi::class => [ 'preview_html', 'pdf', 'is_pdf_available' ],
        ];

        foreach ( $contracts as $class => $methods ) {
            self::assertTrue( class_exists( $class ), $class );
            foreach ( $methods as $method ) {
                self::assertTrue( method_exists( $class, $method ), $class . '::' . $method );
            }
        }
    }
}
