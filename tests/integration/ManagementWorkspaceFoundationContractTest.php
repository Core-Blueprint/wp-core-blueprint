<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\UI\Assets;
use CoreBlueprint\Core\UI\AdminTheme;
use CoreBlueprint\Core\UI\RowActionMenu;

final class CB_Management_Workspace_Foundation_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		foreach ( [
			'cb-core-css-filter-bar',
			'cb-core-css-management-toolbar-native',
			'cb-core-css-management-table',
			'cb-core-css-management-workspace',
			'cb-core-css-row-action-menu',
		] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	public function test_public_semantic_requirements_are_strictly_accepted(): void {
		$expected = [
			'foundations' => [ 'row-action-menu' ],
			'components' => [ 'management-workspace', 'toolbars', 'management-table' ],
		];
		self::assertSame(
			$expected,
			PageRegistry::normalize_semantic_requirements( $expected, 'management-contract' )
		);
		self::assertNull( PageRegistry::normalize_semantic_requirements(
			[ 'components' => [ 'private-management-css-handle' ] ],
			'management-invalid'
		) );
	}

	public function test_native_screen_enqueues_only_selected_management_components(): void {
		$hook = 'cb-management-workspace-test.php';
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );
		AdminTheme::register_screen( $hook, [
			'components' => [ 'management-workspace', 'toolbars', 'management-table' ],
		] );
		AdminTheme::enqueue_registered_screen_requirements( $hook );
		self::assertTrue( wp_style_is( 'cb-core-css-management-workspace', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-management-table', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-management-toolbar-native', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-filter-bar', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
	}

	public function test_status_menu_is_not_repurposed_as_row_action_menu(): void {
		self::assertTrue( method_exists( RowActionMenu::class, 'render' ) );
		self::assertTrue( method_exists( Assets::class, 'enqueue_row_action_menu' ) );
		$html = RowActionMenu::render( [
			'id' => 'test-42',
			'label' => 'More actions for <item>',
			'actions' => [
				[ 'type' => 'link', 'label' => 'Edit', 'url' => admin_url( 'admin.php?page=example' ) ],
				[ 'type' => 'submit', 'label' => 'Publish', 'form_id' => 'publish-42' ],
				[ 'type' => 'button', 'label' => 'Archive', 'variant' => 'danger',
					'attrs' => [ 'onclick' => 'unsafe()', 'data-archive-id' => '42' ] ],
			],
		] );
		self::assertStringContainsString( 'data-cb-core-row-menu', $html );
		self::assertStringContainsString( 'aria-haspopup="menu"', $html );
		self::assertStringContainsString( 'aria-expanded="false"', $html );
		self::assertStringContainsString( 'form="publish-42"', $html );
		self::assertStringContainsString( 'type="submit"', $html );
		self::assertStringContainsString( 'data-archive-id="42"', $html );
		self::assertStringNotContainsString( 'onclick=', $html );
		self::assertStringContainsString( 'aria-label="More actions for', $html );
		self::assertStringNotContainsString( '<item>', $html );
		self::assertStringContainsString( 'data-cb-core-row-menu-trigger', $html );
	}

	public function test_invalid_actions_cannot_render_unsafe_links_or_missing_forms(): void {
		$html = RowActionMenu::render( [
			'label' => 'Actions',
			'actions' => [
				[ 'type' => 'link', 'label' => 'Bad', 'url' => 'javascript:alert(1)' ],
				[ 'type' => 'submit', 'label' => 'Unsafe form', 'form_id' => '" onsubmit="alert(1)' ],
				[ 'type' => 'link', 'label' => 'Disabled', 'url' => '/', 'disabled' => true ],
			],
		] );
		self::assertSame( '', $html );
	}
}
