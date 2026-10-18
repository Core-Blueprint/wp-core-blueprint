<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\Assets;
use CoreBlueprint\Core\TemplateLibrary\BrowserController;
use CoreBlueprint\Core\TemplateLibrary\DesignerScope;
use CoreBlueprint\Core\TemplateLibrary\TemplateCategorySourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Browser_Contract_Test extends WP_UnitTestCase {
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $this->admin_id );
		self::assertInstanceOf( WP_User::class, $admin );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 'template_library_test_fixture' ) );
		wp_set_current_user( $this->admin_id );
		self::assertTrue( current_user_can( 'manage_options' ), 'An explicit approved administrator is required for the browser fixture.' );
		set_current_screen( 'core-blueprint_page_core-blueprint-template-library' );
		TemplateSourceRegistry::_reset_for_testing();
		add_action( 'core_blueprint_register_template_sources', [ $this, 'register_sources' ] );
	}

	public function tear_down(): void {
		remove_action( 'core_blueprint_register_template_sources', [ $this, 'register_sources' ] );
		TemplateSourceRegistry::_reset_for_testing();
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function register_sources(): void {
		foreach ( [ 'workflow', 'mail' ] as $type ) {
			TemplateSourceRegistry::register( new class( $type ) implements TemplateSourceInterface, TemplateCategorySourceInterface {
				public function __construct( private string $type ) {}
				public function source_id(): string { return 'core-blueprint-test-' . $this->type; }
				public function extension_id(): string { return 'core-blueprint'; }
				public function designer_type(): string { return $this->type; }
				public function categories( int $user_id ): array {
					return $this->can_browse( $user_id ) ? [ 'example' => 'Example' ] : [];
				}
				public function can_browse( int $user_id ): bool { return user_can( $user_id, 'manage_options' ); }
				public function can_view( string $template_id, int $user_id ): bool {
					return 'example' === $template_id && $this->can_browse( $user_id );
				}
				public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
					return new TemplatePage( [ new TemplateMetadata(
						$this->source_id(), 'core-blueprint', $this->type,
						'example', 'Safe example', 'No document body', 'built-in', 'example', 'en', '1'
					) ] );
				}
			} );
		}
	}

	public function test_public_browser_semantic_requirement_and_global_admin_page(): void {
		self::assertSame( '@cb-core/template-browser', Assets::MODULE_ID );
		$requirements = PageRegistry::normalize_semantic_requirements(
			[ 'foundations' => [ 'template-browser' ] ], 'fixture:template-library'
		);
		self::assertSame( [ 'foundations' => [ 'template-browser' ], 'components' => [] ], $requirements );
		self::assertSame( 'manage_options', ( new \CoreBlueprint\Core\TemplateLibrary\AdminPage() )->capability() );
	}

	public function test_scope_is_bound_to_authenticated_user_and_exact_designer(): void {
		$token = DesignerScope::issue( 'core-blueprint', 'workflow' );
		self::assertIsString( $token );
		self::assertSame( [ 'extension' => 'core-blueprint', 'designer' => 'workflow' ], DesignerScope::verify( $token ) );
		self::assertNull( DesignerScope::issue( 'core-blueprint', 'missing-designer' ) );
		self::assertNull( DesignerScope::verify( $token . 'tampered' ) );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_user = get_userdata( $other );
		self::assertInstanceOf( WP_User::class, $other_user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $other_user, 0, 'template_library_test_fixture' ) );
		wp_set_current_user( $other );
		self::assertTrue( current_user_can( 'manage_options' ), 'Token isolation must not rely on denying an unapproved second administrator.' );
		self::assertNull( DesignerScope::verify( $token ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_read_only_transport_blocks_type_switching_and_user_escalation(): void {
		$token = DesignerScope::issue( 'core-blueprint', 'workflow' );
		self::assertIsString( $token );
		$request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/templates' );
		$request->set_header( 'X-CB-Designer-Scope', $token );
		self::assertTrue( BrowserController::permitted( $request ) );
		$response = BrowserController::templates( $request );
		self::assertSame( 200, $response->get_status() );
		$items = $response->get_data()['items'];
		self::assertCount( 1, $items );
		self::assertSame( 'workflow', $items[0]['designer'] );
		self::assertArrayNotHasKey( 'definition', $items[0] );
		self::assertArrayNotHasKey( 'payload', $items[0] );
		$request->set_param( 'designer', 'mail' );
		self::assertSame( 403, BrowserController::templates( $request )->get_status() );
		$request->set_param( 'designer', '' );
		$request->set_param( 'limit', '49' );
		self::assertSame( 400, BrowserController::templates( $request )->get_status() );
		$request->set_header( 'X-CB-Designer-Scope', 'invalid' );
		self::assertFalse( BrowserController::permitted( $request ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertFalse( BrowserController::permitted( $request ) );
		$request->set_header( 'X-CB-Designer-Scope', '' );
		self::assertFalse( BrowserController::permitted( $request ) );
	}

	public function test_sources_endpoint_never_leaks_second_designer_in_context(): void {
		$token = DesignerScope::issue( 'core-blueprint', 'workflow' );
		self::assertIsString( $token );
		$request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/sources' );
		$request->set_header( 'X-CB-Designer-Scope', $token );
		$items = BrowserController::sources( $request )->get_data()['sources'];
		self::assertCount( 1, $items );
		self::assertSame( 'workflow', $items[0]['designer'] );
	}
	public function test_category_facets_are_authorized_bounded_and_designer_scoped(): void {
		$token = DesignerScope::issue( 'core-blueprint', 'workflow' );
		self::assertIsString( $token );

		$scope_request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/sources' );
		$scope_request->set_header( 'X-CB-Designer-Scope', $token );
		$response = BrowserController::sources( $scope_request );
		self::assertSame( 200, $response->get_status() );
		$sources = $response->get_data()['sources'];
		self::assertCount( 1, $sources );
		self::assertSame( 'workflow', $sources[0]['designer'] );
		self::assertSame( [ [ 'id' => 'example', 'label' => 'Example' ] ], $sources[0]['categories'] );
		self::assertArrayNotHasKey( 'template_body', $sources[0] );
		self::assertArrayNotHasKey( 'item_count', $sources[0] );

		$global_request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/sources' );
		$global = BrowserController::sources( $global_request )->get_data()['sources'];
		self::assertCount( 2, $global );
		foreach ( $global as $source ) {
			self::assertSame( [ [ 'id' => 'example', 'label' => 'Example' ] ], $source['categories'] );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( [], TemplateSourceRegistry::source_categories() );
		wp_set_current_user( $this->admin_id );
	}

}
