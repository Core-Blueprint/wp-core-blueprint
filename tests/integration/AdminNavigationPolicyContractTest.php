<?php
declare(strict_types=1);

use CB\Core\AdminNavigation\Audience;
use CB\Core\AdminNavigation\Discovery;
use CB\Core\AdminNavigation\Policy;

final class CB_Base_Admin_Navigation_Policy_Contract_Test extends WP_UnitTestCase {

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

	public function test_policy_schema_is_bounded_and_contains_no_custom_navigation_entries(): void {
		self::assertSame( [ 'version', 'menu', 'toolbar' ], array_keys( Policy::defaults() ) );
		self::assertSame( [ 'order', 'hidden' ], array_keys( Policy::defaults()['menu'] ) );
		self::assertSame( [ 'hidden', 'renamed' ], array_keys( Policy::defaults()['toolbar'] ) );
		self::assertArrayNotHasKey( 'custom', Policy::defaults()['menu'] );
		self::assertArrayNotHasKey( 'custom', Policy::defaults()['toolbar'] );

		$invalid = Policy::defaults();
		$invalid['menu']['custom'] = [];
		$this->expectException( InvalidArgumentException::class );
		Policy::normalize( $invalid );
	}

	public function test_corrupt_storage_fails_open_to_exact_empty_policy(): void {
		update_option( Policy::OPTION, [ 'version' => 999 ], false );
		self::assertSame( Policy::defaults(), Policy::get() );
		self::assertFalse( Policy::has_menu_order() );
	}

	public function test_unknown_audience_references_are_preserved_but_do_not_match(): void {
		$normalized = Audience::normalize( [
			'roles'        => [ 'role-that-does-not-exist' ],
			'capabilities' => [ 'capability_that_does_not_exist' ],
		] );
		self::assertSame( [ 'role-that-does-not-exist' ], $normalized['roles'] );
		self::assertSame( [ 'capability_that_does_not_exist' ], $normalized['capabilities'] );

		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );
		self::assertFalse( Audience::matches( $normalized ) );
	}

	public function test_audience_match_uses_any_role_and_every_capability_with_and_between_groups(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		self::assertTrue( Audience::matches( [ 'roles' => [], 'capabilities' => [] ] ) );
		self::assertTrue( Audience::matches( [ 'roles' => [ 'author', 'editor' ], 'capabilities' => [] ] ) );
		self::assertTrue( Audience::matches( [ 'roles' => [], 'capabilities' => [ 'read', 'edit_posts' ] ] ) );
		self::assertTrue( Audience::matches( [ 'roles' => [ 'editor' ], 'capabilities' => [ 'read', 'edit_posts' ] ] ) );
		self::assertFalse( Audience::matches( [ 'roles' => [ 'administrator' ], 'capabilities' => [ 'edit_posts' ] ] ) );
		self::assertFalse( Audience::matches( [ 'roles' => [ 'editor' ], 'capabilities' => [ 'read', 'manage_options' ] ] ) );
	}

	public function test_discovery_catalog_is_request_local_union_with_persisted_references(): void {
		$policy = Policy::defaults();
		$policy['menu']['order'] = [ 'plugins.php', 'index.php' ];
		$policy['menu']['hidden'][] = [
			'id'       => 'temporarily-missing.php',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		$policy['toolbar']['hidden'][] = [
			'id'       => 'frontend-only-reference',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy ) );

		Discovery::capture_menu_order( [ 'index.php', 'edit.php', 'plugins.php' ] );
		self::assertSame(
			[ 'index.php', 'edit.php', 'plugins.php', 'temporarily-missing.php' ],
			Discovery::menu_identities()
		);
		self::assertSame( [ 'frontend-only-reference' ], Discovery::toolbar_identities() );
	}
}
