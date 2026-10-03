<?php
declare(strict_types=1);

use CoreBlueprint\Core\AdminNavigation\AdminMenuRuntime;
use CoreBlueprint\Core\AdminNavigation\Discovery;
use CoreBlueprint\Core\AdminNavigation\Policy;
use CoreBlueprint\Core\AdminNavigation\ToolbarRuntime;

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
}

final class CB_Base_Admin_Navigation_Runtime_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_policy;
	private mixed $saved_menu;

	public function set_up(): void {
		parent::set_up();
		global $menu;
		$this->saved_policy = get_option( Policy::OPTION, '__cb_nav_missing__' );
		$this->saved_menu = $menu ?? null;
		delete_option( Policy::OPTION );
		Discovery::_reset_for_testing();
		$_GET = [];
	}

	public function tear_down(): void {
		global $menu;
		$menu = $this->saved_menu;
		delete_option( Policy::OPTION );
		if ( '__cb_nav_missing__' !== $this->saved_policy ) {
			update_option( Policy::OPTION, $this->saved_policy, false );
		}
		Discovery::_reset_for_testing();
		wp_set_current_user( 0 );
		$_GET = [];
		parent::tear_down();
	}

	public function test_empty_policy_is_exact_noop_and_does_not_enable_custom_ordering(): void {
		$incoming = [ 'index.php', 'separator1', 'edit.php', 'plugins.php' ];
		self::assertFalse( AdminMenuRuntime::filter_custom_menu_order( false ) );
		self::assertTrue( AdminMenuRuntime::filter_custom_menu_order( true ) );
		self::assertSame( $incoming, AdminMenuRuntime::filter_menu_order( $incoming ) );

		$bar = $this->toolbar_fixture();
		$before = $bar->get_nodes();
		ToolbarRuntime::apply( $bar );
		self::assertEquals( $before, $bar->get_nodes() );
	}

	public function test_editor_discovery_request_enables_menu_order_without_saved_order(): void {
		$_GET['page'] = 'core-blueprint-preferences';
		$_GET['tab'] = 'admin-navigation';
		self::assertTrue( AdminMenuRuntime::filter_custom_menu_order( false ) );
	}

	public function test_saved_order_reorders_only_known_items_and_new_unknown_items_fail_open(): void {
		$policy = Policy::defaults();
		$policy['menu']['order'] = [ 'plugins.php', 'index.php', 'not-installed.php' ];
		self::assertTrue( Policy::replace( $policy ) );

		$incoming = [ 'index.php', 'separator1', 'edit.php', 'plugins.php', 'new-plugin-menu' ];
		self::assertTrue( AdminMenuRuntime::filter_custom_menu_order( false ) );
		self::assertSame(
			[ 'plugins.php', 'index.php', 'separator1', 'edit.php', 'new-plugin-menu' ],
			AdminMenuRuntime::filter_menu_order( $incoming )
		);
		self::assertContains( 'new-plugin-menu', Discovery::menu_identities() );
		self::assertContains( 'not-installed.php', Discovery::menu_identities() );
	}

	public function test_hide_is_presentation_only_and_never_changes_authorization(): void {
		global $menu;
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$policy = Policy::defaults();
		$policy['menu']['hidden'] = [
			[
				'id'       => 'tools.php',
				'audience' => [ 'roles' => [ 'editor' ], 'capabilities' => [ 'edit_posts' ] ],
			],
			[
				'id'       => 'new-plugin-menu',
				'audience' => [ 'roles' => [ 'role-that-does-not-exist' ], 'capabilities' => [] ],
			],
		];
		self::assertTrue( Policy::replace( $policy ) );

		$menu = [
			10 => [ 'Dashboard', 'read', 'index.php' ],
			20 => [ 'Tools', 'edit_posts', 'tools.php' ],
			30 => [ 'New Plugin', 'edit_posts', 'new-plugin-menu' ],
		];
		$edit_posts_before = current_user_can( 'edit_posts' );
		$manage_options_before = current_user_can( 'manage_options' );

		AdminMenuRuntime::apply_visibility();

		self::assertSame( $edit_posts_before, current_user_can( 'edit_posts' ) );
		self::assertSame( $manage_options_before, current_user_can( 'manage_options' ) );
		self::assertNotContains( 'tools.php', array_column( $menu, 2 ) );
		self::assertContains( 'new-plugin-menu', array_column( $menu, 2 ) );
	}

	public function test_toolbar_rename_preserves_node_metadata_and_children(): void {
		$policy = Policy::defaults();
		$policy['toolbar']['renamed'][] = [
			'id'       => 'site-name',
			'label'    => 'Workspace',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy ) );

		$bar = $this->toolbar_fixture();
		$before = $bar->get_node( 'site-name' );
		$child_before = $bar->get_node( 'child-node' );
		ToolbarRuntime::apply( $bar );
		$after = $bar->get_node( 'site-name' );
		$child_after = $bar->get_node( 'child-node' );

		self::assertIsObject( $before );
		self::assertIsObject( $after );
		self::assertSame( 'Workspace', $after->title );
		self::assertSame( $before->parent, $after->parent );
		self::assertSame( $before->href, $after->href );
		self::assertSame( $before->group, $after->group );
		self::assertSame( $before->meta, $after->meta );
		self::assertEquals( $child_before, $child_after );
	}

	public function test_toolbar_markup_title_is_not_renamed_and_unknown_hide_audience_fails_open(): void {
		$policy = Policy::defaults();
		$policy['toolbar']['hidden'][] = [
			'id'       => 'site-name',
			'audience' => [ 'roles' => [], 'capabilities' => [ 'capability_that_does_not_exist' ] ],
		];
		$policy['toolbar']['renamed'][] = [
			'id'       => 'updates',
			'label'    => 'Updates renamed',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy ) );

		$bar = $this->toolbar_fixture();
		$bar->add_node( [ 'id' => 'updates', 'title' => '<span class="ab-label">2</span>', 'href' => 'https://example.test/updates' ] );
		ToolbarRuntime::apply( $bar );

		self::assertIsObject( $bar->get_node( 'site-name' ) );
		self::assertSame( '<span class="ab-label">2</span>', $bar->get_node( 'updates' )->title );
	}

	public function test_toolbar_hide_removes_only_the_target_node_and_keeps_unrelated_nodes(): void {
		$policy = Policy::defaults();
		$policy['toolbar']['hidden'][] = [
			'id'       => 'site-name',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy ) );

		$bar = $this->toolbar_fixture();
		ToolbarRuntime::apply( $bar );
		self::assertNull( $bar->get_node( 'site-name' ) );
		self::assertIsObject( $bar->get_node( 'unrelated-node' ) );
	}

	public function test_toolbar_discovery_is_explicitly_current_admin_request_plus_policy_references(): void {
		$policy = Policy::defaults();
		$policy['toolbar']['hidden'][] = [
			'id'       => 'not-present-this-request',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy ) );

		$bar = $this->toolbar_fixture();
		Discovery::capture_toolbar( $bar );
		self::assertSame(
			[ 'site-name', 'child-node', 'unrelated-node', 'not-present-this-request' ],
			Discovery::toolbar_identities()
		);
	}

	public function test_source_contract_uses_no_menu_internals_dom_sorter_aliases_or_plugin_adapters(): void {
		$root = CB_CORE_DIR . 'src/AdminNavigation/';
		$source = '';
		foreach ( [ 'Policy.php', 'Audience.php', 'Discovery.php', 'AdminMenuRuntime.php', 'ToolbarRuntime.php', 'Bootstrap.php' ] as $file ) {
			$contents = file_get_contents( $root . $file );
			self::assertIsString( $contents );
			$source .= "\n" . $contents;
		}

		foreach ( [ 'global $menu', '$submenu', 'querySelector', 'jQuery', 'add_submenu_page', 'remove_submenu_page', 'menu_page_url(', 'add_menu_page(' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
		self::assertStringContainsString( "add_filter( 'custom_menu_order'", $source );
		self::assertStringContainsString( "add_filter( 'menu_order'", $source );
		self::assertStringContainsString( 'remove_menu_page( $rule[\'id\'] )', $source );
		self::assertStringContainsString( '$admin_bar->get_node( $id )', $source );
		self::assertStringContainsString( '$admin_bar->add_node(', $source );
		self::assertStringContainsString( '$admin_bar->remove_node(', $source );
		self::assertStringContainsString( 'PHP_INT_MAX', $source );
	}

	private function toolbar_fixture(): WP_Admin_Bar {
		$bar = new WP_Admin_Bar();
		$bar->add_node( [
			'id'     => 'site-name',
			'title'  => 'Example Site',
			'href'   => 'https://example.test/wp-admin/',
			'parent' => false,
			'meta'   => [ 'class' => 'site-node', 'title' => 'Original tooltip' ],
		] );
		$bar->add_node( [
			'id'     => 'child-node',
			'parent' => 'site-name',
			'title'  => 'Child',
			'href'   => 'https://example.test/child',
		] );
		$bar->add_node( [
			'id'    => 'unrelated-node',
			'title' => 'Unrelated',
			'href'  => 'https://example.test/unrelated',
		] );
		return $bar;
	}
}
