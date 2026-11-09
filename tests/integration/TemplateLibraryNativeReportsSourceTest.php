<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Permissions\PrivilegedAccessGuard;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Reports\DesignDocumentAdapter;
use CoreBlueprint\Core\Reports\NativeTemplateSource;
use CoreBlueprint\Core\Reports\State as ReportsState;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Native_Reports_Source_Test extends WP_UnitTestCase {
	private int $operator = 0;
	private bool $was_enabled = false;

	public function set_up(): void {
		parent::set_up();
		Roles::ensure_operator_role();
		self::assertNotNull( get_role( Roles::OPERATOR_ROLE ) );
		$this->operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->operator );
		self::assertInstanceOf( WP_User::class, $user );
		PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
			$user->add_role( Roles::OPERATOR_ROLE );
		} );
		self::assertTrue(
			PrivilegedAccessRegistry::approve( $user, 0, 'template_library_native_reports_fixture' )
		);
		wp_set_current_user( $this->operator );
		self::assertTrue( current_user_can( 'cb_manage_branding' ) );
		self::assertTrue( current_user_can( 'manage_options' ) );
		$this->was_enabled = ReportsState::is_enabled();
		if ( ! $this->was_enabled ) {
			ReportsState::set_enabled( true, 'template_library_native_reports_fixture' );
		}
		TemplateSourceRegistry::_reset_for_testing();
		TemplateSourceRegistry::collect();
	}

	public function tear_down(): void {
		TemplateSourceRegistry::_reset_for_testing();
		wp_set_current_user( $this->operator );
		if ( ReportsState::is_enabled() !== $this->was_enabled ) {
			ReportsState::set_enabled( $this->was_enabled, 'template_library_native_reports_fixture' );
		}
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_only_one_native_reports_template_is_discoverable_without_document_data(): void {
		self::assertSame(
			[ 'extension' => 'core-blueprint', 'designer' => 'reports' ],
			TemplateSourceRegistry::sources()[ NativeTemplateSource::SOURCE_ID ] ?? null
		);
		$page = TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'reports',
			new TemplateQuery( source: NativeTemplateSource::SOURCE_ID, limit: 1 )
		);
		self::assertCount( 1, $page->items );
		self::assertNull( $page->next_cursor );
		$item = $page->items[0];
		self::assertSame( NativeTemplateSource::TEMPLATE_ID, $item->id );
		self::assertSame( DesignDocumentAdapter::KEY, $item->id );
		self::assertSame( 'built-in', $item->origin );
		self::assertSame( 'maintenance', $item->category );
		self::assertSame( 'reports', $item->designer );
		self::assertSame( 'en', $item->locale );
		self::assertArrayNotHasKey( 'document', get_object_vars( $item ) );
		self::assertArrayNotHasKey( 'fields', get_object_vars( $item ) );
		self::assertArrayNotHasKey( 'branding', get_object_vars( $item ) );
	}

	public function test_native_reports_search_facets_and_wrong_designer_are_fail_closed(): void {
		$source = new NativeTemplateSource();
		self::assertSame(
			[ 'maintenance' => __( 'Maintenance Report', 'core-blueprint' ) ],
			$source->categories( $this->operator )
		);
		foreach ( [
			new TemplateQuery( origin: 'personal' ),
			new TemplateQuery( category: 'newsletter' ),
			new TemplateQuery( designer: 'mail' ),
			new TemplateQuery( source: 'core-blueprint-mail' ),
			new TemplateQuery( extension: 'different-extension' ),
			new TemplateQuery( locale: 'nl' ),
			new TemplateQuery( search: 'this-does-not-match' ),
		] as $query ) {
			self::assertSame( [], $source->query( $query, null )->items );
		}
		self::assertCount( 1, $source->query(
			new TemplateQuery( search: __( 'Maintenance Report', 'core-blueprint' ), origin: 'built-in', category: 'maintenance' ), null
		)->items );
		self::assertSame( [], $source->query( new TemplateQuery(), '0' )->items );
		self::assertSame( [], TemplateSourceRegistry::browse_designer(
			'core-blueprint', 'mail',
			new TemplateQuery( source: NativeTemplateSource::SOURCE_ID )
		)->items );
	}

	public function test_native_apply_document_is_same_target_read_only_and_capability_gated(): void {
		$source = new NativeTemplateSource();
		$scope = [ 'extension' => 'core-blueprint', 'designer' => 'reports' ];
		self::assertSame( \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::PROFILE, $source->target_profile_id() );
		$expected = [
			'project' => \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_project(),
			'fields' => \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_fields(),
		];
		self::assertSame( $expected, TemplateSourceRegistry::document_for_apply(
			NativeTemplateSource::SOURCE_ID, 'maintenance', $source->target_profile_id(),
			Repository::REPORTS, 'maintenance', $scope
		) );
		self::assertNull( TemplateSourceRegistry::document_for_apply(
			NativeTemplateSource::SOURCE_ID, 'maintenance', 'base/mail',
			Repository::REPORTS, 'maintenance', $scope
		) );
		self::assertNull( TemplateSourceRegistry::document_for_apply(
			NativeTemplateSource::SOURCE_ID, 'maintenance', $source->target_profile_id(),
			Repository::MAIL, 'maintenance', $scope
		) );
		self::assertNull( TemplateSourceRegistry::document_for_apply(
			NativeTemplateSource::SOURCE_ID, 'maintenance', $source->target_profile_id(),
			Repository::REPORTS, 'another-report', $scope
		) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertNull( TemplateSourceRegistry::document_for_apply(
			NativeTemplateSource::SOURCE_ID, 'maintenance', $source->target_profile_id(),
			Repository::REPORTS, 'maintenance', $scope
		) );
		wp_set_current_user( $this->operator );
	}

	public function test_navigation_is_same_site_and_built_in_remains_immutable(): void {
		$source = new NativeTemplateSource();
		$item = $source->query( new TemplateQuery(), null )->items[0];
		self::assertFalse( $source->can_manage( $item->id, $this->operator ) );
		$navigation = TemplateSourceRegistry::navigation( $item, true );
		self::assertNull( $navigation->manage );
		self::assertNotNull( $navigation->preview );
		self::assertStringStartsWith( admin_url(), $navigation->preview['url'] );
		self::assertStringContainsString( 'page=core-blueprint-preferences', $navigation->preview['url'] );
		self::assertStringContainsString( 'tab=reports', $navigation->preview['url'] );
		self::assertStringContainsString( 'cb_designer=1', $navigation->preview['url'] );

		$forged = new TemplateMetadata(
			NativeTemplateSource::SOURCE_ID, 'core-blueprint', 'reports',
			'maintenance', 'Forged', '', 'personal', 'maintenance', 'en', '1'
		);
		self::assertSame( [], $source->navigation( $forged, $this->operator )->public_links( true ) );
		self::assertFalse( $source->can_access_designer_resource(
			Repository::MAIL, 'maintenance', $this->operator
		) );
		self::assertFalse( $source->can_access_designer_resource(
			Repository::REPORTS, 'another-report', $this->operator
		) );
		self::assertTrue( $source->can_access_designer_resource(
			Repository::REPORTS, 'maintenance', $this->operator
		) );
	}

	public function test_module_deactivation_and_revoked_actor_hide_all_reports_metadata(): void {
		$source = new NativeTemplateSource();
		ReportsState::set_enabled( false, 'template_library_native_reports_disabled_fixture' );
		self::assertArrayNotHasKey(
			NativeTemplateSource::SOURCE_ID, TemplateSourceRegistry::sources()
		);
		self::assertSame( [], TemplateSourceRegistry::browse_global(
			new TemplateQuery( source: NativeTemplateSource::SOURCE_ID )
		)->items );
		self::assertSame( [], $source->categories( $this->operator ) );
		self::assertFalse( $source->can_access_designer_resource(
			Repository::REPORTS, 'maintenance', $this->operator
		) );
		ReportsState::set_enabled( true, 'template_library_native_reports_enabled_fixture' );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		self::assertArrayNotHasKey(
			NativeTemplateSource::SOURCE_ID, TemplateSourceRegistry::sources()
		);
		self::assertFalse( $source->can_browse( $subscriber ) );
		self::assertFalse( $source->can_browse( $this->operator ), 'Acting user must match authorized actor.' );
		self::assertSame( [], $source->categories( $subscriber ) );
		self::assertSame( [], TemplateSourceRegistry::browse_global(
			new TemplateQuery( source: NativeTemplateSource::SOURCE_ID )
		)->items );
	}

	public function test_designer_scope_cannot_be_minted_on_other_wordpress_screens(): void {
		$source = new NativeTemplateSource();
		set_current_screen( 'front' );
		self::assertFalse( $source->can_launch_designer(
			Repository::REPORTS, 'maintenance', $this->operator
		) );
	}
}
