<?php
declare(strict_types=1);

use CoreBlueprint\Core\Mail\Designer\SavedTemplateSchema;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateSource;
use CoreBlueprint\Core\Mail\Designer\SavedTemplateStore;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreview;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Saved_Mail_Preview_Test extends WP_UnitTestCase {
	private int $actor = 0;
	private bool $was_enabled = false;
	/** @var list<string> */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mail_saved_preview_fixture' ) );
		wp_set_current_user( $this->actor );
		$this->was_enabled = DesignerState::is_enabled();
		if ( ! $this->was_enabled ) {
			DesignerState::set_enabled( true, 'mail_saved_preview_fixture' );
		}
		self::assertTrue( SavedTemplateSchema::install() );
		TemplateSourceRegistry::_reset_for_testing();
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
			DesignerState::set_enabled( $this->was_enabled, 'mail_saved_preview_fixture' );
		}
		TemplateSourceRegistry::_reset_for_testing();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function create( string $visibility ): array {
		$document = WordPressTemplates::default_document( 'wordpress.new-user' );
		self::assertIsArray( $document );
		$created = SavedTemplateStore::create( 'Preview example', $visibility, $this->actor, $document );
		self::assertSame( 'created', $created['status'] );
		$this->ids[] = $created['id'];
		return $created;
	}

	private function other_admin(): int {
		$id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mail_saved_preview_other' ) );
		return $id;
	}

	public function test_saved_personal_preview_is_synthetic_and_owner_scoped(): void {
		$record = $this->create( 'personal' );
		$source = new SavedTemplateSource();
		self::assertTrue( TemplateSourceRegistry::supports_preview( SavedTemplateSource::SOURCE_ID ) );
		$preview = TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $record['id'], '1', null
		);
		self::assertInstanceOf( TemplatePreview::class, $preview );
		self::assertStringContainsString( 'Example Site', $preview->html );
		self::assertStringNotContainsString( 'href=', $preview->html );
		self::assertStringNotContainsString( '<img', $preview->html );
		$other = $this->other_admin();
		wp_set_current_user( $other );
		self::assertFalse( $source->can_preview( $record['id'], $other ) );
		self::assertNull( TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $record['id'], '1', null
		) );
		wp_set_current_user( $this->actor );
	}

	public function test_site_saved_preview_requires_current_admin_but_can_be_shared(): void {
		$record = $this->create( 'site' );
		$other = $this->other_admin();
		wp_set_current_user( $other );
		self::assertInstanceOf( TemplatePreview::class, TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $record['id'], '1', null
		) );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		self::assertNull( TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $record['id'], '1', null
		) );
		wp_set_current_user( $this->actor );
	}

	public function test_stale_revision_and_deleted_asset_are_refused(): void {
		$record = $this->create( 'personal' );
		$id = $record['id'];
		self::assertNull( TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $id, '2', null
		) );
		$renamed = SavedTemplateStore::rename( $id, 1, 'Revised preview', $this->actor );
		self::assertSame( 'renamed', $renamed['status'] );
		self::assertNull( TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $id, '1', null
		) );
		self::assertInstanceOf( TemplatePreview::class, TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $id, '2', null
		) );
		self::assertSame( 'deleted', SavedTemplateStore::delete( $id, 2, $this->actor )['status'] );
		self::assertNull( TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $id, '2', null
		) );
	}

	public function test_preview_disallowed_for_other_designer_scope(): void {
		$record = $this->create( 'personal' );
		self::assertNull( TemplateSourceRegistry::preview(
			SavedTemplateSource::SOURCE_ID, $record['id'], '1',
			[ 'extension' => 'core-blueprint', 'designer' => 'reports' ]
		) );
	}
}
