<?php
declare(strict_types=1);

/**
 * A2.4-S1 scoped six-consumer acceptance regression.
 * Exercises the actual six production classes with in-memory Settings/WordPress
 * doubles. WordPress, database transactions and Docker remain Level 2 HOLD.
 */

namespace {
    define( 'ABSPATH', __DIR__ . '/' );

    class WP_User {
        public int $ID = 1;
        public string $user_login = 'operator';
        public function exists(): bool { return true; }
    }
    class WP_Admin_Bar {}
    function __( string $message, string $domain = '' ): string { return $message; }
    function esc_html__( string $message, string $domain = '' ): string { return $message; }
    function sanitize_key( string $text ): string { return strtolower( $text ); }
    function wp_get_current_user(): WP_User { return new WP_User(); }
    function current_user_can( string $cap ): bool { return true; }
    function check_admin_referer( string $action ): void {}
    function wp_unslash( string $value ): string { return $value; }
    function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
    function admin_url( string $path ): string { return 'https://localhost.invalid/wp-admin/' . $path; }
    function wp_die( mixed ...$args ): never { throw new \RuntimeException( 'Unexpected permission failure' ); }
    final class S1Redirect extends \RuntimeException {}
    function wp_safe_redirect( string $url ): bool { throw new S1Redirect( $url ); }

    function s1_six_assert( bool $condition, string $message ): void {
        if ( ! $condition ) { throw new \RuntimeException( 'S1 six-consumer regression: ' . $message ); }
    }
    function s1_six_throws( callable $callback, string $message ): void {
        $error = null;
        try { $callback(); } catch ( \RuntimeException $caught ) { $error = $caught; }
        s1_six_assert( $error instanceof \RuntimeException && ! $error instanceof S1Redirect, $message );
    }
}
namespace CoreBlueprint\Core\Profiles {
    abstract class ExactSection {
        public function snapshot(): array { return $this->export(); }
    }
    final class StateGuard {
        public static bool $canRestore = false;
        public static function is_between( array $current, array $snapshot, array $incoming ): bool {
            return self::$canRestore;
        }
    }
    final class SchemaGuard {
        public static function exact_keys( array $value, array $expected, string $context ): void {
            $actual = array_keys( $value );
            sort( $actual );
            sort( $expected );
            if ( $actual !== $expected ) { throw new \InvalidArgumentException( 'invalid test keys: ' . $context ); }
        }
        public static function object( mixed $value, string $context ): array {
            if ( ! is_array( $value ) ) { throw new \InvalidArgumentException( $context ); }
            return $value;
        }
        public static function string( mixed $value, string $context ): string {
            if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( $context ); }
            return $value;
        }
        public static function int( mixed $value, string $context ): int {
            if ( ! is_int( $value ) ) { throw new \InvalidArgumentException( $context ); }
            return $value;
        }
        public static function bool( mixed $value, string $context ): bool {
            if ( ! is_bool( $value ) ) { throw new \InvalidArgumentException( $context ); }
            return $value;
        }
        public static function invalid_value( string $context ): never {
            throw new \InvalidArgumentException( $context );
        }
    }
}
namespace CoreBlueprint\Core\Log {
    final class AuditLog {
        public const SEVERITIES = [ 'critical', 'warning', 'notice', 'info' ];
        public static array $events = [];
        public static function log( string $name, string $level, array $context = [] ): void {
            self::$events[] = $name;
        }
    }
}
namespace CoreBlueprint\Core\Security {
    final class FakeModule {
        public function features(): array { return [ [ 'id' => 'test_feature' ] ]; }
    }
    final class ModuleRegistry {
        public static function get( string $slug ): ?FakeModule {
            return 'headers' === $slug ? new FakeModule() : null;
        }
    }
    final class LoginShield {
        public const MODE_STANDARD = 'standard';
        public const MODES = [ 'standard' ];
        public const RESPONSE_CODE_404 = 404;
        public const RESPONSE_CODES = [ 404 ];
        public static int $calls = 0;
        public static function sanitize_slug( string $slug ): string { return $slug; }
        public static function config(): array {
            return [ 'slug' => 'private', 'mode' => 'standard', 'block_response_code' => 404 ];
        }
        public static function save( array $config, string $actor ): array {
            self::$calls++;
            return $config;
        }
    }
}
namespace CoreBlueprint\Core\Security\TwoFactor {
    final class Policy {
        public const SCOPE_PRIVILEGED = 'privileged';
        public static function config(): array { return [ 'mode' => 'optional', 'scope' => 'privileged' ]; }
        public static function mode(): string { return 'optional'; }
        public static function is_valid_mode( string $value ): bool { return 'optional' === $value; }
    }
    final class PolicyMutation {
        public static int $calls = 0;
        public static function set_mode( string $mode, \WP_User $actor, string $source ): bool {
            self::$calls++;
            return true;
        }
    }
}
namespace CoreBlueprint\Core\Modules {
    interface ModuleStateInterface {
        public static function is_enabled(): bool;
        public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void;
    }
}
namespace CoreBlueprint\Core\Environment {
    final class Governance {
        public const POLICY_KEY = 'environment_governance';
        public const PROTECT_SEARCH_INDEXING = 'protect_search_indexing';
        public static function policy(): array {
            return \CoreBlueprint\Core\Settings::get()[self::POLICY_KEY] ?? [];
        }
        public static function is_non_production(): bool { return false; }
        public static function current_type(): string { return 'production'; }
    }
}
namespace CoreBlueprint\Core\Admin {
    final class Admin {
        public const SAFEGUARDS_SLUG = 'core-blueprint-safeguards';
    }
}
namespace CoreBlueprint\Core {
    /**
     * Preserve a deliberately stale request projection independently of the
     * authoritative stored document. Consumer logic must never treat get()
     * equality as verified persistence.
     */
    final class Settings {
        public static array $cached = [];
        public static ?array $stored = [];
        public static array $outcomes = [];
        public static string $status = 'not_attempted';
        public static array $mutations = [];
        public static function seed( array $cache, ?array $storage, string ...$outcomes ): void {
            self::$cached = $cache;
            self::$stored = $storage;
            self::$outcomes = $outcomes;
            self::$status = 'not_attempted';
            self::$mutations = [];
        }
        public static function get(): array { return self::$cached; }
        public static function last_write_status(): string { return self::$status; }
        public static function matches_paths( array $expected ): bool {
            if ( ! is_array( self::$stored ) ) { return false; }
            foreach ( $expected as $path => $wanted ) {
                $node = self::$stored;
                foreach ( explode( '.', $path ) as $part ) {
                    if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) { return false; }
                    $node = $node[$part];
                }
                if ( $node !== $wanted ) { return false; }
            }
            return true;
        }
        private static function outcome(): bool {
            self::$status = array_shift( self::$outcomes ) ?? 'unchanged';
            self::$mutations[] = self::$status;
            return 'saved' === self::$status;
        }
        public static function set_key( string $key, mixed $value, string $actor = '' ): bool {
            if ( ! self::outcome() ) { return false; }
            self::$stored[$key] = $value;
            self::$cached = self::$stored;
            return true;
        }
        public static function set_shield_enabled( bool $enabled, string $actor = '' ): bool {
            return self::set_key( 'shield_enabled', $enabled, $actor );
        }
        public static function shield_enabled(): bool {
            return (bool) ( self::$cached['shield_enabled'] ?? true );
        }
        public static function set_module_enabled( string $slug, bool $enabled, string $actor = '' ): bool {
            if ( ! self::outcome() ) { return false; }
            self::$stored['modules'][$slug]['enabled'] = $enabled;
            self::$cached = self::$stored;
            return true;
        }
        public static function set_feature_enabled( string $slug, string $feature, bool $enabled, string $actor = '' ): bool {
            if ( ! self::outcome() ) { return false; }
            self::$stored['modules'][$slug]['features'][$feature] = $enabled;
            self::$cached = self::$stored;
            return true;
        }
    }
}
namespace {
    require_once dirname( __DIR__ ) . '/src/Profiles/Sections/SecuritySection.php';
    require_once dirname( __DIR__ ) . '/src/Profiles/Sections/AuditNotificationsSection.php';
    require_once dirname( __DIR__ ) . '/src/Profiles/Sections/EnvironmentGovernanceSection.php';
    require_once dirname( __DIR__ ) . '/src/Routing/Policy.php';
    require_once dirname( __DIR__ ) . '/src/Security/CoreShieldState.php';
    require_once dirname( __DIR__ ) . '/src/Environment/Admin.php';

    $settings = \CoreBlueprint\Core\Settings::class;
    $audit = \CoreBlueprint\Core\Log\AuditLog::class;
    $alerts = [ 'critical' => true, 'warning' => false, 'notice' => true, 'info' => false ];
    $desired = [
        'audit' => [ 'email_alerts' => $alerts ],
        'environment_governance' => [ 'protect_search_indexing' => true ],
        'routing' => [ 'clean_archive_urls' => true ],
        'shield_enabled' => true,
        'modules' => [ 'headers' => [ 'enabled' => true, 'features' => [ 'test_feature' => true ] ] ],
    ];
    $other = [
        'audit' => [ 'email_alerts' => array_fill_keys( array_keys( $alerts ), false ) ],
        'environment_governance' => [ 'protect_search_indexing' => false ],
        'routing' => [ 'clean_archive_urls' => false ],
        'shield_enabled' => false,
        'modules' => [ 'headers' => [ 'enabled' => false, 'features' => [ 'test_feature' => false ] ] ],
    ];

    // Security Profile: cached apparent target must not conceal refused module.
    $profile = new \CoreBlueprint\Core\Profiles\Sections\SecuritySection();
    $incoming = [
        'login_shield' => [ 'slug' => 'private', 'mode' => 'standard', 'block_response_code' => 404 ],
        'core_modules' => [ 'headers' => [ 'enabled' => true, 'features' => [ 'test_feature' => true ] ] ],
        'two_factor' => [ 'mode' => 'optional', 'scope' => 'privileged' ],
    ];
    $settings::seed( $desired, $other, 'refused' );
    s1_six_throws( static fn() => $profile->apply( $incoming, 'operator' ), 'Security module refusal must throw' );
    s1_six_assert( 0 === \CoreBlueprint\Core\Security\TwoFactor\PolicyMutation::$calls, '2FA side effects must not run after module refusal' );
    $featureStale = $desired;
    $featureStale['modules']['headers']['features']['test_feature'] = false;
    $settings::seed( $desired, $featureStale, 'unchanged', 'refused' );
    s1_six_throws( static fn() => $profile->apply( $incoming, 'operator' ), 'Security feature refusal must throw' );
    $settings::seed( $desired, $desired, 'unchanged', 'unchanged' );
    $profile->apply( $incoming, 'operator' );
    s1_six_assert( [ 'unchanged', 'unchanged' ] === $settings::$mutations, 'Security verified no-op keeps both passes' );
    // Existing compensation guard must still fail before applying a rollback.
    \CoreBlueprint\Core\Profiles\StateGuard::$canRestore = false;
    $settings::seed( $desired, $desired );
    s1_six_throws( static fn() => $profile->restore( $incoming, $incoming, 'operator' ), 'Security rollback state guard must remain enforced' );
    s1_six_assert( [] === $settings::$mutations, 'Rejected rollback performs no Settings write' );

    // Audit Notifications Profile: only an actual committed policy or proven
    // no-op is admissible after a false return.
    $auditProfile = new \CoreBlueprint\Core\Profiles\Sections\AuditNotificationsSection();
    $auditIncoming = [ 'email_alerts' => $alerts ];
    $settings::seed( $desired, $other, 'refused' );
    s1_six_throws( static fn() => $auditProfile->apply( $auditIncoming, 'operator' ), 'audit notification refusal must throw' );
    $settings::seed( $desired, $desired, 'conflict' );
    s1_six_throws( static fn() => $auditProfile->apply( $auditIncoming, 'operator' ), 'competing audit writer matching target must not count as our write' );
    $settings::seed( $desired, $desired, 'unchanged' );
    $auditProfile->apply( $auditIncoming, 'operator' );
    $settings::seed( $desired, $other, 'saved' );
    $auditProfile->apply( $auditIncoming, 'operator' );
    s1_six_assert( $settings::matches_paths( [ 'audit.email_alerts' => $alerts ] ), 'audit profile committed target' );

    // Environment Governance Profile: identical false-success boundaries.
    $envProfile = new \CoreBlueprint\Core\Profiles\Sections\EnvironmentGovernanceSection();
    $envIncoming = [ 'protect_search_indexing' => true ];
    $settings::seed( $desired, $other, 'refused' );
    s1_six_throws( static fn() => $envProfile->apply( $envIncoming, 'operator' ), 'environment profile refusal must throw' );
    $settings::seed( $desired, $desired, 'conflict' );
    s1_six_throws( static fn() => $envProfile->apply( $envIncoming, 'operator' ), 'environment competing writer must not count as our write' );
    $settings::seed( $desired, $desired, 'unchanged' );
    $envProfile->apply( $envIncoming, 'operator' );

    // Routing API preserves bool return and differentiates four write outcomes.
    $routing = \CoreBlueprint\Core\Routing\Policy::class;
    foreach ( [
        [ 'refused', $other, false ],
        [ 'conflict', $desired, false ],
        [ 'failed', $other, false ],
        [ 'unchanged', $desired, true ],
        [ 'saved', $other, true ],
    ] as [ $outcome, $live, $shouldSucceed ] ) {
        $settings::seed( $desired, $live, $outcome );
        s1_six_assert( $shouldSucceed === $routing::set_enabled( true, 'operator' ), 'routing outcome ' . $outcome );
    }
    $settings::seed( $desired, null, 'unchanged' );
    s1_six_assert( ! $routing::set_enabled( true, 'operator' ), 'routing missing row fail closed' );

    // Core Shield: both already-target and actual mutations check durable state.
    $shield = \CoreBlueprint\Core\Security\CoreShieldState::class;
    $settings::seed( $desired, $other );
    s1_six_throws( static fn() => $shield::set_enabled( true, 'operator' ), 'stale shield no-op must throw' );
    $cachedOff = $desired;
    $cachedOff['shield_enabled'] = false;
    $settings::seed( $cachedOff, $other, 'refused' );
    s1_six_throws( static fn() => $shield::set_enabled( true, 'operator' ), 'refused Core Shield write must throw' );
    $settings::seed( $cachedOff, $desired, 'conflict' );
    s1_six_throws( static fn() => $shield::set_enabled( true, 'operator' ), 'coincidentally matching Core Shield target is not success' );
    $settings::seed( $desired, $desired );
    $shield::set_enabled( true, 'operator' );
    s1_six_assert( [] === $settings::$mutations, 'confirmed shield no-op avoids write' );
    $settings::seed( $cachedOff, $other, 'saved' );
    $shield::set_enabled( true, 'operator' );

    // Environment Admin keeps CSRF/capability/redirect protocol. Intercept
    // wp_safe_redirect with a typed exception before the endpoint exits.
    $_POST = [ 'protect_search_indexing' => '1' ];
    foreach ( [
        [ 'refused', $other, 'error' ],
        [ 'conflict', $desired, 'error' ],
        [ 'failed', $other, 'error' ],
        [ 'unchanged', $desired, 'success' ],
        [ 'saved', $other, 'success' ],
    ] as [ $outcome, $live, $expectedMessage ] ) {
        $settings::seed( $desired, $live, $outcome );
        $redirect = '';
        try {
            \CoreBlueprint\Core\Environment\Admin::save();
        } catch ( S1Redirect $redirectException ) {
            $redirect = $redirectException->getMessage();
        }
        s1_six_assert( str_contains( $redirect, 'environment_saved=' . $expectedMessage ), 'environment admin redirect ' . $outcome );
    }
    s1_six_assert( [] === $audit::$events, 'no consumer-level false success audit in veto/conflict fixtures' );

    echo "Core Blueprint Settings A2.4-S1 six-consumer source regression PASS\n";
}
