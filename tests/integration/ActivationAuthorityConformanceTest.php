<?php
declare(strict_types=1);

use CoreBlueprint\Core\Ajax\Handlers\Modules as ModuleActions;
use CoreBlueprint\Core\Ajax\Handlers\Reports as ReportsActions;
use CoreBlueprint\Core\Integrity\Rest\ScanController;
use CoreBlueprint\Core\Integrity\State as IntegrityState;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Modules\ActivationRegistry;
use CoreBlueprint\Core\Notes\Rest\NotesController;
use CoreBlueprint\Core\Notes\State as NotesState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Reports\State as ReportsState;

final class CB_C3_Test_Termination extends RuntimeException {}

final class CB_Base_Activation_Authority_Conformance_Test extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        Roles::ensure_operator_role();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    public function test_c3_legacy_activation_authorities_are_absent(): void {
        global $wp_rest_server;
        $wp_rest_server = null;

        self::assertNotFalse(
            has_action( 'rest_api_init', [ ScanController::class, 'register_routes' ] ),
            'Scanner REST controller is not attached to the canonical rest_api_init lifecycle.'
        );
        self::assertNotFalse(
            has_action( 'rest_api_init', [ NotesController::class, 'register' ] ),
            'Notes REST controller is not attached to the canonical rest_api_init lifecycle.'
        );

        // rest_get_server() creates the server and fires rest_api_init through
        // WordPress itself. Do not call controller registration methods directly:
        // WordPress intentionally flags that as incorrect usage in integration tests.
        $routes = rest_get_server()->get_routes();
        ReportsActions::init();

        self::assertArrayNotHasKey(
            '/core-blueprint/v1/integrity/admin/enable',
            $routes,
            'Scanner still exposes its legacy activation REST authority.'
        );
        self::assertArrayNotHasKey(
            '/core-blueprint/v1/notes/enable',
            $routes,
            'Notes still exposes its legacy activation REST authority.'
        );
        self::assertFalse(
            has_action( 'wp_ajax_cb_core_set_reports_enabled' ),
            'Reports still exposes its legacy activation AJAX authority.'
        );

        self::assertFalse( method_exists( ScanController::class, 'set_subsystem_enabled' ) );
        self::assertFalse( method_exists( NotesController::class, 'enable' ) );
        self::assertFalse( method_exists( ReportsActions::class, 'set_reports_enabled' ) );
    }

    public function test_c3_activation_registry_is_the_canonical_authority(): void {
        $expected = [
            'core-scanner' => [
                'state'      => IntegrityState::class,
                'capability' => 'cb_manage_integrity_policy',
            ],
            'notes' => [
                'state'      => NotesState::class,
                'capability' => 'cb_manage_notes',
            ],
            'reports' => [
                'state'      => ReportsState::class,
                'capability' => 'cb_manage_reports',
            ],
        ];

        foreach ( $expected as $module => $definition ) {
            self::assertSame( $definition, ActivationRegistry::definition( $module ), $module . ': canonical definition drifted.' );
        }

        self::assertNull( ActivationRegistry::definition( 'not-a-real-module' ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_c3_shared_ajax_authority_enforces_caps_persists_and_audits(): void {
        Roles::ensure_operator_role();
        ModuleActions::init();

        self::assertNotFalse(
            has_action( 'wp_ajax_cb_core_set_module_enabled', [ ModuleActions::class, 'set_enabled' ] ),
            'Canonical module activation AJAX authority is not registered.'
        );
        self::assertFalse( has_action( 'wp_ajax_nopriv_cb_core_set_module_enabled' ) );

        $operator_id = self::factory()->user->create( [ 'role' => Roles::OPERATOR_ROLE ] );
        $operator = get_userdata( $operator_id );
        self::assertInstanceOf( WP_User::class, $operator );
        self::assertTrue(
            PrivilegedAccessRegistry::approve( $operator, 0, 'c3-activation-authority-fixture' ),
            'Could not approve C3 Operator fixture.'
        );

        $subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        foreach ( $this->module_specs() as $module => $spec ) {
            $state = $spec['state'];
            $initial = (bool) $state::is_enabled();
            $target = ! $initial;

            wp_set_current_user( $subscriber_id );
            $denied_event = $this->event_for( $spec['event_prefix'], $target );
            $denied_audit = $this->audit_count( $denied_event );
            $denied = $this->invoke_module_action( $module, $target );

            self::assertFalse( (bool) ( $denied['success'] ?? true ), $module . ': wrong-capability request unexpectedly succeeded.' );
            self::assertSame( $initial, (bool) $state::is_enabled(), $module . ': wrong-capability request mutated state.' );
            self::assertSame( $denied_audit, $this->audit_count( $denied_event ), $module . ': denied request emitted a success audit.' );

            wp_set_current_user( $operator_id );
            $success_event = $this->event_for( $spec['event_prefix'], $target );
            $success_audit = $this->audit_count( $success_event );
            $success = $this->invoke_module_action( $module, $target );

            self::assertTrue( (bool) ( $success['success'] ?? false ), $module . ': canonical activation request failed.' );
            self::assertSame( $target, (bool) $state::is_enabled(), $module . ': canonical activation request did not persist.' );
            self::assertSame( $success_audit + 1, $this->audit_count( $success_event ), $module . ': transition did not emit exactly one audit.' );

            $restore = $this->invoke_module_action( $module, $initial );
            self::assertTrue( (bool) ( $restore['success'] ?? false ), $module . ': canonical restore request failed.' );
            self::assertSame( $initial, (bool) $state::is_enabled(), $module . ': pre-test state was not restored.' );
        }

        wp_set_current_user( $operator_id );
        $before = $this->state_snapshot();
        $unknown = $this->invoke_module_action( 'not-a-real-module', true );
        self::assertFalse( (bool) ( $unknown['success'] ?? true ), 'Unknown module did not fail closed.' );
        self::assertSame( $before, $this->state_snapshot(), 'Unknown module request mutated canonical module state.' );
    }

    /** @return array<string,array{state:class-string,event_prefix:string}> */
    private function module_specs(): array {
        return [
            'core-scanner' => [
                'state'        => IntegrityState::class,
                'event_prefix' => 'integrity_subsystem_',
            ],
            'notes' => [
                'state'        => NotesState::class,
                'event_prefix' => 'notes_subsystem_',
            ],
            'reports' => [
                'state'        => ReportsState::class,
                'event_prefix' => 'reports_subsystem_',
            ],
        ];
    }

    /** @return array{core-scanner:bool,notes:bool,reports:bool} */
    private function state_snapshot(): array {
        return [
            'core-scanner' => IntegrityState::is_enabled(),
            'notes'        => NotesState::is_enabled(),
            'reports'      => ReportsState::is_enabled(),
        ];
    }

    /** @return array<string,mixed> */
    private function invoke_module_action( string $module, bool $enabled ): array {
        $_POST = [
            'action'  => 'cb_core_set_module_enabled',
            'nonce'   => wp_create_nonce( 'cb_core_admin' ),
            'module'  => $module,
            'enabled' => $enabled ? '1' : '0',
        ];
        $_REQUEST = $_POST;

        $output = $this->capture_termination(
            static fn() => do_action( 'wp_ajax_cb_core_set_module_enabled' )
        );

        $payload = json_decode( $output, true );
        self::assertIsArray( $payload, 'Module activation endpoint did not return a JSON object.' );
        return $payload;
    }

    private function capture_termination( callable $callback ): string {
        $die_handler = static function ( $message = '', $title = '', $args = [] ): void {
            unset( $message, $title, $args );
            throw new CB_C3_Test_Termination( '__CB_C3_TEST_TERMINATION__' );
        };
        $handler_filter = static fn() => $die_handler;
        $ajax_filter = static fn() => true;
        $level = ob_get_level();

        add_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
        add_filter( 'wp_die_ajax_handler', $handler_filter, PHP_INT_MAX );
        add_filter( 'wp_doing_ajax', $ajax_filter, PHP_INT_MAX );

        ob_start();
        try {
            $callback();
            self::fail( 'Expected WordPress JSON termination did not occur.' );
        } catch ( CB_C3_Test_Termination $termination ) {
            unset( $termination );
            $output = (string) ob_get_clean();
        } finally {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            remove_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
            remove_filter( 'wp_die_ajax_handler', $handler_filter, PHP_INT_MAX );
            remove_filter( 'wp_doing_ajax', $ajax_filter, PHP_INT_MAX );
        }

        return $output;
    }

    private function event_for( string $prefix, bool $enabled ): string {
        return $prefix . ( $enabled ? 'enabled' : 'disabled' );
    }

    private function audit_count( string $event_type ): int {
        $result = AuditLog::query( [
            'event_type' => AuditLog::normalize_event_type( $event_type ),
            'per_page'   => 1,
        ] );
        return (int) $result['total'];
    }
}
