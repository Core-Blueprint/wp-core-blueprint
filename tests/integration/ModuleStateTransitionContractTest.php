<?php
declare(strict_types=1);

use CoreBlueprint\Core\Integrity\Scheduler\Cron as IntegrityCron;
use CoreBlueprint\Core\Integrity\State as IntegrityState;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Mail\Settings as MailSettings;
use CoreBlueprint\Core\Mail\State as MailState;
use CoreBlueprint\Core\MediaReplace\State as MediaReplaceState;
use CoreBlueprint\Core\Notes\State as NotesState;
use CoreBlueprint\Core\PackageDownload\State as PackageDownloadState;
use CoreBlueprint\Core\Permissions\UserRolesState;
use CoreBlueprint\Core\Reports\State as ReportsState;

final class CB_Base_Module_State_Transition_Contract_Test extends WP_UnitTestCase {

    public function test_b1_state_transition_contract(): void {
        foreach ( $this->module_specs() as $id => $spec ) {
            $state = $spec['state'];
            $initial = (bool) $state::is_enabled();

            // Same-state calls are true no-ops: no transition audit is emitted.
            $same_event = $this->event_for( $spec['event_prefix'], $initial );
            $same_before = $this->audit_count( $same_event );
            $state::set_enabled( $initial, 'b1-same-' . $id );
            self::assertSame( $same_before, $this->audit_count( $same_event ), $id . ': same-state call emitted a transition audit.' );

            // A successful transition must be observable through the canonical
            // state reader before the adapter reports success.
            $target = ! $initial;
            $target_event = $this->event_for( $spec['event_prefix'], $target );
            $target_before = $this->audit_count( $target_event );
            $state::set_enabled( $target, 'b1-success-' . $id );
            self::assertSame( $target, (bool) $state::is_enabled(), $id . ': successful transition did not persist.' );
            self::assertSame( $target_before + 1, $this->audit_count( $target_event ), $id . ': successful transition did not emit exactly one audit event.' );

            // Restore the original state before exercising persistence refusal.
            $state::set_enabled( $initial, 'b1-restore-' . $id );
            self::assertSame( $initial, (bool) $state::is_enabled(), $id . ': could not restore pre-test state.' );

            $failure_event = $this->event_for( $spec['event_prefix'], $target );
            $failure_before = $this->audit_count( $failure_event );
            $filters = $this->block_option_writes( $spec['options'] );
            $thrown = null;
            try {
                $state::set_enabled( $target, 'b1-failure-' . $id );
            } catch ( RuntimeException $exception ) {
                $thrown = $exception;
            } finally {
                $this->remove_option_write_blocks( $filters );
            }

            self::assertInstanceOf( RuntimeException::class, $thrown, $id . ': refused persistence did not fail hard.' );
            self::assertSame( $initial, (bool) $state::is_enabled(), $id . ': refused persistence changed canonical state.' );
            self::assertSame( $failure_before, $this->audit_count( $failure_event ), $id . ': refused persistence emitted a success transition audit.' );
        }

        $this->assert_scanner_refused_disable_has_no_runtime_side_effects();
    }

    /** @return array<string,array{state:class-string,event_prefix:string,options:string[]}> */
    private function module_specs(): array {
        return [
            'core-scanner' => [
                'state'        => IntegrityState::class,
                'event_prefix' => 'integrity_subsystem_',
                'options'      => [ CB_CORE_SETTINGS ],
            ],
            'notes' => [
                'state'        => NotesState::class,
                'event_prefix' => 'notes_subsystem_',
                'options'      => [ CB_CORE_SETTINGS ],
            ],
            'reports' => [
                'state'        => ReportsState::class,
                'event_prefix' => 'reports_subsystem_',
                'options'      => [ CB_CORE_SETTINGS ],
            ],
            'mail' => [
                'state'        => MailState::class,
                'event_prefix' => 'mail_subsystem_',
                'options'      => [ MailSettings::OPTION ],
            ],
            'media-replace' => [
                'state'        => MediaReplaceState::class,
                'event_prefix' => 'media_replace_subsystem_',
                'options'      => [ 'cb_core_media_replace_enabled' ],
            ],
            'package-downloads' => [
                'state'        => PackageDownloadState::class,
                'event_prefix' => 'package_download_subsystem_',
                'options'      => [ 'cb_core_package_download_enabled' ],
            ],
            'user-roles' => [
                'state'        => UserRolesState::class,
                'event_prefix' => 'user_roles_subsystem_',
                'options'      => [ 'cb_core_user_roles_enabled' ],
            ],
        ];
    }

    private function assert_scanner_refused_disable_has_no_runtime_side_effects(): void {
        $initial = IntegrityState::is_enabled();
        if ( ! $initial ) {
            IntegrityState::set_enabled( true, 'b1-scanner-precondition' );
        }

        wp_clear_scheduled_hook( IntegrityCron::HOOK );
        $timestamp = time() + HOUR_IN_SECONDS;
        self::assertNotFalse( wp_schedule_single_event( $timestamp, IntegrityCron::HOOK ), 'Could not seed Scanner cron side-effect sentinel.' );
        $scheduled_before = wp_next_scheduled( IntegrityCron::HOOK );

        $filters = $this->block_option_writes( [ CB_CORE_SETTINGS ] );
        $thrown = null;
        try {
            IntegrityState::set_enabled( false, 'b1-scanner-side-effect-refusal' );
        } catch ( RuntimeException $exception ) {
            $thrown = $exception;
        } finally {
            $this->remove_option_write_blocks( $filters );
        }

        self::assertInstanceOf( RuntimeException::class, $thrown );
        self::assertTrue( IntegrityState::is_enabled(), 'Scanner refusal changed state.' );
        self::assertSame( $scheduled_before, wp_next_scheduled( IntegrityCron::HOOK ), 'Scanner refusal cleared/rescheduled cron before persistence verification.' );

        wp_clear_scheduled_hook( IntegrityCron::HOOK );
        if ( ! $initial ) {
            IntegrityState::set_enabled( false, 'b1-scanner-precondition-restore' );
        }
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

    /** @return array<int,array{hook:string,callback:callable}> */
    private function block_option_writes( array $options ): array {
        $filters = [];
        foreach ( $options as $option ) {
            $hook = 'pre_update_option_' . $option;
            $callback = static function ( $value, $old_value ) {
                unset( $value );
                return $old_value;
            };
            add_filter( $hook, $callback, PHP_INT_MAX, 2 );
            $filters[] = [ 'hook' => $hook, 'callback' => $callback ];
        }
        return $filters;
    }

    /** @param array<int,array{hook:string,callback:callable}> $filters */
    private function remove_option_write_blocks( array $filters ): void {
        foreach ( $filters as $filter ) {
            remove_filter( $filter['hook'], $filter['callback'], PHP_INT_MAX );
        }
    }
}
