<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;
use CoreBlueprint\Core\Design\Profile\Mail\Contract as NativeMailContract;

defined( 'ABSPATH' ) || exit;

/**
 * DB-backed contract. Authored in A2.1; execute ONLY in a newly approved
 * isolated WordPress Level 2 slot. Never in production or staging.
 */
final class DesignDocumentStorageFoundationTest extends WP_UnitTestCase {
	/** @var string[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		self::assertTrue( Schema::healthy(), 'Base schema must be installed as InnoDB with the correct unique constraints.' );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->created as $key ) {
			$wpdb->delete( Schema::table_name(), [ 'identity_hash' => hash( 'sha256', Repository::MAIL . "\0" . $key ) ], [ '%s' ] );
		}
		parent::tearDown();
	}

	private function key(): string {
		$key = 'test.' . bin2hex( random_bytes( 12 ) );
		$this->created[] = $key;
		return $key;
	}

	private function mail( string $subject ): array {
		$document = NativeMailContract::default_document();
		$document['fields']['subject'] = $subject;
		return $document;
	}

	public function test_create_save_and_reset_use_real_single_row_revision_cas(): void {
		$key = $this->key();
		$initial = $this->mail( 'Initial' );
		self::assertSame( 'missing', Repository::get( Repository::MAIL, $key )['status'] );
		$created = Repository::create( Repository::MAIL, $key, '0', $initial );
		self::assertSame( 'saved', $created['status'] );
		self::assertSame( '1', $created['revision'] );
		self::assertSame( 'conflict', Repository::create( Repository::MAIL, $key, '0', $initial )['status'] );

		$new = $this->mail( 'New subject' );
		self::assertSame( 'saved', Repository::save( Repository::MAIL, $key, '1', $new )['status'] );
		self::assertSame( 'conflict', Repository::save( Repository::MAIL, $key, '1', $initial )['status'] );
		self::assertSame( '2', Repository::get( Repository::MAIL, $key )['revision'] );
		self::assertSame( $new, Repository::get( Repository::MAIL, $key )['document'] );
		self::assertSame( 'saved', Repository::reset( Repository::MAIL, $key, '2', $initial )['status'] );
		self::assertSame( 'conflict', Repository::save( Repository::MAIL, $key, '2', $new )['status'] );
		self::assertSame( '3', Repository::get( Repository::MAIL, $key )['revision'] );
	}

	public function test_distinct_template_identifiers_have_independent_revisions(): void {
		$a = $this->key();
		$b = $this->key();
		self::assertSame( 'saved', Repository::create( Repository::MAIL, $a, '0', $this->mail( 'A' ) )['status'] );
		self::assertSame( 'saved', Repository::create( Repository::MAIL, $b, '0', $this->mail( 'B' ) )['status'] );
		self::assertSame( 'saved', Repository::save( Repository::MAIL, $a, '1', $this->mail( 'A2' ) )['status'] );
		self::assertSame( 'saved', Repository::save( Repository::MAIL, $b, '1', $this->mail( 'B2' ) )['status'] );
		self::assertSame( '2', Repository::get( Repository::MAIL, $a )['revision'] );
		self::assertSame( '2', Repository::get( Repository::MAIL, $b )['revision'] );
	}

	public function test_bigint_revision_is_preserved_as_decimal_string(): void {
		global $wpdb;
		$key = $this->key();
		self::assertSame( 'saved', Repository::create( Repository::MAIL, $key, '0', $this->mail( 'B' ) )['status'] );
		$hash = hash( 'sha256', Repository::MAIL . "\0" . $key );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revision = %s WHERE identity_hash = %s', Schema::table_name(), '9007199254740993', $hash ) );
		self::assertSame( '9007199254740993', Repository::get( Repository::MAIL, $key )['revision'] );
		$saved = Repository::save( Repository::MAIL, $key, '9007199254740993', $this->mail( 'C' ) );
		self::assertSame( 'saved', $saved['status'] );
		self::assertSame( '9007199254740994', $saved['revision'] );
		self::assertIsString( $saved['revision'] );
	}

	public function test_pre_native_mail_envelope_is_refused_without_writes(): void {
		$key = $this->key();
		$obsolete = [ 'subject' => 'Legacy subject', 'project' => [ 'root' => [ 'type' => 'mail.root' ] ] ];
		self::assertSame( 'invalid', Repository::create( Repository::MAIL, $key, '0', $obsolete )['status'] );
		self::assertSame( 'missing', Repository::get( Repository::MAIL, $key )['status'] );
	}

	public function test_invalid_revision_never_writes(): void {
		$key = $this->key();
		self::assertSame( 'saved', Repository::create( Repository::MAIL, $key, '0', $this->mail( 'B' ) )['status'] );
		foreach ( [ '', '-1', '01', '1e3', '9223372036854775808' ] as $revision ) {
			self::assertSame( 'invalid', Repository::save( Repository::MAIL, $key, $revision, $this->mail( 'X' ) )['status'] );
		}
		self::assertSame( '1', Repository::get( Repository::MAIL, $key )['revision'] );
	}
}
