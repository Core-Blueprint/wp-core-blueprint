<?php
declare(strict_types=1);

use CB\Core\Notes\Repository;

final class CB_Base_Notes_Repository_Content_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Repository::install_schema();
	}

	public function test_create_preserves_literal_backslashes_after_request_normalization(): void {
		global $wpdb;

		$content = 'Path C:\\Core\\Blueprint and regex \\d+ remain literal.';
		self::assertTrue(
			Repository::create( [
				'title'   => 'Backslash preservation',
				'content' => $content,
			] )
		);

		$id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $id );

		$note = Repository::find( $id );
		self::assertNotNull( $note );
		self::assertSame( $content, (string) $note->content );

		$wpdb->delete( $wpdb->prefix . 'cb_core_notes', [ 'id' => $id ], [ '%d' ] );
	}

	public function test_import_normalization_preserves_literal_backslashes(): void {
		$content = 'Keep \\vendor\\package and \\w+ unchanged.';
		$normalized = Repository::normalize_import_note( [
			'title'   => 'Imported note',
			'content' => $content,
		] );

		self::assertSame( $content, $normalized['content'] );
	}
}
