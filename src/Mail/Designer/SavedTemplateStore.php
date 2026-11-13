<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSort;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for Mail's reusable assets, never for live Designer documents.
 * Each asset is a complete immutable-on-read native Mail snapshot. Updating
 * metadata uses a revision-fenced single-row SQL mutation.
 */
final class SavedTemplateStore {
	private const MAX_JSON = 1048576;
	private const ID_PATTERN = '/^mail\.saved\.[a-f0-9]{32}$/D';

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( self::ID_PATTERN, $id );
	}

	public static function valid_title( string $title ): bool {
		$title = trim( $title );
		return '' !== $title && $title === trim( wp_strip_all_tags( $title ) )
			&& strlen( $title ) <= 191
			&& ! preg_match( '/[\x00-\x1f\x7f]/', $title )
			&& 1 === preg_match( '//u', $title );
	}

	private static function allowed( int $actor ): bool {
		return $actor > 0 && $actor === get_current_user_id()
			&& user_can( $actor, 'manage_options' )
			&& \CoreBlueprint\Core\Mail\DesignerState::is_enabled();
	}

	/** @return array{status:string,id?:string,revision?:int} */
	public static function create( string $title, string $visibility, int $actor, array $document ): array {
		try {
			$id = 'mail.saved.' . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return [ 'status' => 'refused' ];
		}
		return self::insert_new( $id, $title, $visibility, $actor, $document );
	}

	/**
	 * Portable imports must bind a unique destination ID before preview.
	 * This is a personal asset only, never a live Mail resource or site template.
	 */
	public static function create_imported_personal( string $id, string $title, int $actor, array $document ): array {
		return self::insert_new( $id, $title, 'personal', $actor, $document );
	}

	private static function insert_new( string $id, string $title, string $visibility, int $actor, array $document ): array {
		global $wpdb;
		if ( ! self::valid_id( $id ) || ! self::allowed( $actor )
			|| ! self::valid_title( $title )
			|| ! in_array( $visibility, [ 'personal', 'site' ], true )
			|| ! PlatformTransport::valid_native_document( Repository::MAIL, $document ) ) {
			return [ 'status' => 'refused' ];
		}
		try {
			$json = json_encode( $document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} catch ( \Throwable ) {
			return [ 'status' => 'refused' ];
		}
		if ( strlen( $json ) > self::MAX_JSON || ! SavedTemplateSchema::healthy( true ) ) {
			return [ 'status' => 'unavailable' ];
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$owner = 'personal' === $visibility ? $actor : 0;
		$written = $wpdb->insert( SavedTemplateSchema::table(), [
			'id' => $id, 'owner_id' => $owner, 'visibility' => $visibility,
			'title' => trim( $title ), 'revision' => 1, 'document_json' => $json,
			'created_at' => $now, 'updated_at' => $now,
		], [ '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ] );
		// A primary-key collision can NEVER update or replace an existing asset.
		if ( 1 !== $written ) return [ 'status' => 'unknown' ];
		$check = self::find( $id, $actor );
		return 'found' === $check['status'] && $check['document'] === $document
			&& (int) $check['revision'] === 1
			? [ 'status' => 'created', 'id' => $id, 'revision' => 1 ]
			: [ 'status' => 'unknown' ];
	}

	/**
	 * Actor-specific authorized read. Corruption is never normalized or
	 * replaced with an operational provider default.
	 */
	public static function find( string $id, int $actor ): array {
		global $wpdb;
		if ( ! self::valid_id( $id ) || ! self::allowed( $actor ) ) return [ 'status' => 'refused' ];
		if ( ! SavedTemplateSchema::healthy() ) return [ 'status' => 'unavailable' ];
		$sql = $wpdb->prepare(
			"SELECT * FROM %i WHERE id = %s AND (visibility = 'site' OR (visibility = 'personal' AND owner_id = %d)) LIMIT 1",
			SavedTemplateSchema::table(), $id, $actor
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) ) return [ 'status' => '' !== (string) $wpdb->last_error ? 'unavailable' : 'missing' ];
		if ( ! in_array( $row['visibility'] ?? '', [ 'personal', 'site' ], true )
			|| ! self::valid_title( (string) ( $row['title'] ?? '' ) )
			|| (int) ( $row['revision'] ?? 0 ) < 1
			|| ( ( 'site' === $row['visibility'] && (int) $row['owner_id'] !== 0 )
				|| ( 'personal' === $row['visibility'] && (int) $row['owner_id'] !== $actor ) ) ) {
			return [ 'status' => 'invalid' ];
		}
		$json = $row['document_json'] ?? null;
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_JSON ) return [ 'status' => 'invalid' ];
		try {
			$document = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			return [ 'status' => 'invalid' ];
		}
		if ( ! is_array( $document )
			|| ! PlatformTransport::valid_native_document( Repository::MAIL, $document ) ) {
			return [ 'status' => 'invalid' ];
		}
		return [
			'status' => 'found', 'id' => $id, 'visibility' => $row['visibility'],
			'owner_id' => (int) $row['owner_id'], 'title' => $row['title'],
			'revision' => (int) $row['revision'], 'document' => $document,
		];
	}

	/**
	 * Keyset-paged metadata only; never SELECT document_json. Pagination and
	 * actor visibility are enforced in SQL before result count or disclosure.
	 *
	 * @return array{status:string,items?:array,next?:string|null}
	 */
	public static function page( TemplateQuery $query, ?string $cursor, int $actor ): array {
		global $wpdb;
		if ( ! self::allowed( $actor ) ) return [ 'status' => 'refused' ];
		if ( null !== $cursor && ! self::valid_id( $cursor ) ) return [ 'status' => 'refused' ];
		if ( ! SavedTemplateSchema::healthy() ) return [ 'status' => 'unavailable' ];
		$where = [ "(visibility = 'site' OR (visibility = 'personal' AND owner_id = %d))" ];
		$values = [ $actor ];
		if ( null !== $cursor ) { $where[] = 'id > %s'; $values[] = $cursor; }
		if ( '' !== $query->origin ) { $where[] = 'visibility = %s'; $values[] = $query->origin; }
		if ( '' !== $query->search ) {
			$where[] = 'title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $query->search ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$sql = "SELECT id,owner_id,visibility,title,revision FROM %i WHERE {$where_sql} ORDER BY id ASC LIMIT %d";
		$params = array_merge( [ SavedTemplateSchema::table() ], $values, [ $query->limit + 1 ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed predicates, %i identifier and bound values.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) return [ 'status' => 'unavailable' ];
		$more = count( $rows ) > $query->limit;
		$rows = array_slice( $rows, 0, $query->limit );
		$next = $more && $rows ? (string) end( $rows )['id'] : null;
		return [ 'status' => 'ready', 'items' => $rows, 'next' => $next ];
	}

	/**
	 * Sorted metadata-only seek. BINARY title and BINARY id in SQL exactly
	 * match PHP's strcmp() raw UTF-8 byte comparator. No locale collation or
	 * document_json is read. This does not change the default ID-keyset page().
	 *
	 * @param array{title:string,source:string,id:string}|null $after
	 * @return array{status:string,items?:array}
	 */
	public static function sorted_page( TemplateQuery $query, ?array $after, int $actor ): array {
		global $wpdb;
		if ( TemplateSort::TITLE_ASC !== $query->sort || ! self::allowed( $actor ) ) {
			return [ 'status' => 'refused' ];
		}
		if ( null !== $after && ! TemplateSort::valid_position( $after ) ) {
			return [ 'status' => 'refused' ];
		}
		if ( ! SavedTemplateSchema::healthy() ) return [ 'status' => 'unavailable' ];
		$where = [ "(visibility = 'site' OR (visibility = 'personal' AND owner_id = %d))" ];
		$values = [ $actor ];
		if ( '' !== $query->origin ) {
			$where[] = 'visibility = %s';
			$values[] = $query->origin;
		}
		if ( '' !== $query->search ) {
			$where[] = 'title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $query->search ) . '%';
		}
		if ( null !== $after ) {
			$relation = strcmp( SavedTemplateSource::SOURCE_ID, $after['source'] );
			if ( $relation > 0 ) {
				$where[] = 'BINARY title >= BINARY %s';
				$values[] = $after['title'];
			} elseif ( $relation < 0 ) {
				$where[] = 'BINARY title > BINARY %s';
				$values[] = $after['title'];
			} else {
				$where[] = '(BINARY title > BINARY %s OR (BINARY title = BINARY %s AND BINARY id > BINARY %s))';
				array_push( $values, $after['title'], $after['title'], $after['id'] );
			}
		}
		$where_sql = implode( ' AND ', $where );
		$sql = "SELECT id,owner_id,visibility,title,revision FROM %i WHERE {$where_sql} ORDER BY BINARY title ASC, BINARY id ASC LIMIT %d";
		$params = array_merge( [ SavedTemplateSchema::table() ], $values, [ $query->limit ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL predicates, %i table, bound values.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) return [ 'status' => 'unavailable' ];
		return [ 'status' => 'ready', 'items' => $rows ];
	}

	/** @return array{status:string,revision?:int} */
	public static function rename( string $id, int $expected, string $title, int $actor ): array {
		global $wpdb;
		if ( $expected < 1 || ! self::valid_title( $title ) ) return [ 'status' => 'refused' ];
		$old = self::find( $id, $actor );
		if ( $old['status'] !== 'found' ) return [ 'status' => $old['status'] ];
		if ( $old['revision'] !== $expected ) return [ 'status' => 'conflict' ];
		if ( ! SavedTemplateSchema::healthy( true ) ) return [ 'status' => 'unavailable' ];
		$sql = $wpdb->prepare(
			"UPDATE %i SET title = %s, revision = revision + 1, updated_at = %s WHERE id = %s AND revision = %d AND (visibility = 'site' OR (visibility = 'personal' AND owner_id = %d))",
			SavedTemplateSchema::table(), trim( $title ), gmdate( 'Y-m-d H:i:s' ),
			$id, $expected, $actor
		);
		$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- %i and all mutations are bound above.
		if ( false === $written ) return [ 'status' => 'unknown' ];
		if ( 0 === $written ) return [ 'status' => 'conflict' ];
		$check = self::find( $id, $actor );
		return $check['status'] === 'found' && $check['revision'] === $expected + 1
			&& $check['title'] === trim( $title )
			? [ 'status' => 'renamed', 'revision' => $expected + 1 ]
			: [ 'status' => 'unknown' ];
	}

	/** @return array{status:string} */
	public static function delete( string $id, int $expected, int $actor ): array {
		global $wpdb;
		if ( $expected < 1 ) return [ 'status' => 'refused' ];
		$old = self::find( $id, $actor );
		if ( $old['status'] !== 'found' ) return [ 'status' => $old['status'] ];
		if ( $old['revision'] !== $expected ) return [ 'status' => 'conflict' ];
		if ( ! SavedTemplateSchema::healthy( true ) ) return [ 'status' => 'unavailable' ];
		$sql = $wpdb->prepare(
			"DELETE FROM %i WHERE id = %s AND revision = %d AND (visibility = 'site' OR (visibility = 'personal' AND owner_id = %d))",
			SavedTemplateSchema::table(), $id, $expected, $actor
		);
		$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- bound identity, revision and scope.
		if ( false === $written ) return [ 'status' => 'unknown' ];
		if ( 0 === $written ) return [ 'status' => 'conflict' ];
		return self::find( $id, $actor )['status'] === 'missing'
			? [ 'status' => 'deleted' ] : [ 'status' => 'unknown' ];
	}

	public static function duplicate( string $id, int $expected, string $title, int $actor ): array {
		global $wpdb;
		if ( $expected < 1 || ! self::valid_title( $title ) ) return [ 'status' => 'refused' ];
		$original = self::find( $id, $actor );
		if ( 'found' !== $original['status'] ) return [ 'status' => $original['status'] ];
		if ( $original['revision'] !== $expected ) return [ 'status' => 'conflict' ];
		if ( ! SavedTemplateSchema::healthy( true ) ) return [ 'status' => 'unavailable' ];
		try {
			$new_id = 'mail.saved.' . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return [ 'status' => 'unavailable' ];
		}
		// A single INSERT ... SELECT fences the *source row* at its exact
		// expected revision and authorized owner scope. A concurrent Rename
		// or Delete cannot produce an uncontrolled stale duplicate.
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare(
			"INSERT INTO %i (id,owner_id,visibility,title,revision,document_json,created_at,updated_at)
			 SELECT %s,owner_id,visibility,%s,1,document_json,%s,%s FROM %i
			 WHERE id = %s AND revision = %d
			 AND (visibility = 'site' OR (visibility = 'personal' AND owner_id = %d))",
			SavedTemplateSchema::table(), $new_id, trim( $title ), $now, $now,
			SavedTemplateSchema::table(), $id, $expected, $actor
		);
		$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identity, revision and scope bound above.
		if ( false === $written ) return [ 'status' => 'unknown' ];
		if ( 0 === $written ) return [ 'status' => 'conflict' ];
		$check = self::find( $new_id, $actor );
		return 'found' === $check['status']
			&& $check['revision'] === 1
			&& $check['title'] === trim( $title )
			&& $check['document'] === $original['document']
			? [ 'status' => 'created', 'id' => $new_id, 'revision' => 1 ]
			: [ 'status' => 'unknown' ];
	}

	private function __construct() {}
}
