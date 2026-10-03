<?php
declare(strict_types=1);

use CoreBlueprint\Core\AdminColumns\Admin\Ajax as AdminColumnsAjax;
use CoreBlueprint\Core\AdminColumns\PolicyRepository;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;

/**
 * Canonical WordPress AJAX coverage for Admin Columns Governance.
 *
 * @group ajax
 */
final class AdminColumnsAjaxContractTest extends WP_Ajax_UnitTestCase {
	private mixed $saved_policy = '__cb_ac_ajax_missing__';

	public function set_up(): void {
		parent::set_up();

		$this->saved_policy = get_option( PolicyRepository::OPTION, '__cb_ac_ajax_missing__' );
		delete_option( PolicyRepository::OPTION );

		if ( false === has_action( 'wp_ajax_' . AdminColumnsAjax::ACTION, [ AdminColumnsAjax::class, 'handle' ] ) ) {
			AdminColumnsAjax::boot();
		}
	}

	public function tear_down(): void {
		if ( '__cb_ac_ajax_missing__' === $this->saved_policy ) {
			delete_option( PolicyRepository::OPTION );
		} else {
			update_option( PolicyRepository::OPTION, $this->saved_policy, false );
		}

		parent::tear_down();
	}

	public function test_b9_ajax_save_uses_nonce_and_preserves_wordpress_user_screen_options(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $admin_id );
		self::assertInstanceOf( WP_User::class, $admin );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 'admin-columns-b9-fixture' ) );

		wp_set_current_user( $admin_id );
		self::assertTrue( current_user_can( 'manage_options' ) );

		$hidden_meta_key = 'manageedit-postcolumnshidden';
		update_user_meta( $admin_id, $hidden_meta_key, [ 'author' ] );

		$_POST = [
			'nonce' => wp_create_nonce( AdminColumnsAjax::NONCE_ACTION ),
			'screen_id' => 'edit-post',
			'operation' => 'save',
			'screen_policy' => (string) wp_json_encode( [
				'order' => [ 'cb', 'title', 'date' ],
				'hidden' => [ 'date' ],
				'taxonomies' => [],
				'meta' => [],
			] ),
		];

		try {
			$this->_handleAjax( AdminColumnsAjax::ACTION );
		} catch ( WPAjaxDieContinueException $error ) {
			// Expected normal termination after wp_send_json_success().
		}

		$payload = json_decode( $this->_last_response, true );
		self::assertIsArray( $payload );
		self::assertTrue( (bool) ( $payload['success'] ?? false ) );
		self::assertTrue( (bool) ( $payload['data']['changed'] ?? false ) );
		self::assertSame( [ 'date' ], PolicyRepository::screen( 'edit-post' )['hidden'] );
		self::assertSame( [ 'author' ], get_user_meta( $admin_id, $hidden_meta_key, true ) );
	}
}
