<?php
declare(strict_types=1);

use CoreBlueprint\Core\AdminNavigation\Admin as AdminNavigationAdmin;
use CoreBlueprint\Core\AdminNotices\Admin as AdminNoticesAdmin;
use CoreBlueprint\Core\UI\RoleCapabilityPicker;

final class CB_Base_Role_Capability_Picker_Contract_Test extends WP_UnitTestCase {

	private const ROLE = 'cb_shared_picker_contract';

	public function set_up(): void {
		parent::set_up();
		add_role( self::ROLE, 'Shared Picker Contract', [ 'read' => true ] );
	}

	public function tear_down(): void {
		remove_role( self::ROLE );
		parent::tear_down();
	}

	public function test_shared_role_items_are_the_canonical_feature_projection(): void {
		$roles = [ self::ROLE, 'missing_role_reference' ];
		$expected = RoleCapabilityPicker::role_items( $roles );

		self::assertSame( $expected, AdminNavigationAdmin::role_picker_items( $roles ) );
		self::assertSame( $expected, AdminNoticesAdmin::role_picker_items( $roles ) );
		self::assertSame( self::ROLE, $expected[0]['id'] );
		self::assertSame( 'Shared Picker Contract', $expected[0]['label'] );
		self::assertSame( self::ROLE, $expected[0]['meta'] );
		self::assertSame( 'missing_role_reference', $expected[1]['label'] );
	}

	public function test_shared_role_search_is_bounded_and_used_by_both_features(): void {
		self::assertSame( [], RoleCapabilityPicker::search_roles( 'x' ) );

		$expected = RoleCapabilityPicker::search_roles( 'shared picker contract' );
		self::assertContains( self::ROLE, array_column( $expected, 'id' ) );
		self::assertSame( $expected, AdminNavigationAdmin::search_roles( 'shared picker contract' ) );
		self::assertSame( $expected, AdminNoticesAdmin::search_roles( 'shared picker contract' ) );
	}

	public function test_shared_capability_projection_and_search_are_used_by_both_features(): void {
		$capabilities = [ 'manage_options', 'cb_capability_reference_missing' ];
		$items = RoleCapabilityPicker::capability_items( $capabilities );

		self::assertSame( $items, AdminNavigationAdmin::capability_picker_items( $capabilities ) );
		self::assertSame( $items, AdminNoticesAdmin::capability_picker_items( $capabilities ) );
		self::assertSame( 'manage_options', $items[0]['id'] );
		self::assertSame( 'cb_capability_reference_missing', $items[1]['label'] );

		self::assertSame( [], RoleCapabilityPicker::search_capabilities( 'x' ) );
		$expected = RoleCapabilityPicker::search_capabilities( 'manage_options' );
		self::assertContains( 'manage_options', array_column( $expected, 'id' ) );
		self::assertSame( $expected, AdminNavigationAdmin::search_capabilities( 'manage_options' ) );
		self::assertSame( $expected, AdminNoticesAdmin::search_capabilities( 'manage_options' ) );
	}
}
