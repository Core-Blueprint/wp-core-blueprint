<?php
declare(strict_types=1);

use CoreBlueprint\Core\Mail\Designer\SavedTemplateSchema;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateSource;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateStore;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\BrowserController;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Action_Transport_Test extends WP_UnitTestCase {
	private int $actor;
	private bool $was_enabled;
	private bool $had_cookie;
	private mixed $old_cookie;
	/** @var list<string> */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'tl_m1_test' ) );
		$this->had_cookie = array_key_exists( LOGGED_IN_COOKIE, $_COOKIE );
		$this->old_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
		wp_set_current_user( $this->actor );
		$expiry = time() + HOUR_IN_SECONDS;
		$session = WP_Session_Tokens::get_instance( $this->actor )->create( $expiry );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->actor, $expiry, 'logged_in', $session );
		$this->was_enabled = DesignerState::is_enabled();
		if ( ! $this->was_enabled ) {
			DesignerState::set_enabled( true, 'tl_m1_test' );
		}
		self::assertTrue( SavedTemplateSchema::install() );
	}

	public function tear_down(): void {
		global $wpdb;
		wp_set_current_user( $this->actor );
		foreach ( $this->ids as $id ) {
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM %i WHERE id = %s', SavedTemplateSchema::table(), $id
			) );
		}
		if ( DesignerState::is_enabled() !== $this->was_enabled ) {
			DesignerState::set_enabled( $this->was_enabled, 'tl_m1_test' );
		}
		if ( $this->had_cookie ) $_COOKIE[ LOGGED_IN_COOKIE ] = $this->old_cookie;
		else unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function make(): array {
		$document = WordPressTemplates::default_document( 'wordpress.new-user' );
		self::assertIsArray( $document );
		$created = SavedTemplateStore::create( 'Reusable Mail', 'personal', $this->actor, $document );
		self::assertSame( 'created', $created['status'] );
		$this->ids[] = $created['id'];
		return $created;
	}

	private function request( string $id, string $action, int $revision, array $input = [], bool $nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/template-library/manage' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( [
			'source' => SavedTemplateSource::SOURCE_ID, 'templateId' => $id,
			'action' => $action, 'revision' => $revision, 'input' => $input,
		] ) );
		return $request;
	}

	public function test_actions_discover_only_live_authorized_saved_assets(): void {
		$created = $this->make();
		$source = new SavedTemplateSource();
		$page = $source->query( new TemplateQuery( source: SavedTemplateSource::SOURCE_ID ), null );
		self::assertCount( 1, $page->items );
		self::assertSame( [ 'rename', 'duplicate', 'export-json', 'delete' ],
			TemplateSourceRegistry::actions( $page->items[0] ) );
		$built_in = new TemplateMetadata(
			SavedTemplateSource::SOURCE_ID, 'core-blueprint', 'mail',
			$created['id'], 'Not editable', '', 'built-in', 'saved', 'en', '1'
		);
		self::assertSame( [], TemplateSourceRegistry::actions( $built_in ) );
		$other = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $other );
		self::assertSame( [], TemplateSourceRegistry::actions( $page->items[0] ) );
	}

	public function test_rest_route_rechecks_nonce_cas_and_native_mail_writer(): void {
		$created = $this->make();
		$id = $created['id'];
		self::assertArrayHasKey(
			'/core-blueprint/v1/template-library/manage', rest_get_server()->get_routes()
		);
		$missing_nonce = rest_do_request( $this->request( $id, 'rename', 1, [ 'title' => 'No nonce' ], false ) );
		self::assertSame( 403, $missing_nonce->get_status() );
		$rename = rest_do_request( $this->request( $id, 'rename', 1, [ 'title' => 'Renamed Mail' ] ) );
		self::assertSame( 200, $rename->get_status() );
		self::assertSame( [ 'status' => 'renamed', 'revision' => 2 ], $rename->get_data() );
		self::assertSame( 'Renamed Mail', SavedTemplateStore::find( $id, $this->actor )['title'] );
		$stale = rest_do_request( $this->request( $id, 'delete', 1 ) );
		self::assertSame( 409, $stale->get_status() );
		self::assertSame( 'found', SavedTemplateStore::find( $id, $this->actor )['status'] );
		$export = rest_do_request( $this->request( $id, 'export-json', 2 ) );
		self::assertSame( 200, $export->get_status() );
		self::assertSame( 'ready', $export->get_data()['status'] );
		self::assertIsString( $export->get_data()['data'] );
		self::assertSame( 2, SavedTemplateStore::find( $id, $this->actor )['revision'] );
		$duplicate = rest_do_request( $this->request( $id, 'duplicate', 2, [ 'title' => 'Copy' ] ) );
		self::assertSame( 201, $duplicate->get_status() );
		$copy = $duplicate->get_data();
		self::assertSame( 'created', $copy['status'] );
		$this->ids[] = $copy['id'];
		self::assertNotSame( $id, $copy['id'] );
		self::assertSame( 'Copy', SavedTemplateStore::find( $copy['id'], $this->actor )['title'] );
		$delete = rest_do_request( $this->request( $id, 'delete', 2 ) );
		self::assertSame( 200, $delete->get_status() );
		self::assertSame( 'deleted', $delete->get_data()['status'] );
	}

	public function test_unknown_actions_shape_and_scope_are_rejected_before_domain_write(): void {
		$created = $this->make();
		$id = $created['id'];
		self::assertSame( 403, rest_do_request( $this->request( $id, 'edit', 1 ) )->get_status() );
		self::assertSame( 400, rest_do_request( $this->request( $id, 'rename', 1 ) )->get_status() );
		$request = $this->request( $id, 'delete', 1 );
		$request->set_header( 'X-CB-Designer-Scope', 'invalid' );
		self::assertSame( 403, rest_do_request( $request )->get_status() );
		self::assertSame( 1, SavedTemplateStore::find( $id, $this->actor )['revision'] );
	}
}
