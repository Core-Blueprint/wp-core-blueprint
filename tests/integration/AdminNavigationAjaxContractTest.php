<?php
declare(strict_types=1);

use CoreBlueprint\Core\AdminNavigation\Admin as AdminNavigationAdmin;
use CoreBlueprint\Core\AdminNavigation\Bootstrap as AdminNavigationBootstrap;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;

/**
 * Canonical WordPress AJAX coverage for Admin Navigation audience pickers.
 *
 * @group ajax
 */
final class AdminNavigationAjaxContractTest extends WP_Ajax_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_Ajax_UnitTestCase establishes WordPress' canonical Ajax context.
		// Re-enter the product bootstrap boundary rather than registering Admin
		// callbacks directly inside the test.
		AdminNavigationBootstrap::boot();
	}

	public function tear_down(): void {
		remove_role( 'cb_nav_picker_ajax' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_plugin_bootstrap_registers_picker_ajax_handlers(): void {
		self::assertNotFalse( has_action( 'wp_ajax_' . AdminNavigationAdmin::ROLE_SEARCH_ACTION, [ AdminNavigationAdmin::class, 'ajax_search_roles' ] ) );
		self::assertNotFalse( has_action( 'wp_ajax_' . AdminNavigationAdmin::CAPABILITY_SEARCH_ACTION, [ AdminNavigationAdmin::class, 'ajax_search_capabilities' ] ) );
	}

	public function test_role_picker_search_runs_through_canonical_ajax_boundary(): void {
		$this->set_admin_user();

		add_role( 'cb_nav_picker_ajax', 'Navigation Picker Ajax', [ 'read' => true ] );

		$_POST = [
			'_ajax_nonce' => wp_create_nonce( AdminNavigationAdmin::PICKER_NONCE_ACTION ),
			'search'      => 'navigation picker ajax',
		];

		try {
			$this->_handleAjax( AdminNavigationAdmin::ROLE_SEARCH_ACTION );
		} catch ( WPAjaxDieContinueException $error ) {
			// Expected normal termination after wp_send_json_success().
		}

		$payload = json_decode( $this->_last_response, true );
		self::assertIsArray( $payload );
		self::assertTrue( (bool) ( $payload['success'] ?? false ) );
		$items = is_array( $payload['data']['items'] ?? null ) ? $payload['data']['items'] : [];
		self::assertContains( 'cb_nav_picker_ajax', array_column( $items, 'id' ) );
	}

	public function test_capability_picker_search_runs_through_canonical_ajax_boundary(): void {
		$this->set_admin_user();

		$_POST = [
			'_ajax_nonce' => wp_create_nonce( AdminNavigationAdmin::PICKER_NONCE_ACTION ),
			'search'      => 'manage_options',
		];

		try {
			$this->_handleAjax( AdminNavigationAdmin::CAPABILITY_SEARCH_ACTION );
		} catch ( WPAjaxDieContinueException $error ) {
			// Expected normal termination after wp_send_json_success().
		}

		$payload = json_decode( $this->_last_response, true );
		self::assertIsArray( $payload );
		self::assertTrue( (bool) ( $payload['success'] ?? false ) );
		$items = is_array( $payload['data']['items'] ?? null ) ? $payload['data']['items'] : [];
		self::assertContains( 'manage_options', array_column( $items, 'id' ) );
	}

	private function set_admin_user(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $admin_id );
		self::assertInstanceOf( WP_User::class, $admin );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 'admin-navigation-ajax-fixture' ) );

		wp_set_current_user( $admin_id );
		self::assertTrue( current_user_can( 'manage_options' ) );
	}
}
