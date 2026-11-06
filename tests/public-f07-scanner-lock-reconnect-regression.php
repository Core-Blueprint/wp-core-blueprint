<?php
declare(strict_types=1);

/**
 * F07: simulate a database reconnect between nested Scanner mutations.
 *
 * Standalone Level 1 failure-injection regression: no WordPress installation,
 * real database queries, lock recovery, source mutation or CI.
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

	public function __construct() {
		$this->dbh = new stdClass();
	}

	public function prepare( string $query, mixed ...$params ): string {
		// SQL arguments are irrelevant to this isolated mutex protocol fake.
		return $query;
	}

	public function get_var( string $query ): string {
		if ( str_contains( $query, 'SELECT GET_LOCK(' ) ) {
			$this->claims++;
			if ( null !== $this->lock_owner && $this->lock_owner !== $this->session_id ) {
				return '0';
			}
			$this->lock_owner = $this->session_id;
			return '1';
		}
		if ( str_contains( $query, 'IS_USED_LOCK(' ) ) {
			$owned = $this->session_id === $this->lock_owner ? (string) $this->session_id : '0';
			if ( $this->reconnect_on_check ) {
				$this->reconnect_on_check = false;
				$this->reconnect();
			}
			return $owned;
		}
		if ( str_contains( $query, 'SELECT RELEASE_LOCK(' ) ) {
			$this->releases++;
			if ( $this->lock_owner !== $this->session_id ) {
				return '0';
			}
			$this->lock_owner = null;
			return '1';
		}
		throw new RuntimeException( 'Unexpected fake database query.' );
	}

	/** A lost MySQL connection releases named locks, possibly to a new worker. */
	public function reconnect( bool $successor_owns_lock = false ): void {
		$this->session_id++;
		$this->dbh = new stdClass();
		$this->lock_owner = $successor_owns_lock ? $this->session_id : null;
	}
}

require_once dirname( __DIR__ ) . '/src/Integrity/Storage/ChunkedOptionStore.php';

$check = static function ( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "F07 reconnect mutex FAIL: {$name}\n" );
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

// Ordinary recursion uses one lock claim and releases its original owner.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$result = ChunkedOptionStore::with_lock( $key, static function () use ( $key ) {
	return ChunkedOptionStore::with_lock( $key, static fn() => 'nested_success' );
} );
$check( 'nested_success' === $result, 'healthy nested operation succeeds' );
$check( 1 === $db->claims && 1 === $db->releases && null === $db->lock_owner, 'healthy nested operation claims and releases once' );

// The old worker must not execute a nested callback after its connection was lost.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$unsafe_callback_ran = false;
$rejects( static function () use ( $key, $db, &$unsafe_callback_ran ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $key, $db, &$unsafe_callback_ran ): void {
		$db->reconnect( true ); // Simulates a successor worker already owning the named lock.
		ChunkedOptionStore::with_lock( $key, static function () use ( &$unsafe_callback_ran ): void {
			$unsafe_callback_ran = true;
		} );
	} );
}, 'nested operation refuses a lost lock' );
$check( ! $unsafe_callback_ran, 'stale nested callback never runs' );
$check( 0 === $db->releases && $db->lock_owner === $db->session_id, 'stale worker never releases successor lock' );

// Reconnection just before returning a callback must fail the outer postcondition.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$rejects( static function () use ( $key, $db ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $db ): void {
		$db->reconnect();
	} );
}, 'completed callback cannot hide a lost lock' );
$check( 0 === $db->releases, 'reconnected session never releases old lock' );

// A connection switch *during* the ownership probe is also rejected.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$rejects( static function () use ( $key, $db ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $key, $db ): void {
		$db->reconnect_on_check = true;
		ChunkedOptionStore::with_lock( $key, static fn() => null );
	} );
}, 'reconnect inside ownership query fails closed' );

// Lost lock without changing the WordPress handle is also rejected.
$GLOBALS['wpdb'] = $db = new CB_F07_Fake_Database();
$rejects( static function () use ( $key, $db ): void {
	ChunkedOptionStore::with_lock( $key, static function () use ( $key, $db ): void {
		$db->lock_owner = null;
		ChunkedOptionStore::with_lock( $key, static fn() => null );
	} );
}, 'named lock disappearance on same DB handle fails closed' );

// Fail closed around every option write, including the published pointer.
$source = file_get_contents( dirname( __DIR__ ) . '/src/Integrity/Storage/ChunkedOptionStore.php' );
$check( is_string( $source ), 'source file is readable' );
$check( str_contains( $source, 'self::write_option( $key, $key, $meta );' ), 'pointer publication uses a guarded write' );
$check( 3 === substr_count( $source, 'self::write_option(' ), 'all three option updates are guarded' );
$check( 3 === substr_count( $source, 'self::remove_option(' ), 'all three option deletions are guarded' );
$check( str_contains( $source, 'IS_USED_LOCK(%s) = CONNECTION_ID()' ), 'database session ownership probe is enforced' );

echo "F07 reconnect fencing and mutation guard contracts: PASS\n";
