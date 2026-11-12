<?php
declare(strict_types=1);

use CoreBlueprint\Core\DataExchange\Engine;
use CoreBlueprint\Core\DataExchange\Foundation;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Interoperability\Registry;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateController;
use CoreBlueprint\Core\Mail\Designer\SavedTemplatePortability;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateSchema;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateStore;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;

/** Real Mail-owned T4 entity: no test-only Data Exchange adapter. */
final class CB_Mail_Saved_Template_Portability_Test extends WP_UnitTestCase {
	private int $actor = 0;
	private bool $initial_enabled = false;
	/** @var list<string> */
	private array $identities = [];

	public function set_up(): void {
		parent::set_up();
		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mail_portability_test' ) );
		wp_set_current_user( $this->actor );
		$this->initial_enabled = DesignerState::is_enabled();
		if ( ! $this->initial_enabled ) DesignerState::set_enabled( true, 'mail_portability_test' );
		self::assertTrue( SavedTemplateSchema::install() );
		Registry::_reset_for_testing();
	}

	public function tear_down(): void {
		global $wpdb;
		wp_set_current_user( $this->actor );
		foreach ( $this->identities as $id ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %s', SavedTemplateSchema::table(), $id ) );
		}
		if ( DesignerState::is_enabled() !== $this->initial_enabled ) {
			DesignerState::set_enabled( $this->initial_enabled, 'mail_portability_test' );
		}
		Registry::_reset_for_testing();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function source(): array {
		$doc = WordPressTemplates::default_document( 'wordpress.new-user' );
		self::assertIsArray( $doc );
		$created = SavedTemplateStore::create( 'Portable welcome', 'personal', $this->actor, $doc );
		self::assertSame( 'created', $created['status'] );
		$this->identities[] = $created['id'];
		return $created;
	}

	private function destination(): string {
		$id = 'mail.saved.' . bin2hex( random_bytes( 16 ) );
		$this->identities[] = $id;
		return $id;
	}

	private function context( string $id ): array {
		return [ 'intent' => 'create_personal_copy', 'destination_id' => $id ];
	}

	private function file( array $source ): string {
		$json = Engine::export_json( 'core-blueprint', SavedTemplatePortability::ENTITY, [
			'source_id' => $source['id'], 'revision' => $source['revision'],
		] );
		self::assertIsString( $json );
		return $json;
	}

	public function test_native_entity_exports_exact_envelope_and_imports_personal_new_copy(): void {
		$source = $this->source();
		$json = $this->file( $source );
		$decoded = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		self::assertSame( [
			'format', 'format_version', 'extension_id', 'entity', 'schema_version', 'exported_at', 'records',
		], array_keys( $decoded ) );
		self::assertSame( 'core-blueprint-data-exchange', $decoded['format'] );
		self::assertSame( 'core-blueprint', $decoded['extension_id'] );
		self::assertSame( 'template.mail', $decoded['entity'] );
		self::assertCount( 1, $decoded['records'] );
		self::assertSame( [], $decoded['records'][0]['resources'] );
		self::assertArrayNotHasKey( 'owner_id', $decoded['records'][0] );
		self::assertArrayNotHasKey( 'source_id', $decoded['records'][0] );
		self::assertNotSame( $source['id'], $decoded['records'][0]['portable_id'] );

		$destination = $this->destination();
		$ctx = $this->context( $destination );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $ctx );
		self::assertIsArray( $preview );
		self::assertTrue( $preview['valid'] );
		self::assertSame( 1, $preview['counts']['create'] );
		self::assertSame( 'missing', SavedTemplateStore::find( $destination, $this->actor )['status'] );
		$applied = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $ctx );
		self::assertIsArray( $applied );
		self::assertSame( 'complete', $applied['status'] );
		self::assertSame( 1, $applied['applied_count'] );
		self::assertSame( $destination, $applied['items'][0]['reference'] );
		$copy = SavedTemplateStore::find( $destination, $this->actor );
		$original = SavedTemplateStore::find( $source['id'], $this->actor );
		self::assertSame( 'found', $copy['status'] );
		self::assertSame( 'personal', $copy['visibility'] );
		self::assertSame( $this->actor, $copy['owner_id'] );
		self::assertSame( $original['document'], $copy['document'] );
		self::assertSame( 'Portable welcome', $copy['title'] );
		self::assertSame( 1, $copy['revision'] );

		$replay = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $ctx );
		self::assertIsArray( $replay );
		self::assertFalse( $replay['valid'] );
		self::assertSame( 1, SavedTemplateStore::find( $destination, $this->actor )['revision'] );
	}

	public function test_import_rejects_media_unknown_fields_wrong_schema_tampering_and_stale_fingerprint(): void {
		$json = $this->file( $this->source() );
		$destination = $this->destination();
		$ctx = $this->context( $destination );
		$bad = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		$bad['records'][0]['resources'] = [ [ 'path' => '/tmp/secret' ] ];
		$preview = Engine::preview_json( wp_json_encode( $bad ), Foundation::MODE_CREATE_ONLY, $ctx );
		self::assertIsArray( $preview );
		self::assertFalse( $preview['valid'] );

		$bad = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		$bad['records'][0]['privilege'] = 'administrator';
		self::assertFalse( Engine::preview_json( wp_json_encode( $bad ), Foundation::MODE_CREATE_ONLY, $ctx )['valid'] );

		$bad = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		$bad['schema_version'] = 9;
		self::assertWPError( Engine::preview_json( wp_json_encode( $bad ), Foundation::MODE_CREATE_ONLY, $ctx ) );

		$bad = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		$bad['records'][0]['title'] = 'Tampered title';
		self::assertFalse( Engine::preview_json( wp_json_encode( $bad ), Foundation::MODE_CREATE_ONLY, $ctx )['valid'] );

		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $ctx );
		self::assertTrue( $preview['valid'] );
		$other = $this->destination();
		self::assertWPError( Engine::apply_json(
			$json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $this->context( $other )
		) );
		self::assertSame( 'missing', SavedTemplateStore::find( $destination, $this->actor )['status'] );
		self::assertSame( 'missing', SavedTemplateStore::find( $other, $this->actor )['status'] );
	}

	public function test_export_revision_acl_disabled_designer_and_existing_destination_fail_closed(): void {
		$source = $this->source();
		$json = $this->file( $source );
		$wrong_revision = Engine::export_json( 'core-blueprint', SavedTemplatePortability::ENTITY, [
			'source_id' => $source['id'], 'revision' => 99,
		] );
		self::assertWPError( $wrong_revision );

		$other_actor = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $other_actor );
		self::assertWPError( Engine::export_json( 'core-blueprint', SavedTemplatePortability::ENTITY, [
			'source_id' => $source['id'], 'revision' => 1,
		] ) );
		wp_set_current_user( $this->actor );

		$collision = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->context( $source['id'] ) );
		self::assertFalse( $collision['valid'] );

		DesignerState::set_enabled( false, 'mail_portability_test' );
		self::assertWPError( Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY,
			$this->context( $this->destination() )
		) );
		DesignerState::set_enabled( true, 'mail_portability_test' );
	}

	public function test_rest_endpoints_require_authenticated_nonce_and_exact_import_confirmation(): void {
		$source = $this->source();
		$nonce = wp_create_nonce( 'wp_rest' );
		$export = new WP_REST_Request( 'POST', '/core-blueprint/v1/mail-templates/export' );
		$export->set_header( 'X-WP-Nonce', $nonce );
		$export->set_header( 'Content-Type', 'application/json' );
		$export->set_body( wp_json_encode( [ 'id' => $source['id'], 'revision' => 1 ] ) );
		self::assertTrue( SavedTemplateController::permitted( $export ) );
		$file = SavedTemplateController::export( $export );
		self::assertSame( 'ready', $file->get_data()['status'] );
		$json = $file->get_data()['data'];

		$destination = $this->destination();
		$preview = new WP_REST_Request( 'POST', '/core-blueprint/v1/mail-templates/preview' );
		$preview->set_header( 'X-WP-Nonce', $nonce );
		$preview->set_header( 'Content-Type', 'application/json' );
		$preview->set_body( wp_json_encode( [ 'file' => $json, 'destinationId' => $destination ] ) );
		self::assertTrue( SavedTemplateController::permitted( $preview ) );
		$preflight = SavedTemplateController::preview( $preview );
		self::assertSame( 'ready', $preflight->get_data()['status'] );

		$apply = new WP_REST_Request( 'POST', '/core-blueprint/v1/mail-templates/import' );
		$apply->set_header( 'X-WP-Nonce', $nonce );
		$apply->set_header( 'Content-Type', 'application/json' );
		$apply->set_body( wp_json_encode( [
			'file' => $json, 'destinationId' => $destination,
			'fingerprint' => $preflight->get_data()['preview']['fingerprint'],
		] ) );
		self::assertTrue( SavedTemplateController::permitted( $apply ) );
		self::assertSame( 'created', SavedTemplateController::import( $apply )->get_data()['status'] );
		self::assertSame( 'found', SavedTemplateStore::find( $destination, $this->actor )['status'] );
		self::assertSame( 'invalid', SavedTemplateController::import( $apply )->get_data()['status'] );
		wp_set_current_user( 0 );
		self::assertFalse( SavedTemplateController::permitted( $apply ) );
	}
}
