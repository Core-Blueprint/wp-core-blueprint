<?php
declare(strict_types=1);

use CB\Core\AdminNavigation\Admin as AdminNavigationAdmin;
use CB\Core\AdminNavigation\Discovery;
use CB\Core\AdminNavigation\Policy;

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
}

final class CB_Base_Admin_Navigation_Management_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_policy;

	public function set_up(): void {
		parent::set_up();
		$this->saved_policy = get_option( Policy::OPTION, '__cb_nav_missing__' );
		delete_option( Policy::OPTION );
		Discovery::_reset_for_testing();
		$_GET = [];
	}

	public function tear_down(): void {
		delete_option( Policy::OPTION );
		if ( '__cb_nav_missing__' !== $this->saved_policy ) {
			update_option( Policy::OPTION, $this->saved_policy, false );
		}
		Discovery::_reset_for_testing();
		wp_set_current_user( 0 );
		$_GET = [];
		parent::tear_down();
	}

	public function test_editor_save_and_reset_roundtrip_through_canonical_policy_boundary(): void {
		$policy = Policy::defaults();
		$policy['menu']['order'] = [ 'plugins.php', 'index.php', 'missing-plugin.php' ];
		$policy['menu']['hidden'][] = [
			'id'       => 'tools.php',
			'audience' => [ 'roles' => [ 'editor' ], 'capabilities' => [ 'edit_posts' ] ],
		];
		$policy['toolbar']['hidden'][] = [
			'id'       => 'wp-logo',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		$policy['toolbar']['renamed'][] = [
			'id'       => 'site-name',
			'label'    => 'Workspace',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];

		self::assertTrue( AdminNavigationAdmin::save_editor_payload( $policy, 'management-roundtrip' ) );
		self::assertSame( Policy::normalize( $policy ), Policy::get() );

		self::assertTrue( AdminNavigationAdmin::reset_editor_policy( 'management-roundtrip' ) );
		self::assertSame( Policy::defaults(), Policy::get() );
	}

	public function test_noop_save_and_noop_reset_do_not_create_artificial_audit_events(): void {
		$events = [];
		$listener = static function ( int $id, string $event ) use ( &$events ): void {
			unset( $id );
			$events[] = $event;
		};
		add_action( 'cb_core_audit_log_written', $listener, 10, 2 );

		try {
			$policy = Policy::defaults();
			$policy['menu']['hidden'][] = [
				'id'       => 'tools.php',
				'audience' => [ 'roles' => [], 'capabilities' => [] ],
			];

			self::assertTrue( Policy::replace( $policy, 'noop-audit-contract' ) );
			$changed_after_first = count( array_filter( $events, static fn( string $event ): bool => 'ui_admin_navigation_changed' === $event ) );
			self::assertSame( 1, $changed_after_first );

			self::assertTrue( Policy::replace( $policy, 'noop-audit-contract' ) );
			$changed_after_noop = count( array_filter( $events, static fn( string $event ): bool => 'ui_admin_navigation_changed' === $event ) );
			self::assertSame( $changed_after_first, $changed_after_noop );

			self::assertTrue( Policy::reset( 'noop-audit-contract' ) );
			$reset_after_first = count( array_filter( $events, static fn( string $event ): bool => 'ui_admin_navigation_reset' === $event ) );
			self::assertSame( 1, $reset_after_first );

			self::assertTrue( Policy::reset( 'noop-audit-contract' ) );
			$reset_after_noop = count( array_filter( $events, static fn( string $event ): bool => 'ui_admin_navigation_reset' === $event ) );
			self::assertSame( $reset_after_first, $reset_after_noop );
		} finally {
			remove_action( 'cb_core_audit_log_written', $listener, 10 );
		}
	}

	public function test_editor_catalog_keeps_current_and_dormant_policy_identities_without_plugin_adapters(): void {
		$policy = Policy::defaults();
		$policy['menu']['hidden'][] = [
			'id'       => 'temporarily-missing-menu',
			'audience' => [ 'roles' => [ 'missing-role' ], 'capabilities' => [ 'missing_capability' ] ],
		];
		$policy['toolbar']['renamed'][] = [
			'id'       => 'temporarily-missing-toolbar',
			'label'    => 'Dormant title',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy, 'editor-catalog-contract' ) );

		Discovery::capture_menu_order( [ 'index.php', 'new-plugin-menu' ] );
		$bar = new WP_Admin_Bar();
		$bar->add_node( [ 'id' => 'site-name', 'title' => 'Example Site', 'href' => 'https://example.test/' ] );
		Discovery::capture_toolbar( $bar );

		$state = AdminNavigationAdmin::editor_state();
		$menu_by_id = [];
		foreach ( $state['menu'] as $row ) {
			$menu_by_id[ $row['id'] ] = $row;
		}
		$toolbar_by_id = [];
		foreach ( $state['toolbar'] as $row ) {
			$toolbar_by_id[ $row['id'] ] = $row;
		}

		self::assertArrayHasKey( 'new-plugin-menu', $menu_by_id );
		self::assertSame( 'new-plugin-menu', $menu_by_id['new-plugin-menu']['label'] );
		self::assertTrue( $menu_by_id['new-plugin-menu']['present'] );
		self::assertArrayHasKey( 'temporarily-missing-menu', $menu_by_id );
		self::assertFalse( $menu_by_id['temporarily-missing-menu']['present'] );
		self::assertArrayHasKey( 'temporarily-missing-toolbar', $toolbar_by_id );
		self::assertFalse( $toolbar_by_id['temporarily-missing-toolbar']['present'] );
		self::assertSame( 'Example Site', $toolbar_by_id['site-name']['label'] );
	}

	public function test_audience_picker_catalogs_are_searchable_and_preserve_unknown_references(): void {
		add_role( 'cb_nav_picker_test', 'Navigation Picker Test', [
			'read'                   => true,
			'cb_nav_picker_test_cap' => true,
		] );

		try {
			$roles = AdminNavigationAdmin::search_roles( 'navigation picker' );
			self::assertContains( 'cb_nav_picker_test', array_column( $roles, 'id' ) );

			$capabilities = AdminNavigationAdmin::search_capabilities( 'cb_nav_picker_test_cap' );
			self::assertContains( 'cb_nav_picker_test_cap', array_column( $capabilities, 'id' ) );

			$selected_roles = AdminNavigationAdmin::role_picker_items( [ 'cb_nav_picker_test', 'missing-role' ] );
			self::assertSame( [ 'cb_nav_picker_test', 'missing-role' ], array_column( $selected_roles, 'id' ) );
			self::assertSame( 'missing-role', $selected_roles[1]['label'] );

			$selected_capabilities = AdminNavigationAdmin::capability_picker_items( [ 'cb_nav_picker_test_cap', 'missing_capability' ] );
			self::assertSame( [ 'cb_nav_picker_test_cap', 'missing_capability' ], array_column( $selected_capabilities, 'id' ) );
			self::assertSame( 'missing_capability', $selected_capabilities[1]['label'] );
		} finally {
			remove_role( 'cb_nav_picker_test' );
		}
	}

	public function test_form_handler_is_manage_options_and_nonce_gated_and_does_not_write_options_directly(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/AdminNavigation/Admin.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( "current_user_can( 'manage_options' )", $source );
		self::assertStringContainsString( 'check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME )', $source );
		self::assertStringContainsString( 'Policy::replace( $payload, $actor )', $source );
		self::assertStringContainsString( 'Policy::reset( $actor )', $source );
		self::assertStringContainsString( "Request::nonce( self::PICKER_NONCE_ACTION, '_ajax_nonce' )", $source );
		self::assertStringContainsString( "Request::cap( 'manage_options' )", $source );
		self::assertStringContainsString( "'wp_ajax_' . self::ROLE_SEARCH_ACTION", $source );
		self::assertStringContainsString( "'wp_ajax_' . self::CAPABILITY_SEARCH_ACTION", $source );
		self::assertStringNotContainsString( 'update_option(', $source );
		self::assertStringNotContainsString( 'delete_option(', $source );
	}

	public function test_reorder_foundation_only_mutates_editor_payload_and_never_real_admin_menu_dom(): void {
		$script = file_get_contents( CB_CORE_DIR . 'assets/js/features/admin-navigation.js' );
		$template = file_get_contents( CB_CORE_DIR . 'templates/preferences-admin-navigation.php' );
		self::assertIsString( $script );
		self::assertIsString( $template );

		self::assertStringContainsString( 'window.cbCore?.reorder', $script );
		self::assertStringContainsString( 'reorderFoundation.enhance', $script );
		self::assertStringContainsString( 'data-cb-admin-navigation-payload', $template );
		self::assertStringContainsString( 'cb-core-admin-navigation-row--menu', $template );
		self::assertStringContainsString( 'button-link cb-core-admin-navigation-row__drag', $template );
		self::assertStringContainsString( 'dashicons dashicons-move', $template );
		self::assertStringNotContainsString( 'dashicons dashicons-menu', $template );
		self::assertStringContainsString( 'data-cb-admin-navigation-hide-audience', $template );
		self::assertStringContainsString( 'data-cb-admin-navigation-rename-audience', $template );
		self::assertStringContainsString( 'data-cb-admin-navigation-hide-roles-picker', $template );
		self::assertStringContainsString( 'data-cb-admin-navigation-hide-capabilities-picker', $template );
		self::assertStringContainsString( '\\CB\\Core\\UI\\ObjectPicker::render', $template );
		self::assertStringContainsString( 'cb-core-admin-navigation-toolbar-item', $template );
		self::assertStringContainsString( 'cb-core-disclosure--compact', $template );
		self::assertStringNotContainsString( 'class="cb-core-panel"', $template );
		self::assertStringContainsString( 'syncPresentation', $script );
		self::assertStringContainsString( 'renameAudience.hidden', $script );
		self::assertStringContainsString( 'data-cb-core-object-picker-input', $script );
		self::assertStringContainsString( 'JSON.stringify(buildPolicy())', $script );
		self::assertStringNotContainsString( 'jQuery', $script );
		self::assertStringNotContainsString( '#adminmenu', $script );
		self::assertStringNotContainsString( 'wp-admin-bar-', $script );
		self::assertStringNotContainsString( 'fetch(', $script );
	}
}
