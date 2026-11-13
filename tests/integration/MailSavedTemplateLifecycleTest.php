<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateSchema;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateStore;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateSource;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateController;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSort;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Mail_Saved_Template_Lifecycle_Test extends WP_UnitTestCase {
	private int $actor = 0;
	private bool $initial_enabled = false;
	/** @var list<string> */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mail_saved_template_fixture' ) );
		wp_set_current_user( $this->actor );
		$this->initial_enabled = DesignerState::is_enabled();
		if ( ! $this->initial_enabled ) {
			DesignerState::set_enabled( true, 'mail_saved_template_fixture' );
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
		if ( DesignerState::is_enabled() !== $this->initial_enabled ) {
			DesignerState::set_enabled( $this->initial_enabled, 'mail_saved_template_fixture' );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function make( string $origin = 'personal' ): array {
		$document = WordPressTemplates::default_document( 'wordpress.new-user' );
		self::assertIsArray( $document );
		$created = SavedTemplateStore::create( 'Reusable message', $origin, $this->actor, $document );
		self::assertSame( 'created', $created['status'] );
		$this->ids[] = $created['id'];
		return $created;
	}

	public function test_personal_saved_asset_is_scoped_and_can_be_applied_without_creating_mail_resources(): void {
		$item = $this->make();
		$source = new SavedTemplateSource();
		self::assertTrue( $source->can_view( $item['id'], $this->actor ) );
		self::assertTrue( $source->can_manage( $item['id'], $this->actor ) );
		self::assertFalse( $source->can_access_designer_resource(
			Repository::MAIL, $item['id'], $this->actor
		) );
		self::assertTrue( $source->can_access_designer_resource(
			Repository::MAIL, 'wordpress.new-user', $this->actor
		) );
		$query = new TemplateQuery( source: SavedTemplateSource::SOURCE_ID, origin: 'personal' );
		$page = $source->query( $query, null );
		self::assertCount( 1, $page->items );
		self::assertSame( 'personal', $page->items[0]->origin );
		self::assertSame( $item['id'], $page->items[0]->id );
		self::assertArrayNotHasKey( 'document', get_object_vars( $page->items[0] ) );
		self::assertSame(
			WordPressTemplates::default_document( 'wordpress.new-user' ),
			$source->instantiate_for_apply(
				$item['id'], Repository::MAIL, 'wordpress.new-user', $this->actor
			)
		);
		self::assertNull( $source->instantiate_for_apply(
			$item['id'], Repository::REPORTS, 'maintenance', $this->actor
		) );

		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $other );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mail_saved_other_admin_fixture' ) );
		wp_set_current_user( $other );
		self::assertFalse( $source->can_view( $item['id'], $other ) );
		self::assertSame( [], $source->query( $query, null )->items );
		self::assertSame( 'missing', SavedTemplateStore::find( $item['id'], $other )['status'] );
	}

	public function test_site_assets_are_visible_to_other_authorized_admins(): void {
		$item = $this->make( 'site' );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $other );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mail_saved_site_admin_fixture' ) );
		wp_set_current_user( $other );
		self::assertTrue( ( new SavedTemplateSource() )->can_view( $item['id'], $other ) );
		self::assertSame( 'found', SavedTemplateStore::find( $item['id'], $other )['status'] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		self::assertFalse( ( new SavedTemplateSource() )->can_view( $item['id'], $subscriber ) );
	}

	/** Physical MariaDB parity: BINARY SQL title/id order must equal PHP strcmp. */
	public function test_sorted_mail_saved_source_uses_exact_bytewise_utf8_order_and_keyset(): void {
		$doc = WordPressTemplates::default_document( 'wordpress.new-user' );
		self::assertIsArray( $doc );
		$names = [ 'beta', 'Alpha', 'alpha', 'Éclair', 'Alpha' ];
		$expected = [];
		foreach ( $names as $title ) {
			$created = SavedTemplateStore::create( $title, 'personal', $this->actor, $doc );
			self::assertSame( 'created', $created['status'] );
			$this->ids[] = $created['id'];
			$expected[] = [ 'title' => $title, 'id' => $created['id'] ];
		}
		usort( $expected, static fn ( array $a, array $b ): int =>
			strcmp( $a['title'], $b['title'] ) ?: strcmp( $a['id'], $b['id'] )
		);
		$source = new SavedTemplateSource();
		$query = new TemplateQuery(
			source: SavedTemplateSource::SOURCE_ID, origin: 'personal',
			limit: 2, sort: TemplateSort::TITLE_ASC
		);
		$actual = [];
		$after = null;
		for ( $round = 0; $round < 4; ++$round ) {
			$page = $source->query_sorted( $query, $after );
			self::assertLessThanOrEqual( 2, count( $page->items ) );
			self::assertNull( $page->next_cursor, 'The global cursor belongs to Base.' );
			if ( ! $page->items ) break;
			foreach ( $page->items as $item ) {
				$actual[] = [ 'title' => $item->title, 'id' => $item->id ];
				self::assertArrayNotHasKey( 'document', get_object_vars( $item ) );
			}
			$after = TemplateSort::position( $page->items[ count( $page->items ) - 1 ] );
		}
		self::assertSame( $expected, $actual );
		self::assertSame( [ 'title' => 'Alpha', 'id' => $expected[0]['id'] ],
			$actual[0], 'The first tie is ordered by binary saved template ID.' );
		$other_source_after = [
			'title' => 'Alpha', 'source' => 'a-earlier-source', 'id' => 'a',
		];
		$inclusive = $source->query_sorted( $query, $other_source_after );
		self::assertSame( 'Alpha', $inclusive->items[0]->title,
			'An earlier provider has not consumed same-titled Mail records.' );
		$later_source_after = [
			'title' => 'Alpha', 'source' => 'z-later-source', 'id' => 'a',
		];
		$exclusive = $source->query_sorted( $query, $later_source_after );
		self::assertSame( 'alpha', $exclusive->items[0]->title,
			'An alphabetically later provider already passed same-titled Mail records.' );
	}

	public function test_revision_fences_rename_duplicate_and_delete(): void {
		$original = $this->make();
		$id = $original['id'];
		self::assertSame( 'conflict', SavedTemplateStore::rename(
			$id, 2, 'Wrong revision', $this->actor
		)['status'] );
		$renamed = SavedTemplateStore::rename( $id, 1, 'Updated name', $this->actor );
		self::assertSame( 'renamed', $renamed['status'] );
		self::assertSame( 2, $renamed['revision'] );
		self::assertSame( 'conflict', SavedTemplateStore::duplicate(
			$id, 1, 'Stale copy', $this->actor
		)['status'] );
		$copy = SavedTemplateStore::duplicate( $id, 2, 'Independent copy', $this->actor );
		self::assertSame( 'created', $copy['status'] );
		$this->ids[] = $copy['id'];
		self::assertSame( 'Independent copy', SavedTemplateStore::find( $copy['id'], $this->actor )['title'] );
		self::assertSame( 'conflict', SavedTemplateStore::delete( $id, 1, $this->actor )['status'] );
		self::assertSame( 'deleted', SavedTemplateStore::delete( $id, 2, $this->actor )['status'] );
		self::assertSame( 'missing', SavedTemplateStore::find( $id, $this->actor )['status'] );
		self::assertSame( 'found', SavedTemplateStore::find( $copy['id'], $this->actor )['status'] );
	}

	public function test_invalid_document_and_unauthorized_create_fail_closed(): void {
		$document = WordPressTemplates::default_document( 'wordpress.new-user' );
		self::assertIsArray( $document );
		$invalid = $document;
		$invalid['fields']['unregistered'] = 'not allowed';
		self::assertSame( 'refused', SavedTemplateStore::create(
			'Invalid project', 'personal', $this->actor, $invalid
		)['status'] );
		self::assertSame( 'refused', SavedTemplateStore::create(
			'<script>bad</script>', 'personal', $this->actor, $document
		)['status'] );
		self::assertSame( 'refused', SavedTemplateStore::create(
			'Bad visibility', 'public', $this->actor, $document
		)['status'] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		self::assertSame( 'refused', SavedTemplateStore::create(
			'Unauthorized', 'site', $subscriber, $document
		)['status'] );
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/mail-templates/create' );
		self::assertFalse( SavedTemplateController::permitted( $request ) );
	}

	public function test_missing_scope_never_creates_a_saved_asset(): void {
		$document = WordPressTemplates::default_document( 'wordpress.new-user' );
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/mail-templates/create' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'title' => 'Unsafe create',
			'visibility' => 'personal',
			'resourceType' => Repository::MAIL,
			'resourceKey' => 'wordpress.new-user',
			'profileId' => 'base/mail',
			'contextEpoch' => 0,
			'document' => $document,
		] ) );
		$result = SavedTemplateController::create( $request );
		self::assertSame( 403, $result->get_status() );
		self::assertSame( 'refused', $result->get_data()['status'] );
	}
}
