<?php
declare(strict_types=1);

/**
 * F07 final: a wpdb SQL retry must not escape the original named-lock session.
 * Isolated Level 1 failure injection; real MariaDB/WP execution is Level 2.
 */

use CoreBlueprint\Core\Integrity\Storage\ChunkedOptionStore;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

final class CB_F07_Fake_Database {
	public string $options = 'wp_options';
	public object $dbh;
	public int $session_id = 41;
	public ?int $lock_owner = null;
	public int $claims = 0;
	public int $releases = 0;
	public bool $reconnect_on_check = false;
	public bool $reconnect_during_option_query = false;
	public bool $reconnect_after_server_commit = false;
	/** @var array<string,array{0:string,1:array}> */
	public array $prepared = [];
	/** @var list<array{sql:string,args:array}> */
	public array $option_queries = [];
	private int $sequence = 0;

	public function __construct() {
		$this->dbh = new stdClass();
	}

	public function prepare( string $query, mixed ...$params ): string {
		$id = 'cb_f07_stmt_' . ++$this->sequence;
		$this->prepared[ $id ] = [ $query, $params ];
		return $id;
	}

	private function statement( string $id ): array {
		if ( ! isset( $this->prepared[ $id ] ) ) {
			throw new RuntimeException( 'Unrecognized fake prepared statement.' );
		}
		return $this->prepared[ $id ];
	}

	public function get_var( string $query ): string {
		[ $sql ] = $this->statement( $query );
		if ( str_contains( $sql, 'SELECT GET_LOCK(' ) ) {
			$this->claims++;
			if ( null !== $this->lock_owner && $this->lock_owner !== $this->session_id ) {
				return '0';
			}
			$this->lock_owner = $this->session_id;
			return '1';
		}
		if ( str_contains( $sql, 'IS_USED_LOCK(' ) ) {
			$owned = $this->session_id === $this->lock_owner ? (string) $this->session_id : '0';
			if ( $this->reconnect_on_check ) {
				$this->reconnect_on_check = false;
				$this->reconnect();
			}
			return $owned;
		}
		if ( str_contains( $sql, 'SELECT RELEASE_LOCK(' ) ) {
			$this->releases++;
			if ( $this->lock_owner !== $this->session_id ) {
				return '0';
			}
			$this->lock_owner = null;
			return '1';
		}
		throw new RuntimeException( 'Unexpected fake database query.' );
	}

	/**
	 * Execute *the exact same prepared DML* against the current session.
	 * A WordPress retry after reconnect must affect zero rows.
	 */
	public function query( string $id ): int {
		[ $sql, $args ] = $this->statement( $id );
		$is_insert = str_contains( $sql, 'INSERT INTO ' );
		$is_delete = str_contains( $sql, 'DELETE FROM ' );
		if (
			( ! $is_insert && ! $is_delete )
			|| ! str_contains( $sql, 'CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d' )
		) {
			throw new RuntimeException( 'Scanner DML lacks its SQL ownership fence.' );
		}
		$expected_session = (int) $args[ $is_insert ? 3 : 1 ];
		$lock_name = $args[ $is_insert ? 4 : 2 ];
		$expected_owner = (int) $args[ $is_insert ? 5 : 3 ];
		$key = $args[0];
		$this->option_queries[] = [ 'sql' => $sql, 'args' => $args ];
		if ( $this->reconnect_after_server_commit ) {
			$this->reconnect_after_server_commit = false;
			// Simulate a committed statement, lost acknowledgement and SQL retry.
			$this->apply_dml( $is_insert, $key, $args, $expected_session, $expected_owner );
			$this->reconnect( true );
		} elseif ( $this->reconnect_during_option_query ) {
			$this->reconnect_during_option_query = false;
			$this->reconnect( true ); // Next worker has acquired the old lock.
		}
		return $this->apply_dml( $is_insert, $key, $args, $expected_session, $expected_owner );
	}

	private function apply_dml( bool $insert, string $key, array $args, int $expected_session, int $expected_owner ): int {
		if (
			$this->session_id !== $expected_session
			|| $this->session_id !== $expected_owner
			|| $this->lock_owner !== $this->session_id
		) {
			return 0;
		}
		if ( $insert ) {
			$GLOBALS['cb_f07_options'][ $key ] = cb_f07_unserialize_option( $args[1] );
		} else {
			unset( $GLOBALS['cb_f07_options'][ $key ] );
		}
		$GLOBALS['cb_f07_option_writes'][] = $key;
		return 1;
	}

	/** A disconnected session loses its named mutex. */
	public function reconnect( bool $successor_owns_lock = false ): void {
		$this->session_id++;
		$this->dbh = new stdClass();
		$this->lock_owner = $successor_owns_lock ? $this->session_id : null;
	}
}

$GLOBALS['cb_f07_options'] = [];
$GLOBALS['cb_f07_option_writes'] = [];
$GLOBALS['cb_f07_cache_invalidations'] = [];
function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['cb_f07_options'][ $name ] ?? $default;
}
function maybe_serialize( mixed $value ): string {
	if ( is_array( $value ) || is_object( $value ) ) {
		return serialize( $value );
	}
	// WordPress double-serializes strings which already look serialized.
	if ( is_string( $value ) && preg_match( '/^(?:a|s|O|i|b|d|N):/', $value ) ) {
		return serialize( $value );
	}
	return (string) $value;
}
function cb_f07_unserialize_option( string $raw ): mixed {
	$decoded = @unserialize( $raw, [ 'allowed_classes' => false ] );
	return false === $decoded && 'b:0;' !== $raw ? $raw : $decoded;
}
function sanitize_option( string $name, mixed $value ): mixed {
	return $value;
}
function apply_filters( string $name, mixed $value, mixed ...$args ): mixed {
	return $value;
}
function wp_cache_delete( string $key, string $group = '' ): bool {
	$GLOBALS['cb_f07_cache_invalidations'][] = [ $group, $key ];
	return true;
}

require_once dirname( __DIR__ ) . '/src/Integrity/Storage/ChunkedOptionStore.php';

$check = static function ( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "F07 final SQL fencing FAIL: {$name}\n" );
		exit( 1 );
	}
};
$rejects = static function ( callable $operation, string $name ) use ( $check ): void {
	$rejected = false;
	try {
		$operation();
	} catch ( RuntimeException $error ) {
		$rejected = str_contains( $error->getMessage(), 'mutex' );
	}
	$check( $rejected, $name );
};
$key = 'cb_core_integrity_f07_reconnect_contract';

// Healthy reentrancy shares one lock owner.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$result = ChunkedOptionStore::with_lock( $key, static fn() =>
	ChunkedOptionStore::with_lock( $key, static fn() => 'nested_success' )
);
$check( 'nested_success' === $result, 'healthy nested operation succeeds' );
$check( 1 === $db->claims && 1 === $db->releases && null === $db->lock_owner, 'one nested claim and release' );

// A stale worker cannot enter a nested callback or release the successor's lock.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$ran = false;
$rejects( static function () use ( $key, $db, &$ran ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $key, $db, &$ran ): void {
		$db->reconnect( true );
		ChunkedOptionStore::with_lock( $key, static function () use ( &$ran ): void {
			$ran = true;
		} );
	} );
}, 'nested operation rejects lost lock' );
$check( ! $ran && 0 === $db->releases, 'stale worker never runs or releases' );

// Same PHP mysqli handle, different server session: successor's lock is not released.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$rejects( static function () use ( $key, $db ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $db ): void {
		$db->session_id++; // In-place server-session replacement.
		$db->lock_owner = $db->session_id; // Simulated successor ownership.
	} );
}, 'in-place session change must fail before RELEASE_LOCK' );
$check(
	0 === $db->releases && $db->lock_owner === $db->session_id,
	'in-place reconnect never releases successor mutex'
);

$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$rejects( static function () use ( $key, $db ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $db ): void {
		$db->reconnect();
	} );
}, 'callback return cannot hide a lost lock' );
$check( 0 === $db->releases, 'reconnected session never releases old lock' );

$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$rejects( static function () use ( $key, $db ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $key, $db ): void {
		$db->reconnect_on_check = true;
		ChunkedOptionStore::with_lock( $key, static fn() => null );
	} );
}, 'reconnect inside ownership query fails closed' );

$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$rejects( static function () use ( $key, $db ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $key, $db ): void {
		$db->lock_owner = null;
		ChunkedOptionStore::with_lock( $key, static fn() => null );
	} );
}, 'lock disappearance on same database handle fails closed' );

// WordPress retries the same option SQL on another connection: no DML occurs.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$GLOBALS['cb_f07_options'] = [];
$GLOBALS['cb_f07_option_writes'] = [];
$db->reconnect_during_option_query = true;
$rejects( static function () use ( $key ): void {
	ChunkedOptionStore::set( $key, [ 'revision' => 'stale-worker' ] );
}, 'replayed journal DML cannot write without original session' );
$check( [] === $GLOBALS['cb_f07_option_writes'], 'no unauthorized SQL reaches journal, chunks or pointer' );
$check( null === get_option( $key, null ), 'replayed SQL never publishes pointer' );
$check( 0 === $db->releases && $db->lock_owner === $db->session_id, 'new lock owner is untouched' );

// The server may commit a statement before losing its acknowledgement.
// Recovery must handle that one interrupted journal without stale follow-up.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$GLOBALS['cb_f07_options'] = [];
$GLOBALS['cb_f07_option_writes'] = [];
$db->reconnect_after_server_commit = true;
$rejects( static function () use ( $key ): void {
	ChunkedOptionStore::set( $key, [ 'revision' => 'partial-worker' ] );
}, 'committed but unacknowledged journal aborts further writes' );
$check( [ $key . '__pending' ] === $GLOBALS['cb_f07_option_writes'], 'only original session could commit journal' );
$check( null === get_option( $key, null ), 'partial journal never publishes pointer' );
$db->lock_owner = null;
$check( true === ChunkedOptionStore::set( $key, [ 'revision' => 'recovered' ] ), 'next writer recovers pending journal' );
$check( [ 'revision' => 'recovered' ] === ChunkedOptionStore::get( $key ), 'recovered snapshot intact' );
$check( null === get_option( $key . '__pending', null ), 'recovered journal cleared' );

// A stale SQL statement is safe to replay even after the lock is released,
// and also when a successor owns the mutex on another database session.
$pointer_sql = null;
foreach ( $db->prepared as $id => [ $sql, $args ] ) {
	if ( str_contains( $sql, 'INSERT INTO ' ) && ( $args[0] ?? null ) === $key ) {
		$pointer_sql = $id;
	}
}
$check( null !== $pointer_sql, 'protected pointer upsert was recorded' );
$original = get_option( $key, null );
$check( 0 === $db->query( $pointer_sql ), 'released owner cannot replay pointer SQL' );
$db->reconnect( true );
$check( 0 === $db->query( $pointer_sql ), 'successor session cannot replay predecessor SQL' );
$check( $original === get_option( $key, null ), 'pointer survives both stale SQL replays' );
$check( 0 === $db->releases || $db->lock_owner === $db->session_id, 'no stale release of successor lock' );
$db->lock_owner = null;
ChunkedOptionStore::delete( $key );
$check( null === ChunkedOptionStore::get( $key, null ), 'normal delete remains functional' );

// All three WP cache paths are invalidated for raw fenced DML.
$keys = array_column( $GLOBALS['cb_f07_cache_invalidations'], 1 );
$check( in_array( 'notoptions', $keys, true ) && in_array( 'alloptions', $keys, true ), 'native option caches invalidated' );

// Enforce the exact DML and lifecycle boundary from Level 1 source.
$source = file_get_contents( dirname( __DIR__ ) . '/src/Integrity/Storage/ChunkedOptionStore.php' );
$check( is_string( $source ), 'source readable' );
$check( str_contains( $source, 'self::write_option( $key, $key, $meta );' ), 'pointer uses fenced write' );
$check( 3 === substr_count( $source, 'self::write_option(' ), 'three option updates fenced' );
$check( 3 === substr_count( $source, 'self::remove_option(' ), 'three option deletions fenced' );
$check( str_contains( $source, 'IS_USED_LOCK(%s) = CONNECTION_ID()' ), 'acquisition session ownership checked' );
$check( 2 === substr_count( $source, 'CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d' ), 'INSERT and DELETE have replay-proof SQL predicates' );
$check( ! str_contains( $source, 'update_option( $option_key, $value, false )' ), 'no unfenced WordPress update_option write' );
$check( ! str_contains( $source, 'delete_option( $option_key )' ), 'no unfenced WordPress delete_option write' );

echo "F07 final named-lock SQL authority and retry regression: PASS\n";
