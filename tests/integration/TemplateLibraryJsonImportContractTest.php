<?php
declare(strict_types=1);

use CoreBlueprint\Core\DataExchange\Engine;
use CoreBlueprint\Core\Interoperability\Registry;
use CoreBlueprint\Core\Mail\Designer\NativeTemplateSource;
use CoreBlueprint\Core\Mail\Designer\SavedTemplatePortability;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateSchema;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateSource;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateStore;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\BrowserController;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

/** MC-02C: JSON import lives in the shared Library, but Mail alone owns data. */
final class CB_Template_Library_Json_Import_Contract_Test extends WP_UnitTestCase {
	private int $actor = 0;
	private bool $was_enabled = false;
	private bool $had_cookie = false;
	private mixed $previous_cookie = null;
	/** @var list<string> */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mc02c_json_import' ) );
		wp_set_current_user( $this->actor );
		$this->had_cookie = array_key_exists( LOGGED_IN_COOKIE, $_COOKIE );
		$this->previous_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
		$expires = time() + HOUR_IN_SECONDS;
		$session = WP_Session_Tokens::get_instance( $this->actor )->create( $expires );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->actor, $expires, 'logged_in', $session );
		$this->was_enabled = DesignerState::is_enabled();
		if ( ! $this->was_enabled ) DesignerState::set_enabled( true, 'mc02c_json_import' );
		self::assertTrue( SavedTemplateSchema::install() );
		Registry::_reset_for_testing();
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
			DesignerState::set_enabled( $this->was_enabled, 'mc02c_json_import' );
		}
		Registry::_reset_for_testing();
		if ( $this->had_cookie ) $_COOKIE[ LOGGED_IN_COOKIE ] = $this->previous_cookie;
		else unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function document(): array {
		$document = WordPressTemplates::default_document( 'wordpress.new-user' );
		self::assertIsArray( $document );
		return $document;
	}

	private function fixture(): array {
		$created = SavedTemplateStore::create(
			'MC02C portable fixture', 'personal', $this->actor, $this->document()
		);
		self::assertSame( 'created', $created['status'] );
		$this->ids[] = $created['id'];
		return $created;
	}

	private function json( array $created ): string {
		$json = Engine::export_json( 'core-blueprint', SavedTemplatePortability::ENTITY, [
			'source_id' => $created['id'], 'revision' => $created['revision'],
		] );
		self::assertIsString( $json );
		return $json;
	}

	private function destination(): string {
		$id = 'mail.saved.' . bin2hex( random_bytes( 16 ) );
		$this->ids[] = $id;
		return $id;
	}

	private function request( array $body, bool $nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/template-library/import-json' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	public function test_json_source_admission_is_opt_in_and_no_zip_fallback(): void {
		$descriptor = TemplateSourceRegistry::json_import_descriptor(
			SavedTemplateSource::SOURCE_ID, null
		);
		self::assertSame( [ 'version' => 1, 'destinationPrefix' => 'mail.saved.' ], $descriptor );
		self::assertNull( TemplateSourceRegistry::json_import_descriptor(
			NativeTemplateSource::SOURCE_ID, null
		) );
		$sources = BrowserController::sources( new WP_REST_Request(
			'GET', '/core-blueprint/v1/template-library/sources'
		) )->get_data()['sources'];
		$by_id = array_column( $sources, null, 'id' );
		self::assertSame( $descriptor, $by_id[ SavedTemplateSource::SOURCE_ID ]['jsonImport'] );
		self::assertNull( $by_id[ NativeTemplateSource::SOURCE_ID ]['jsonImport'] );
		self::assertNull( $by_id[ SavedTemplateSource::SOURCE_ID ]['resourceImportDestination'] );
	}

	public function test_shared_json_import_previews_then_creates_only_one_personal_copy(): void {
		$existing = $this->fixture();
		$json = $this->json( $existing );
		$id = $this->destination();
		$preview = BrowserController::import_json( $this->request( [
			'phase' => 'preview', 'source' => SavedTemplateSource::SOURCE_ID,
			'destinationId' => $id, 'file' => $json,
		] ) );
		self::assertSame( 200, $preview->get_status() );
		$payload = $preview->get_data();
		self::assertSame( 'ready', $payload['status'] );
		self::assertSame( SavedTemplateSource::SOURCE_ID, $payload['source'] );
		self::assertSame( $id, $payload['destinationId'] );
		self::assertSame( [ 'create' => 1 ], $payload['preview']['counts'] );
		self::assertSame( 'missing', SavedTemplateStore::find( $id, $this->actor )['status'] );
		$apply = BrowserController::import_json( $this->request( [
			'phase' => 'apply', 'source' => SavedTemplateSource::SOURCE_ID,
			'destinationId' => $id, 'file' => $json,
			'fingerprint' => $payload['preview']['fingerprint'],
		] ) );
		self::assertSame( 201, $apply->get_status() );
		self::assertSame( [
			'status' => 'created', 'source' => SavedTemplateSource::SOURCE_ID,
			'id' => $id, 'revision' => 1,
		], $apply->get_data() );
		$copy = SavedTemplateStore::find( $id, $this->actor );
		self::assertSame( 'found', $copy['status'] );
		self::assertSame( 'personal', $copy['visibility'] );
		self::assertSame( $this->actor, $copy['owner_id'] );
		self::assertSame( $this->document(), $copy['document'] );
		self::assertSame( 'found', SavedTemplateStore::find( $existing['id'], $this->actor )['status'] );
		self::assertSame( 400, BrowserController::import_json( $this->request( [
			'phase' => 'preview', 'source' => SavedTemplateSource::SOURCE_ID,
			'destinationId' => $id, 'file' => $json,
		] ) )->get_status(), 'the existing destination cannot be silently overwritten' );
	}

	public function test_import_refuses_wrong_source_schema_nonce_scope_and_actor(): void {
		$json = $this->json( $this->fixture() );
		$id = $this->destination();
		$valid = [
			'phase' => 'preview', 'source' => SavedTemplateSource::SOURCE_ID,
			'destinationId' => $id, 'file' => $json,
		];
		self::assertSame( 403, rest_do_request( $this->request( $valid, false ) )->get_status() );
		$foreign = $valid;
		$foreign['source'] = NativeTemplateSource::SOURCE_ID;
		self::assertSame( 403, BrowserController::import_json( $this->request( $foreign ) )->get_status() );
		$wrong_prefix = $valid;
		$wrong_prefix['destinationId'] = 'other.saved.' . bin2hex( random_bytes( 16 ) );
		self::assertSame( 403, BrowserController::import_json( $this->request( $wrong_prefix ) )->get_status() );
		$extended = $valid;
		$extended['document'] = [ 'unexpected' => 'payload' ];
		self::assertSame( 400, BrowserController::import_json( $this->request( $extended ) )->get_status() );
		$bad_fingerprint = $valid;
		$bad_fingerprint['phase'] = 'apply';
		$bad_fingerprint['fingerprint'] = 'not-a-fingerprint';
		self::assertSame( 403, BrowserController::import_json( $this->request( $bad_fingerprint ) )->get_status() );
		$invalid_scope = $this->request( $valid );
		$invalid_scope->set_header( 'X-CB-Designer-Scope', 'spoofed-designer-scope' );
		self::assertSame( 403, BrowserController::import_json( $invalid_scope )->get_status() );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		self::assertSame( 403, BrowserController::import_json( $this->request( $valid ) )->get_status() );
		wp_set_current_user( $this->actor );
		self::assertSame( 'missing', SavedTemplateStore::find( $id, $this->actor )['status'] );
	}
}
