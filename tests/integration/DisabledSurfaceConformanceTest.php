<?php
declare(strict_types=1);

use CoreBlueprint\Core\Ajax\Handlers\Reports as ReportsActions;
use CoreBlueprint\Core\Integrity\Scheduler\Cron as IntegrityCron;
use CoreBlueprint\Core\Integrity\State as IntegrityState;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Mail\Admin\Actions as MailActions;
use CoreBlueprint\Core\Mail\Settings as MailSettings;
use CoreBlueprint\Core\Mail\State as MailState;
use CoreBlueprint\Core\MediaFormats\Admin\Actions as MediaFormatsActions;
use CoreBlueprint\Core\MediaFormats\Settings as MediaFormatsSettings;
use CoreBlueprint\Core\MediaFormats\State as MediaFormatsState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Reports\State as ReportsState;

final class CB_B2_Test_Termination extends RuntimeException {
    /** @var mixed */
    public $wp_message;

    /** @var mixed */
    public $wp_title;

    /** @var mixed */
    public $wp_args;

    public function __construct( $message, $title, $args ) {
        parent::__construct( '__CB_B2_TEST_TERMINATION__' );
        $this->wp_message = $message;
        $this->wp_title   = $title;
        $this->wp_args    = $args;
    }
}

final class CB_Base_Disabled_Surface_Conformance_Test extends WP_UnitTestCase {

    public function test_b2_disabled_surface_contract(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $user    = get_user_by( 'id', $user_id );
        self::assertInstanceOf( WP_User::class, $user );
        $user->add_cap( 'cb_manage_reports' );
        $user->add_cap( 'cb_view_reports' );

        // These manage capabilities are intentionally part of Base's privileged
        // identity boundary. Approve the exact test fingerprint through the real
        // signed registry instead of bypassing capability policy with a filter.
        self::assertTrue(
            PrivilegedAccessRegistry::approve( $user, 0, 'b2-test' ),
            'Could not approve the B2 privileged test actor.'
        );
        wp_set_current_user( $user_id );
        self::assertTrue( current_user_can( 'cb_manage_reports' ), 'B2 actor lacks cb_manage_reports after approval.' );
        self::assertTrue( current_user_can( 'cb_view_reports' ), 'B2 actor lacks cb_view_reports after approval.' );

        $this->assert_scanner_sync_converges_to_no_cron_while_disabled();
        $this->assert_reports_destructive_mutations_are_blocked_while_disabled();
        $this->assert_mail_settings_mutation_is_blocked_but_log_maintenance_remains();
        $this->assert_media_formats_settings_mutation_is_blocked_while_disabled();
    }

    private function assert_scanner_sync_converges_to_no_cron_while_disabled(): void {
        $initial_state    = IntegrityState::is_enabled();
        $initial_settings = ResultRepository::settings();

        try {
            if ( ! $initial_state ) {
                IntegrityState::set_enabled( true, 'b2-scanner-precondition' );
            }
            ResultRepository::saveSettings( [ 'schedule' => 'daily' ] );
            IntegrityState::set_enabled( false, 'b2-scanner-disabled' );

            // Simulate a stale externally-created/leftover event. sync_schedule()
            // must converge disabled Scanner state back to zero scheduled workload.
            self::assertNotFalse(
                wp_schedule_single_event( time() + HOUR_IN_SECONDS, IntegrityCron::HOOK ),
                'Could not seed stale Scanner cron event.'
            );
            self::assertNotFalse( wp_next_scheduled( IntegrityCron::HOOK ), 'Stale Scanner cron sentinel was not scheduled.' );

            IntegrityCron::sync_schedule();
            self::assertFalse( wp_next_scheduled( IntegrityCron::HOOK ), 'Disabled Scanner retained or recreated a cron event.' );
        } finally {
            wp_clear_scheduled_hook( IntegrityCron::HOOK );
            ResultRepository::saveSettings( [ 'schedule' => (string) ( $initial_settings['schedule'] ?? 'disabled' ) ] );
            if ( IntegrityState::is_enabled() !== $initial_state ) {
                IntegrityState::set_enabled( $initial_state, 'b2-scanner-restore' );
            }
        }
    }

    private function assert_reports_destructive_mutations_are_blocked_while_disabled(): void {
        $initial = ReportsState::is_enabled();

        try {
            if ( ! $initial ) {
                ReportsState::set_enabled( true, 'b2-reports-precondition' );
            }
            ReportsState::set_enabled( false, 'b2-reports-disabled' );

            $single = $this->capture_termination(
                static function (): void {
                    $_POST = [
                        'nonce'     => wp_create_nonce( 'cb_core_admin' ),
                        'report_id' => '1',
                    ];
                    $_REQUEST = $_POST;
                    ReportsActions::delete_maintenance();
                }
            );
            self::assertStringContainsString( 'cb_reports_subsystem_disabled', $single['output'] );

            $bulk = $this->capture_termination(
                static function (): void {
                    $_POST = [
                        'nonce'   => wp_create_nonce( 'cb_core_admin' ),
                        'confirm' => 'DELETE ALL REPORTS',
                    ];
                    $_REQUEST = $_POST;
                    ReportsActions::delete_all_maintenance();
                }
            );
            self::assertStringContainsString( 'cb_reports_subsystem_disabled', $bulk['output'] );

            // Read-only history access remains intentional while disabled. A valid
            // download request for an unknown row must reach the normal 404 path,
            // rather than being stopped by the disabled mutation gate.
            $read_only = $this->capture_termination(
                static function (): void {
                    $_GET = [
                        'id'           => '999999',
                        '_cb_dl_nonce' => wp_create_nonce( 'cb_core_download_report_999999' ),
                    ];
                    $_REQUEST = $_GET;
                    ReportsActions::download_maintenance();
                }
            );
            self::assertSame( 404, $this->response_code( $read_only['termination'] ), 'Disabled Reports blocked read-only history access.' );
        } finally {
            $_GET = [];
            $_POST = [];
            $_REQUEST = [];
            if ( ReportsState::is_enabled() !== $initial ) {
                ReportsState::set_enabled( $initial, 'b2-reports-restore' );
            }
        }
    }

    private function assert_mail_settings_mutation_is_blocked_but_log_maintenance_remains(): void {
        $initial_state = MailState::is_enabled();

        try {
            if ( $initial_state ) {
                MailState::set_enabled( false, 'b2-mail-disabled' );
            }
            $disabled_settings = MailSettings::all();

            $result = $this->capture_termination(
                static function (): void {
                    $_POST = [
                        '_wpnonce'  => wp_create_nonce( 'cb_core_mail_save' ),
                        'provider'  => 'smtp',
                        'smtp_host' => 'b2.invalid.example',
                    ];
                    $_REQUEST = $_POST;
                    MailActions::save();
                }
            );

            self::assertSame( 409, $this->response_code( $result['termination'] ) );
            self::assertStringContainsString( 'Mail Delivery is disabled', $this->termination_message( $result['termination'] ) );
            self::assertSame( $disabled_settings, MailSettings::all(), 'Disabled Mail settings request mutated persisted configuration.' );

            // Admin-post registration remains available for explicit datastore
            // maintenance even while transport functionality is disabled.
            MailActions::boot();
            self::assertNotFalse(
                has_action( 'admin_post_cb_core_mail_clear_log', [ MailActions::class, 'clear_log' ] ),
                'Mail Log maintenance was removed while Mail was disabled.'
            );
        } finally {
            $_POST = [];
            $_REQUEST = [];
            if ( MailState::is_enabled() !== $initial_state ) {
                MailState::set_enabled( $initial_state, 'b2-mail-restore' );
            }
        }
    }

    private function assert_media_formats_settings_mutation_is_blocked_while_disabled(): void {
        $initial_state = MediaFormatsState::is_enabled();

        try {
            if ( $initial_state ) {
                MediaFormatsState::set_enabled( false, 'b2-media-formats-disabled' );
            }
            $disabled_settings = MediaFormatsSettings::all();

            $result = $this->capture_termination(
                static function (): void {
                    $_POST = [
                        '_wpnonce'      => wp_create_nonce( 'cb_core_media_formats_save' ),
                        'jxl_uploads'   => '1',
                        'output_format' => 'webp',
                    ];
                    $_REQUEST = $_POST;
                    MediaFormatsActions::save();
                }
            );

            self::assertSame( 409, $this->response_code( $result['termination'] ) );
            self::assertStringContainsString( 'Media Formats is disabled', $this->termination_message( $result['termination'] ) );
            self::assertSame( $disabled_settings, MediaFormatsSettings::all(), 'Disabled Media Formats request mutated settings.' );
        } finally {
            $_POST = [];
            $_REQUEST = [];
            if ( MediaFormatsState::is_enabled() !== $initial_state ) {
                MediaFormatsState::set_enabled( $initial_state, 'b2-media-formats-restore' );
            }
        }
    }


    /**
     * Execute a terminating WordPress handler without changing production code.
     *
     * @return array{termination:CB_B2_Test_Termination,output:string}
     */
    private function capture_termination( callable $callback ): array {
        $die_handler = static function ( $message = '', $title = '', $args = [] ): void {
            throw new CB_B2_Test_Termination( $message, $title, $args );
        };
        $handler_filter = static fn() => $die_handler;
        $ajax_filter    = static fn() => true;

        add_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
        add_filter( 'wp_die_ajax_handler', $handler_filter, PHP_INT_MAX );
        add_filter( 'wp_doing_ajax', $ajax_filter, PHP_INT_MAX );

        $level = ob_get_level();
        ob_start();
        try {
            $callback();
            self::fail( 'Expected WordPress handler to terminate the request.' );
        } catch ( CB_B2_Test_Termination $termination ) {
            $output = (string) ob_get_clean();
            return [ 'termination' => $termination, 'output' => $output ];
        } finally {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            remove_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
            remove_filter( 'wp_die_ajax_handler', $handler_filter, PHP_INT_MAX );
            remove_filter( 'wp_doing_ajax', $ajax_filter, PHP_INT_MAX );
        }
    }

    private function response_code( CB_B2_Test_Termination $termination ): int {
        $args = is_array( $termination->wp_args ) ? $termination->wp_args : [];
        return (int) ( $args['response'] ?? 0 );
    }

    private function termination_message( CB_B2_Test_Termination $termination ): string {
        return is_scalar( $termination->wp_message ) ? (string) $termination->wp_message : '';
    }
}
