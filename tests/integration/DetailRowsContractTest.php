<?php
declare(strict_types=1);

final class CB_Base_Detail_Rows_Test_Page implements \CoreBlueprint\Core\Admin\Page {
    public function slug(): string {
        return 'cb-detail-rows-test';
    }

    public function title(): string {
        return 'Detail Rows Test';
    }

    public function menu_title(): string {
        return 'Detail Rows Test';
    }

    public function capability(): string {
        return 'manage_options';
    }

    public function position(): ?int {
        return 151;
    }

    public function render(): void {
    }
}

final class CB_Base_Detail_Rows_Contract_Test extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        \CoreBlueprint\Core\Admin\PageRegistry::_reset_for_testing();
    }

    public function tear_down(): void {
        \CoreBlueprint\Core\Admin\PageRegistry::_reset_for_testing();
        parent::tear_down();
    }

    public function test_renderer_supports_all_existing_status_semantics_and_multiple_rows(): void {
        $html = \CoreBlueprint\Core\UI\DetailRows::render( [
            [ 'name' => 'Alpha', 'status' => 'active', 'status_label' => 'Active' ],
            [ 'name' => 'Beta', 'status' => 'ready', 'status_label' => 'Ready' ],
            [ 'name' => 'Gamma', 'status' => 'warning', 'status_label' => 'Warning' ],
            [ 'name' => 'Delta', 'status' => 'error', 'status_label' => 'Error' ],
            [ 'name' => 'Epsilon', 'status' => 'idle', 'status_label' => 'Idle' ],
        ] );

        self::assertStringContainsString( 'cb-core-detail-rows', $html );
        self::assertSame( 5, substr_count( $html, 'class="cb-core-detail-row"' ) );
        self::assertStringContainsString( 'cb-core-status__dot--success', $html );
        self::assertSame( 2, substr_count( $html, 'cb-core-status__dot--warning' ) );
        self::assertStringContainsString( 'cb-core-status__dot--danger', $html );
        self::assertStringContainsString( 'cb-core-status__dot--muted', $html );
    }

    public function test_status_is_optional_and_invalid_status_metadata_is_not_coerced(): void {
        $html = \CoreBlueprint\Core\UI\DetailRows::render( [
            [ 'name' => 'No status', 'description' => 'Still a valid row.' ],
            [ 'name' => 'Unknown status', 'status' => 'mystery', 'status_label' => 'Mystery' ],
            [ 'name' => 'Missing label', 'status' => 'active' ],
        ] );

        self::assertSame( 3, substr_count( $html, 'class="cb-core-detail-row"' ) );
        self::assertStringContainsString( 'Still a valid row.', $html );
        self::assertStringNotContainsString( 'cb-core-detail-row__status', $html );
        self::assertStringNotContainsString( 'Mystery', $html );
    }

    public function test_renderer_escapes_content_and_only_emits_complete_cta(): void {
        $html = \CoreBlueprint\Core\UI\DetailRows::render( [
            [
                'name'         => '<Template>',
                'description'  => '<script>alert(1)</script>',
                'status'       => 'active',
                'status_label' => '<Ready>',
                'action_url'   => 'https://example.test/wp-admin/admin.php?page=alpha&tab=<unsafe>',
                'action_label' => '<Edit>',
            ],
            [
                'name'       => 'Incomplete CTA',
                'action_url' => 'https://example.test/configure',
            ],
        ] );

        self::assertStringNotContainsString( '<script>', $html );
        self::assertStringContainsString( '&lt;Template&gt;', $html );
        self::assertStringContainsString( '&lt;Ready&gt;', $html );
        self::assertStringContainsString( '&lt;Edit&gt;', $html );
        self::assertSame( 1, substr_count( $html, 'cb-core-detail-row__action' ) );
        self::assertStringNotContainsString( 'https://example.test/configure"', $html );
    }

    public function test_invalid_rows_fail_closed_and_empty_input_returns_empty_string(): void {
        self::assertSame( '', \CoreBlueprint\Core\UI\DetailRows::render( [] ) );
        self::assertSame( '', \CoreBlueprint\Core\UI\DetailRows::render( [
            [ 'name' => '' ],
            [ 'description' => 'Missing name' ],
            'not-an-item',
        ] ) );
    }

    public function test_detail_rows_compose_inside_existing_card_without_owning_card_context(): void {
        $rows = \CoreBlueprint\Core\UI\DetailRows::render( [
            [
                'name'         => 'Course template',
                'description'  => 'Assigned template',
                'status'       => 'active',
                'status_label' => 'Ready',
                'action_url'   => 'https://example.test/edit',
                'action_label' => 'Edit',
            ],
        ] );
        $html = \CoreBlueprint\Core\UI\Card::render( [
            'title' => 'Template setup',
            'body'  => $rows . '<p class="consumer-guidance">Consumer-owned guidance</p>',
        ] );

        self::assertStringContainsString( 'cb-core-card', $html );
        self::assertStringContainsString( 'cb-core-detail-rows', $html );
        self::assertStringContainsString( 'consumer-guidance', $html );
        self::assertStringNotContainsString( 'cb-core-integration-grid', $html );
    }

    public function test_page_registry_accepts_single_detail_rows_requirement(): void {
        self::assertTrue( \CoreBlueprint\Core\Admin\PageRegistry::register(
            new CB_Base_Detail_Rows_Test_Page(),
            [ 'components' => [ 'detail-rows' ] ]
        ) );
    }

    public function test_detail_rows_requirement_owns_internal_asset_dependencies(): void {
        $context = \CoreBlueprint\Core\Admin\ScreenContext::from_request( 'core-blueprint_page_cb-detail-rows-test' );

        \CoreBlueprint\Core\Admin\AdminAssetCatalog::enqueue_component_requirement( 'detail-rows', $context );

        self::assertTrue( wp_style_is( 'cb-core-css-detail-rows', 'enqueued' ) );
        self::assertTrue( wp_style_is( 'cb-core-css-status-indicators', 'enqueued' ) );
        self::assertTrue( wp_style_is( 'cb-core-css-buttons', 'enqueued' ) );
    }

    public function test_detail_rows_css_uses_base_tokens_and_responsive_stack_boundary(): void {
        $css = file_get_contents( CB_CORE_DIR . 'assets/css/components/detail-rows.css' );

        self::assertIsString( $css );
        self::assertStringContainsString( '@media (max-width: 782px)', $css );
        self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr);', $css );
        self::assertStringContainsString( 'var(--cb-border)', $css );
        self::assertStringContainsString( 'var(--cb-text-strong)', $css );
        self::assertStringContainsString( 'var(--cb-interactive-focus)', $css );
        self::assertStringNotContainsString( '#fff', strtolower( $css ) );
        self::assertStringNotContainsString( '#000', strtolower( $css ) );
    }
}
