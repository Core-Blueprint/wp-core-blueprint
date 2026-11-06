<?php
declare(strict_types=1);

use CoreBlueprint\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CoreBlueprint\Core\Integrity\Storage\ChunkedOptionStore;

final class CB_Base_Integrity_Chunked_Storage_Transaction_Contract_Test extends WP_UnitTestCase {
	private const KEY = 'cb_core_integrity_f07_contract';

	public function test_f07_chunked_snapshots_are_complete_and_reclaim_previous_generations(): void {
		$old = [ 'payload' => str_repeat( 'a', 250000 ), 'marker' => 'old' ];
		$new = [ 'payload' => str_repeat( 'b', 260000 ), 'marker' => 'new' ];

		self::assertTrue( ChunkedOptionStore::set( self::KEY, $old ) );
		self::assertSame( $old, ChunkedOptionStore::get( self::KEY ) );
		self::assertGreaterThan( 1, ChunkedOptionStore::chunk_count( self::KEY ) );
		$old_meta = get_option( self::KEY );
		self::assertIsArray( $old_meta );

		self::assertTrue( ChunkedOptionStore::set( self::KEY, $new ) );
		self::assertSame( $new, ChunkedOptionStore::get( self::KEY ) );
		self::assertNull( get_option( self::KEY . '__pending', null ) );
		self::assertNull( get_option( self::KEY . '__' . $old_meta['generation'] . '_0', null ) );

		ChunkedOptionStore::delete( self::KEY );
		self::assertNull( ChunkedOptionStore::get( self::KEY ) );
		self::assertNull( get_option( self::KEY . '__pending', null ) );
	}

	public function test_f07_missing_active_chunk_fails_closed_instead_of_returning_empty_workspace(): void {
		self::assertTrue( ChunkedOptionStore::set( self::KEY, [ 'evidence' => 1 ] ) );
		$meta = get_option( self::KEY );
		self::assertIsArray( $meta );
		$chunk_key = self::KEY . '__' . $meta['generation'] . '_0';
		$chunk = get_option( $chunk_key, null );
		self::assertIsString( $chunk );
		delete_option( $chunk_key );
		try {
			try {
				ChunkedOptionStore::get( self::KEY, [] );
				self::fail( 'A missing generation chunk silently became a valid empty workspace.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'chunk is missing', $error->getMessage() );
			}
		} finally {
			update_option( $chunk_key, $chunk, false );
			ChunkedOptionStore::delete( self::KEY );
		}
	}

	public function test_f07_interrupted_publication_does_not_overwrite_last_complete_snapshot(): void {
		$old = [ 'revision' => 'old' ];
		self::assertTrue( ChunkedOptionStore::set( self::KEY, $old ) );
		$reject_pointer = static function ( $new_value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_' . self::KEY, $reject_pointer, 10, 2 );
		try {
			self::assertFalse( ChunkedOptionStore::set( self::KEY, [ 'revision' => 'new' ] ) );
		} finally {
			remove_filter( 'pre_update_option_' . self::KEY, $reject_pointer, 10 );
		}
		self::assertSame( $old, ChunkedOptionStore::get( self::KEY ) );
		self::assertNull( get_option( self::KEY . '__pending', null ) );
		ChunkedOptionStore::delete( self::KEY );
	}

	public function test_f07_interrupted_generation_journal_is_recovered_on_next_write(): void {
		self::assertTrue( ChunkedOptionStore::set( self::KEY, [ 'revision' => 'original' ] ) );
		$previous = get_option( self::KEY );
		self::assertIsArray( $previous );
		$orphan_generation = '0123456789abcdef';
		$orphan_chunk = self::KEY . '__' . $orphan_generation . '_0';
		update_option( $orphan_chunk, 'orphaned', false );
		update_option( self::KEY . '__pending', [
			'generation' => $orphan_generation,
			'chunks'     => 1,
			'prior'      => $previous,
			'deleting'   => false,
		], false );

		self::assertTrue( ChunkedOptionStore::set( self::KEY, [ 'revision' => 'after-crash' ] ) );
		self::assertSame( [ 'revision' => 'after-crash' ], ChunkedOptionStore::get( self::KEY ) );
		self::assertNull( get_option( $orphan_chunk, null ) );
		self::assertNull( get_option( self::KEY . '__pending', null ) );
		ChunkedOptionStore::delete( self::KEY );
	}

	public function test_f07_other_database_connection_cannot_read_or_mutate_during_transaction(): void {
		global $wpdb;
		$key = self::KEY;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name = 'cb_integrity_' . substr( hash( 'sha256', $wpdb->options . ':' . $key ), 0, 40 );
		self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		try {
			try {
				ChunkedOptionStore::get( $key, [] );
				self::fail( 'The protected read was admitted while another connection held the mutex.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'storage is busy', $error->getMessage() );
			}
			try {
				ChunkedOptionStore::set( $key, [ 'should' => 'not write' ] );
				self::fail( 'The protected write was admitted while another connection held the mutex.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'storage is busy', $error->getMessage() );
			}
		} finally {
			self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ) );
		}
		self::assertTrue( ChunkedOptionStore::set( $key, [ 'allowed' => true ] ) );
		self::assertSame( [ 'allowed' => true ], ChunkedOptionStore::get( $key ) );
		ChunkedOptionStore::delete( $key );
	}

	public function test_f07_distinct_quarantine_items_survive_shared_snapshot_updates(): void {
		$a = 'q_' . str_repeat( 'a', 16 );
		$b = 'q_' . str_repeat( 'b', 16 );
		self::assertTrue( QuarantineRepository::save( [ 'id' => $a, 'status' => 'awaiting_review' ] ) );
		self::assertTrue( QuarantineRepository::save( [ 'id' => $b, 'status' => 'awaiting_review' ] ) );
		self::assertSame( 'awaiting_review', QuarantineRepository::get( $a )['status'] ?? null );
		self::assertSame( 'awaiting_review', QuarantineRepository::get( $b )['status'] ?? null );
		self::assertTrue( QuarantineRepository::delete( $a ) );
		self::assertNull( QuarantineRepository::get( $a ) );
		self::assertSame( 'awaiting_review', QuarantineRepository::get( $b )['status'] ?? null );
		self::assertTrue( QuarantineRepository::delete( $b ) );
	}

	public function test_f07_baseline_repository_writes_hold_full_transaction_lock(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Integrity/Storage/ResultRepository.php' );
		self::assertIsString( $source );
		self::assertSame( 3, substr_count( $source, 'ChunkedOptionStore::with_lock( self::OPTION_BASELINE' ) );
	}
}
