<?php
declare(strict_types=1);

use CoreBlueprint\Core\AdminNavigation\Policy;
use CoreBlueprint\Core\AdminNavigation\ToolbarRuntime;
use CoreBlueprint\Core\Profiles\Engine;
use CoreBlueprint\Core\Profiles\SectionRegistry;
use CoreBlueprint\Core\Profiles\Sections\AdminNavigationSection;

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
}

final class CB_Base_Admin_Navigation_Profile_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_policy;

	public function set_up(): void {
		parent::set_up();
		$this->saved_policy = get_option( Policy::OPTION, '__cb_nav_missing__' );
		delete_option( Policy::OPTION );
	}

	public function tear_down(): void {
		delete_option( Policy::OPTION );
		if ( '__cb_nav_missing__' !== $this->saved_policy ) {
			update_option( Policy::OPTION, $this->saved_policy, false );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_admin_navigation_is_a_base_owned_schema_v1_profile_section_before_module_activation(): void {
		$sections = SectionRegistry::all();
		self::assertArrayHasKey( 'admin-navigation', $sections );
		self::assertSame( 1, $sections['admin-navigation']->schema_version() );
		self::assertSame( 'module-states', array_key_last( $sections ) );
	}

	public function test_profile_payload_is_policy_only_and_preserves_dormant_references(): void {
		$target = $this->target_policy();
		self::assertTrue( Policy::replace( $target, 'profile-export-fixture' ) );

		$document = Engine::export_document( 'Admin Navigation', 'Portable navigation policy', [ 'admin-navigation' ] );
		$data = $document['sections']['admin-navigation']['data'];

		self::assertSame( [ 'menu', 'toolbar' ], array_keys( $data ) );
		self::assertSame( 'missing-plugin-menu', $data['menu']['hidden'][0]['id'] );
		self::assertSame( [ 'missing-role' ], $data['menu']['hidden'][0]['audience']['roles'] );
		self::assertSame( [ 'missing_capability' ], $data['menu']['hidden'][0]['audience']['capabilities'] );
		self::assertArrayNotHasKey( 'labels', $data );
		self::assertArrayNotHasKey( 'discovery', $data );
		self::assertArrayNotHasKey( 'urls', $data );
	}

	public function test_profile_engine_apply_verify_and_toolbar_runtime_accept_missing_target_state(): void {
		$target = $this->target_policy();
		self::assertTrue( Policy::replace( $target, 'profile-source-fixture' ) );
		$document = Engine::export_document( 'Admin Navigation', 'Portable navigation policy', [ 'admin-navigation' ] );

		self::assertTrue( Policy::reset( 'profile-target-reset' ) );
		$preview = Engine::preview( $document );
		$result = Engine::apply( $document, $preview['fingerprint'], 'profile-apply-contract' );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( $target, Policy::get() );
		$section = SectionRegistry::get( 'admin-navigation' );
		self::assertNotNull( $section );
		self::assertTrue( $section->verify( $document['sections']['admin-navigation']['data'] ) );

		$bar = new WP_Admin_Bar();
		$bar->add_node( [ 'id' => 'site-name', 'title' => 'Example Site', 'href' => 'https://example.test/wp-admin/' ] );
		$bar->add_node( [ 'id' => 'updates', 'title' => 'Updates', 'href' => 'https://example.test/wp-admin/update-core.php' ] );
		$bar->add_node( [ 'id' => 'child-node', 'parent' => 'site-name', 'title' => 'Child' ] );
		ToolbarRuntime::apply( $bar );

		self::assertSame( 'Workspace', $bar->get_node( 'site-name' )->title );
		self::assertNull( $bar->get_node( 'updates' ) );
		self::assertIsObject( $bar->get_node( 'child-node' ) );
	}

	public function test_exact_section_restore_rolls_back_through_same_canonical_policy_boundary(): void {
		$section = new AdminNavigationSection();
		$snapshot = $section->snapshot();
		$incoming = [
			'menu'    => $this->target_policy()['menu'],
			'toolbar' => $this->target_policy()['toolbar'],
		];

		$section->apply( $incoming, 'profile-section-apply' );
		self::assertTrue( $section->verify( $incoming ) );

		$section->restore( $snapshot, $incoming, 'profile-section-rollback' );
		self::assertTrue( $section->verify( $snapshot ) );
		self::assertSame( Policy::defaults()['menu'], Policy::get()['menu'] );
		self::assertSame( Policy::defaults()['toolbar'], Policy::get()['toolbar'] );
	}

	private function target_policy(): array {
		$policy = Policy::defaults();
		$policy['menu']['order'] = [ 'missing-plugin-menu', 'index.php' ];
		$policy['menu']['hidden'][] = [
			'id'       => 'missing-plugin-menu',
			'audience' => [
				'roles'        => [ 'missing-role' ],
				'capabilities' => [ 'missing_capability' ],
			],
		];
		$policy['toolbar']['hidden'][] = [
			'id'       => 'updates',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		$policy['toolbar']['renamed'][] = [
			'id'       => 'site-name',
			'label'    => 'Workspace',
			'audience' => [ 'roles' => [], 'capabilities' => [] ],
		];
		return $policy;
	}
}
