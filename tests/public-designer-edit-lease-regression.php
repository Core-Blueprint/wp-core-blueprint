<?php
declare(strict_types=1);

/** Standalone SOURCE-only lease state-machine regression; no WP or MariaDB. */
define( 'ABSPATH', __DIR__ . '/' );

$current_actor = 7;
$current_blog  = 1;
function get_current_user_id(): int { return $GLOBALS['current_actor']; }
function get_current_blog_id(): int { return $GLOBALS['current_blog']; }

final class EditLeaseFakeDb {
    public string $options = 'wp_options';
    public string $last_error = '';
    public array $rows = [];
    public bool $fail_write = false;
    public bool $fail_read = false;
    public bool $in_transaction = false;
    public string $engine = 'InnoDB';
    public mixed $before_update = null;
    private array $prepared = [];

    public function prepare( string $query, mixed ...$args ): string {
        $id = 'statement:' . count( $this->prepared );
        $this->prepared[$id] = [ $query, $args ];
        return $id;
    }

    public function get_var( string $statement ): ?string {
        if ( 'SELECT @@in_transaction' === $statement ) {
            return $this->in_transaction ? '1' : '0';
        }
        $id = str_replace( ' FOR UPDATE', '', $statement );
        [ $query, $args ] = $this->prepared[$id];
        if ( $this->fail_read ) {
            $this->last_error = 'offline';
            return null;
        }
        if ( str_starts_with( $query, 'SELECT ENGINE' ) ) {
            return $this->engine;
        }
        if ( ! str_starts_with( $query, 'SELECT option_value' ) ) {
            throw new RuntimeException( 'Unexpected lease query.' );
        }
        return $this->rows[$args[1]] ?? null;
    }

    public function query( string $statement ): int|false {
        [ $query, $args ] = $this->prepared[$statement];
        if ( $this->fail_write ) {
            $this->last_error = 'offline';
            return false;
        }
        if ( str_starts_with( $query, 'INSERT IGNORE' ) ) {
            [ $table, $key, $value, $autoload ] = $args;
            if ( $table !== $this->options || 'off' !== $autoload ) {
                throw new RuntimeException( 'Unexpected lease insert.' );
            }
            if ( array_key_exists( $key, $this->rows ) ) {
                return 0;
            }
            $this->rows[$key] = $value;
            return 1;
        }
        if ( str_starts_with( $query, 'UPDATE' ) ) {
            [ $table, $next, $key, $previous ] = $args;
            if ( $table !== $this->options ) {
                throw new RuntimeException( 'Unexpected lease update table.' );
            }
            if ( is_callable( $this->before_update ) ) {
                $callback = $this->before_update;
                $this->before_update = null;
                $callback( $this, $key );
            }
            if ( ! isset( $this->rows[$key] ) || $previous !== $this->rows[$key] ) {
                return 0;
            }
            $this->rows[$key] = $next;
            return 1;
        }
        if ( str_starts_with( $query, 'DELETE FROM' ) ) {
            [ $table, $key, $previous ] = $args;
            if ( $table !== $this->options || ! isset( $this->rows[$key] )
                || $this->rows[$key] !== $previous ) {
                return 0;
            }
            unset( $this->rows[$key] );
            return 1;
        }
        throw new RuntimeException( 'Unexpected lease operation.' );
    }
}

require_once dirname( __DIR__ ) . '/src/Design/Editor/DocumentEditLease.php';
use CoreBlueprint\Core\Design\Editor\DocumentEditLease as Lease;

function assert_lease( bool $condition, string $label ): void {
    if ( ! $condition ) {
        throw new RuntimeException( 'Edit lease regression failed: ' . $label );
    }
}

assert_lease( 1 === Lease::API_VERSION, 'public capability version' );
$wpdb = new EditLeaseFakeDb();
$allowed = static fn ( string $type, string $id ): bool => true;
$denied  = static fn ( string $type, string $id ): bool => false;

assert_lease( 'denied' === Lease::claim( 'contracts.template', 'a', $denied )['status'], 'consumer-owned deny' );
assert_lease( 'denied' === Lease::claim( '../oops', 'a', $allowed )['status'], 'scope validation' );
$first = Lease::claim( 'contracts.template', 'a', $allowed );
assert_lease( 'acquired' === $first['status'] && strlen( $first['token'] ) === 64, 'first claim' );
assert_lease( $first['expires_at'] > time(), 'bounded expiry' );
assert_lease( Lease::owns( 'contracts.template', 'a', $first['token'], $allowed ), 'own lease' );
assert_lease( ! Lease::owns_for_save( 'contracts.template', 'a', $first['token'], $allowed ), 'save guard requires transaction' );
$wpdb->in_transaction = true;
assert_lease( Lease::owns_for_save( 'contracts.template', 'a', $first['token'], $allowed ), 'same-transaction save gate' );
$wpdb->engine = 'MyISAM';
assert_lease( ! Lease::owns_for_save( 'contracts.template', 'a', $first['token'], $allowed ), 'nontransactional option store denied' );
$wpdb->engine = 'InnoDB';
$wpdb->in_transaction = false;
assert_lease( ! Lease::owns( 'contracts.template', 'a', str_repeat( '0', 64 ), $allowed ), 'wrong token' );
assert_lease( 'locked' === Lease::claim( 'contracts.template', 'a', $allowed )['status'], 'second tab same user denied' );
$GLOBALS['current_actor'] = 8;
assert_lease( 'locked' === Lease::claim( 'contracts.template', 'a', $allowed )['status'], 'second user denied' );
assert_lease( 'acquired' === Lease::claim( 'contracts.template', 'b', $allowed )['status'], 'other document available' );
assert_lease( 'denied' === Lease::renew( 'contracts.template', 'a', $first['token'], $denied )['status'], 'renew auth gate' );
assert_lease( 'lost' === Lease::renew( 'contracts.template', 'a', $first['token'], $allowed )['status'], 'other user cannot renew' );

$GLOBALS['current_actor'] = 7;
assert_lease( 'renewed' === Lease::renew( 'contracts.template', 'a', $first['token'], $allowed )['status'], 'original owner renew' );
assert_lease( ! Lease::release( 'contracts.template', 'a', str_repeat( 'f', 64 ) ), 'wrong token release' );
assert_lease( Lease::release( 'contracts.template', 'a', $first['token'] ), 'owner release' );
assert_lease( ! Lease::owns( 'contracts.template', 'a', $first['token'], $allowed ), 'released token lost' );
$second = Lease::claim( 'contracts.template', 'a', $allowed );
assert_lease( 'acquired' === $second['status'] && $first['token'] !== $second['token'], 'new owner after release' );

// Simulate crash/expired lease; reclaim uses DB row CAS instead of delete/recreate.
$key = array_key_first( $wpdb->rows );
foreach ( $wpdb->rows as $candidate => $value ) {
    if ( str_contains( $value, hash( 'sha256', $second['token'] ) ) ) {
        $key = $candidate;
        break;
    }
}
$wpdb->rows[$key] = preg_replace( '/\Av1:[0-9]+:/', 'v1:1:', $wpdb->rows[$key] );
$GLOBALS['current_actor'] = 8;
$third = Lease::claim( 'contracts.template', 'a', $allowed );
assert_lease( 'acquired' === $third['status'], 'expired lease can be reclaimed' );
$GLOBALS['current_actor'] = 7;
assert_lease( ! Lease::release( 'contracts.template', 'a', $second['token'] ), 'expired owner cannot release replacement' );
assert_lease( ! Lease::owns( 'contracts.template', 'a', $second['token'], $allowed ), 'expired owner cannot save' );

// Inject a competing winner between expired-row read and CAS.
$wpdb->rows[$key] = preg_replace( '/\Av1:[0-9]+:/', 'v1:1:', $wpdb->rows[$key] );
$wpdb->before_update = static function ( EditLeaseFakeDb $db, string $target ): void {
    $db->rows[$target] = 'v1:' . ( time() + 120 ) . ':11:' . str_repeat( 'b', 64 );
};
$GLOBALS['current_actor'] = 9;
assert_lease( 'locked' === Lease::claim( 'contracts.template', 'a', $allowed )['status'], 'CAS loser cannot steal lock' );

$wpdb->fail_write = true;
assert_lease( 'unavailable' === Lease::claim( 'contracts.template', 'failed-write', $allowed )['status'], 'DB writer fails closed' );
$wpdb->fail_write = false;
$wpdb->fail_read = true;
assert_lease( ! Lease::owns( 'contracts.template', 'a', $third['token'], $allowed ), 'DB reader fails closed' );
$wpdb->fail_read = false;
$GLOBALS['current_blog'] = 2;
assert_lease( 'acquired' === Lease::claim( 'contracts.template', 'a', $allowed )['status'], 'multisite separation' );

echo "Base Document Editing Lease SOURCE regression: PASS\n";
