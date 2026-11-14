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
			'action' => $action, 'revision' => $revision,
			'input' => [] === $input ? (object) [] : $input,
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
		$listing_request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/templates' );
		$listing_request->set_param( 'source', SavedTemplateSource::SOURCE_ID );
		$listing = BrowserController::templates( $listing_request );
		self::assertSame( 200, $listing->get_status() );
		self::assertCount( 1, $listing->get_data()['items'] );
		self::assertSame( [ 'rename', 'duplicate', 'export-json', 'delete' ],
			$listing->get_data()['items'][0]['actions'] );
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
		$wrong_input = $this->request( $id, 'delete', 1 );
		$wrong_input->set_body( wp_json_encode( [
			'source' => SavedTemplateSource::SOURCE_ID, 'templateId' => $id,
			'action' => 'delete', 'revision' => 1, 'input' => [],
		] ) );
		self::assertSame( 400, rest_do_request( $wrong_input )->get_status() );
		$request = $this->request( $id, 'delete', 1 );
		$request->set_header( 'X-CB-Designer-Scope', 'invalid' );
		self::assertSame( 403, rest_do_request( $request )->get_status() );
		self::assertSame( 1, SavedTemplateStore::find( $id, $this->actor )['revision'] );
	}
	/** @param list<array<string,mixed>> $items */
	private function bulk_request( array $items, bool $nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/template-library/bulk-export' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( [ 'items' => $items ] ) );
		return $request;
	}

	public function test_bounded_global_bulk_json_zip_reuses_per_template_export_authority(): void {
		self::assertTrue( class_exists( ZipArchive::class ) );
		$first = $this->make();
		$second = $this->make();
		$items = [
			[ 'source' => SavedTemplateSource::SOURCE_ID, 'templateId' => $first['id'], 'revision' => 1 ],
			[ 'source' => SavedTemplateSource::SOURCE_ID, 'templateId' => $second['id'], 'revision' => 1 ],
		];
		self::assertArrayHasKey(
			'/core-blueprint/v1/template-library/bulk-export', rest_get_server()->get_routes()
		);
		$reply = rest_do_request( $this->bulk_request( $items ) );
		self::assertSame( 200, $reply->get_status() );
		self::assertSame( 'private, no-store', $reply->get_headers()['Cache-Control'] ?? null );
		$data = $reply->get_data();
		self::assertSame( 'ready', $data['status'] );
		self::assertSame( 'core-blueprint-bulk-json-v1', $data['format'] );
		self::assertSame( 2, $data['count'] );
		$bytes = base64_decode( $data['data'], true );
		self::assertIsString( $bytes );
		$temp = tempnam( get_temp_dir(), 'cbtl-unit-' );
		self::assertIsString( $temp );
		$zip = new ZipArchive();
		try {
			self::assertGreaterThan( 0, file_put_contents( $temp, $bytes ) );
			self::assertTrue( $zip->open( $temp ) );
			self::assertSame( 3, $zip->numFiles );
			$manifest = json_decode( $zip->getFromName( 'manifest.json' ), true );
			self::assertSame( 'core-blueprint-bulk-json-v1', $manifest['format'] );
			self::assertCount( 2, $manifest['items'] );
			foreach ( $items as $index => $item ) {
				$record = $manifest['items'][ $index ];
				self::assertSame( $item['templateId'], $record['templateId'] );
				self::assertSame( $item['revision'], $record['revision'] );
				$entry = $zip->getFromName( $record['file'] );
				self::assertIsString( $entry );
				self::assertSame( hash( 'sha256', $entry ), $record['sha256'] );
				self::assertIsArray( json_decode( $entry, true ) );
			}
		} finally {
			$zip->close();
			unlink( $temp );
		}
		self::assertSame( 'found', SavedTemplateStore::find( $first['id'], $this->actor )['status'] );
		self::assertSame( 'found', SavedTemplateStore::find( $second['id'], $this->actor )['status'] );
	}

	public function test_bulk_zip_refuses_nonce_duplicates_stale_revision_and_scope(): void {
		$record = $this->make();
		$good = [
			'source' => SavedTemplateSource::SOURCE_ID,
			'templateId' => $record['id'], 'revision' => 1,
		];
		self::assertSame( 403, rest_do_request( $this->bulk_request( [ $good ], false ) )->get_status() );
		self::assertSame( 400, rest_do_request( $this->bulk_request( [ $good, $good ] ) )->get_status() );
		self::assertSame( 400, rest_do_request( $this->bulk_request( array_fill( 0, 13, $good ) ) )->get_status() );
		$built_in = [
			'source' => \CoreBlueprint\Core\Mail\Designer\NativeTemplateSource::SOURCE_ID,
			'templateId' => 'wordpress.new-user', 'revision' => 1,
		];
		self::assertSame( 403, rest_do_request( $this->bulk_request( [ $built_in ] ) )->get_status() );
		$stale = $good;
		$stale['revision'] = 2;
		self::assertSame( 409, rest_do_request( $this->bulk_request( [ $stale ] ) )->get_status() );
		$scoped = $this->bulk_request( [ $good ] );
		$scoped->set_header( 'X-CB-Designer-Scope', 'not-an-authorized-scope' );
		self::assertSame( 403, rest_do_request( $scoped )->get_status() );
		$invalid = $this->bulk_request( [ $good ] );
		$invalid->set_body( '{"items":[{"source":"' . SavedTemplateSource::SOURCE_ID . '","templateId":"'
			. $record['id'] . '","revision":1,"document":{}}]}' );
		self::assertSame( 400, rest_do_request( $invalid )->get_status() );
		self::assertSame( 'found', SavedTemplateStore::find( $record['id'], $this->actor )['status'] );
	}

}
