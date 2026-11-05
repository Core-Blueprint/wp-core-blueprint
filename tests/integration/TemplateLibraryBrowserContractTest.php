<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\Assets;
use CoreBlueprint\Core\TemplateLibrary\BrowserController;
use CoreBlueprint\Core\TemplateLibrary\DesignerScope;
use CoreBlueprint\Core\TemplateLibrary\TemplateDesignerAuthorityInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateCategorySourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Browser_Contract_Test extends WP_UnitTestCase {
	private int $admin_id;
	private bool $had_login_cookie = false;
	private mixed $previous_login_cookie = null;

	private function use_session( int $user_id, ?string $session = null ): string {
		wp_set_current_user( $user_id );
		$expires = time() + HOUR_IN_SECONDS;
		$token = $session ?? WP_Session_Tokens::get_instance( $user_id )->create( $expires );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token );
		return $token;
	}

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $this->admin_id );
		self::assertInstanceOf( WP_User::class, $admin );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 'template_library_test_fixture' ) );
		$this->had_login_cookie = array_key_exists( LOGGED_IN_COOKIE, $_COOKIE );
		$this->previous_login_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
		$this->use_session( $this->admin_id );
		self::assertTrue( current_user_can( 'manage_options' ), 'An explicit approved administrator is required for the browser fixture.' );
		set_current_screen( 'core-blueprint-test-workflow' );
		TemplateSourceRegistry::_reset_for_testing();
		add_action( 'core_blueprint_register_template_sources', [ $this, 'register_sources' ] );
	}

	public function tear_down(): void {
		remove_action( 'core_blueprint_register_template_sources', [ $this, 'register_sources' ] );
		TemplateSourceRegistry::_reset_for_testing();
		set_current_screen( 'front' );
		if ( $this->had_login_cookie ) { $_COOKIE[ LOGGED_IN_COOKIE ] = $this->previous_login_cookie; }
		else { unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); }
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function register_sources(): void {
		foreach ( [ 'workflow', 'mail' ] as $type ) {
			TemplateSourceRegistry::register( new class( $type ) implements TemplateSourceInterface, TemplateCategorySourceInterface, TemplateDesignerAuthorityInterface {
				public function __construct( private string $type ) {}
				public function source_id(): string { return 'core-blueprint-test-' . $this->type; }
				public function extension_id(): string { return 'core-blueprint'; }
				public function designer_type(): string { return $this->type; }
				public function categories( int $user_id ): array {
					do_action( 'core_blueprint_test_category_provider_invoked', $this->type );
					return $this->can_browse( $user_id )
						? [ 'example' => 'Example', 'invalid category' => 'Invalid', 'control' => "Bad\nLabel" ] : [];
				}
				public function can_browse( int $user_id ): bool { return user_can( $user_id, 'manage_options' ); }
				public function can_launch_designer( string $resource_type, string $resource_key, int $user_id ): bool {
					$screen = get_current_screen();
					return $screen && $screen->id === 'core-blueprint-test-' . $this->type
						&& $this->can_access_designer_resource( $resource_type, $resource_key, $user_id );
				}
				public function can_access_designer_resource( string $resource_type, string $resource_key, int $user_id ): bool {
					return $resource_type === $this->type && $resource_key === 'fixture-' . $this->type
						&& $this->can_browse( $user_id )
						&& true === apply_filters( 'core_blueprint_test_designer_resource_allowed', true );
				}
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
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		self::assertIsString( $token );
		self::assertSame( [ 'extension' => 'core-blueprint', 'designer' => 'workflow' ], DesignerScope::verify( $token ) );
		self::assertNull( DesignerScope::issue( 'core-blueprint-test-missing-designer', 'workflow', 'fixture-workflow' ) );
		self::assertNull( DesignerScope::verify( $token . 'tampered' ) );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_user = get_userdata( $other );
		self::assertInstanceOf( WP_User::class, $other_user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $other_user, 0, 'template_library_test_fixture' ) );
		$this->use_session( $other );
		self::assertTrue( current_user_can( 'manage_options' ), 'Token isolation must not rely on denying an unapproved second administrator.' );
		self::assertNull( DesignerScope::verify( $token ) );
		$this->use_session( $this->admin_id );
	}

	public function test_read_only_transport_blocks_type_switching_and_user_escalation(): void {
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
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
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		self::assertIsString( $token );
		$request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/sources' );
		$request->set_header( 'X-CB-Designer-Scope', $token );
		$items = BrowserController::sources( $request )->get_data()['sources'];
		self::assertCount( 1, $items );
		self::assertSame( 'workflow', $items[0]['designer'] );
	}
	public function test_category_facets_are_authorized_bounded_and_designer_scoped(): void {
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		self::assertIsString( $token );

		$invoked = [];
		$observe = static function ( string $designer ) use ( &$invoked ): void { $invoked[] = $designer; };
		add_action( 'core_blueprint_test_category_provider_invoked', $observe );
		$scope_request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/sources' );
		$scope_request->set_header( 'X-CB-Designer-Scope', $token );
		$response = BrowserController::sources( $scope_request );
		remove_action( 'core_blueprint_test_category_provider_invoked', $observe );
		self::assertSame( [ 'workflow' ], $invoked, 'Scoped request must not call an unrelated Designer category provider.' );
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
		$this->use_session( $this->admin_id );
	}
 
	public function test_scope_refuses_wrong_launch_and_missing_resource(): void {
		set_current_screen( 'core-blueprint_page_core-blueprint-template-library' );
		self::assertNull( DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' ) );
		set_current_screen( 'core-blueprint-test-workflow' );
		self::assertNull( DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'missing' ) );
		self::assertNull( DesignerScope::issue( 'core-blueprint-test-mail', 'mail', 'fixture-mail' ) );
	}

	public function test_scope_fails_closed_without_live_wp_session(): void {
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		self::assertIsString( $token );
		$cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		self::assertNull( DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' ) );
		self::assertNull( DesignerScope::verify( $token ) );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		WP_Session_Tokens::get_instance( $this->admin_id )->destroy( wp_get_session_token() );
		self::assertNull( DesignerScope::verify( $token ), 'Revoked sessions deny a previously valid scope.' );
	}

	public function test_scope_rechecks_resource_authority_and_same_user_session(): void {
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		self::assertIsString( $token );
		$deny = static fn (): bool => false;
		add_filter( 'core_blueprint_test_designer_resource_allowed', $deny );
		try {
			self::assertNull( DesignerScope::verify( $token ), 'Resource revocation must be checked for every request.' );
		} finally {
			remove_filter( 'core_blueprint_test_designer_resource_allowed', $deny );
		}
		$original = wp_get_session_token();
		$another = WP_Session_Tokens::get_instance( $this->admin_id )->create( time() + HOUR_IN_SECONDS );
		$this->use_session( $this->admin_id, $another );
		self::assertNull( DesignerScope::verify( $token ), 'Another session of the same user must be denied.' );
		$this->use_session( $this->admin_id, $original );
		self::assertSame( [ 'extension' => 'core-blueprint', 'designer' => 'workflow' ], DesignerScope::verify( $token ) );
	}

	public function test_multiple_authorized_designer_contexts_stay_isolated(): void {
		$workflow = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		set_current_screen( 'core-blueprint-test-mail' );
		$mail = DesignerScope::issue( 'core-blueprint-test-mail', 'mail', 'fixture-mail' );
		self::assertIsString( $workflow );
		self::assertIsString( $mail );
		self::assertSame( [ 'extension' => 'core-blueprint', 'designer' => 'workflow' ], DesignerScope::verify( $workflow ) );
		self::assertSame( [ 'extension' => 'core-blueprint', 'designer' => 'mail' ], DesignerScope::verify( $mail ) );
		$request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/templates' );
		$request->set_header( 'X-CB-Designer-Scope', $mail );
		$request->set_param( 'designer', 'workflow' );
		self::assertTrue( BrowserController::permitted( $request ) );
		self::assertSame( 403, BrowserController::templates( $request )->get_status() );
	}

	public function test_provider_authority_exception_fails_closed(): void {
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		self::assertIsString( $token );
		$broken = static function (): never { throw new RuntimeException( 'Untrusted resource check failed.' ); };
		add_filter( 'core_blueprint_test_designer_resource_allowed', $broken );
		try {
			self::assertNull( DesignerScope::verify( $token ) );
			self::assertNull( DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' ) );
		} finally {
			remove_filter( 'core_blueprint_test_designer_resource_allowed', $broken );
		}
	}

	public function test_expired_and_context_modified_tokens_fail_closed(): void {
		$token = DesignerScope::issue( 'core-blueprint-test-workflow', 'workflow', 'fixture-workflow' );
		self::assertIsString( $token );
		[ $encoded ] = explode( '.', $token, 2 );
		$data = json_decode( (string) base64_decode( strtr( $encoded, '-_', '+/' ), true ), true, 8, JSON_THROW_ON_ERROR );
		foreach ( [
			[ 'expires', time() - 1 ], [ 'designer', 'mail' ],
			[ 'resource_key', 'other-workflow' ], [ 'site', (int) get_current_blog_id() + 1 ],
		] as [ $key, $value ] ) {
			$changed = $data;
			$changed[ $key ] = $value;
			$payload = rtrim( strtr( base64_encode( json_encode( $changed, JSON_THROW_ON_ERROR ) ), '+/', '-_' ), '=' );
			$signed = $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
			self::assertNull( DesignerScope::verify( $signed ), 'Changed ' . $key . ' must be denied.' );
		}
	}

}
