<?php
declare(strict_types=1);

use CoreBlueprint\Core\CLI\Commands\Operator\Add as OperatorAdd;
use CoreBlueprint\Core\CLI\Commands\Operator\Recover as OperatorRecover;
use CoreBlueprint\Core\CLI\Commands\Operator\Status as OperatorStatus;
use CoreBlueprint\Core\CLI\Commands\Permissions\RepairRolePolicy;
use CoreBlueprint\Core\CLI\Registry as CLIRegistry;
use CoreBlueprint\Core\Console\Registry as ConsoleRegistry;
use CoreBlueprint\Core\Permissions\Admin\UserRolesFields;
use CoreBlueprint\Core\Permissions\PrivilegedAccessGuard;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\RolePolicySchema;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Permissions\UserRoleAssignments;

final class CB_Base_Privileged_Access_Recovery_Contract_Test extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        // Tests exercise an established, canonical public-v1 role policy.
        RolePolicySchema::repair();
        update_option( 'cb_core_privileged_guard_bootstrapped', time(), false );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        parent::tear_down();
    }

    public function test_operator_is_never_exposed_as_native_base_role(): void {
        $roles = UserRolesFields::filter_editable_roles( wp_roles()->roles );
        self::assertArrayNotHasKey(
            Roles::OPERATOR_ROLE,
            $roles,
            'CB Operator leaked into WordPress native base-role choices.'
        );
    }

    public function test_operator_remains_assignable_through_governed_additional_role_path(): void {
        $actor_id = $this->create_approved_admin_operator();
        wp_set_current_user( $actor_id );

        $target_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        self::assertTrue(
            UserRoleAssignments::can_assign_role( Roles::OPERATOR_ROLE, $target_id ),
            'Hiding CB Operator from editable_roles also disabled the governed Additional roles path.'
        );
    }

    public function test_crafted_operator_base_role_request_is_rejected(): void {
        $actor_id = $this->create_approved_admin_operator();
        wp_set_current_user( $actor_id );

        $target_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $request = new stdClass();
        $request->ID = $target_id;
        $request->role = Roles::OPERATOR_ROLE;
        $errors = new WP_Error();

        UserRolesFields::validate_update( $errors, true, $request );

        self::assertContains(
            'cb_core_user_roles_operator_base_role',
            $errors->get_error_codes(),
            'A crafted profile request could still make CB Operator the WordPress base role.'
        );
        self::assertFalse(
            property_exists( $request, 'role' ),
            'Rejected CB Operator base-role request was left available for WordPress set_role().' 
        );
    }

    public function test_operator_add_is_additive_preserves_administrator_and_signs_final_state(): void {
        $target_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $command = new OperatorAdd();
        $result = $command->execute( [ 'user' => (string) $target_id ] );

        self::assertSame( 'success', $result->status );

        $user = get_userdata( $target_id );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertContains( 'administrator', (array) $user->roles );
        self::assertContains( Roles::OPERATOR_ROLE, (array) $user->roles );
        self::assertSame( 'administrator', UserRoleAssignments::base_role( $user ) );
        self::assertTrue( PrivilegedAccessRegistry::is_approved( $user ) );
    }

    public function test_operator_add_does_not_hide_existing_unapproved_operator_recovery_path(): void {
        $target_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $command = new OperatorAdd();
        self::assertSame( 'success', $command->execute( [ 'user' => (string) $target_id ] )->status );

        $user = get_userdata( $target_id );
        self::assertInstanceOf( WP_User::class, $user );

        // A direct sensitive user capability changes the signed fingerprint.
        $user->add_cap( 'cb_manage_snippets', true );
        $user = get_userdata( $target_id );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertFalse( PrivilegedAccessRegistry::is_approved( $user ) );

        $result = $command->execute( [ 'user' => (string) $target_id ] );
        self::assertSame( 'warning', $result->status );
        self::assertStringContainsString(
            'operator recover',
            implode( "\n", $result->lines ),
            'An already-assigned but unapproved Operator did not receive canonical recovery guidance.'
        );

        $status = ( new OperatorStatus() )->execute( [ 'user' => (string) $target_id ] );
        self::assertSame( 'warning', $status->status );
        self::assertFalse( (bool) $status->data['approved'] );
        self::assertTrue( (bool) $status->data['needs_review'] );
        self::assertSame( 'administrator', $status->data['base_role'] );
    }

    public function test_operator_recover_is_wp_cli_only_and_absent_from_browser_console(): void {
        $cli_names = array_map(
            static fn ( array $entry ): string => (string) $entry['name'],
            CLIRegistry::commands()
        );
        self::assertContains( 'operator recover', $cli_names, 'WP-CLI registry omitted operator recover.' );
        self::assertNull(
            ConsoleRegistry::find( 'cb-operator-recover' ),
            'Break-glass Operator recovery leaked into the browser Console.'
        );

        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            $target_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
            $result = ( new OperatorRecover() )->execute( [ 'user' => (string) $target_id ] );
            self::assertSame( 'error', $result->status );
            self::assertStringContainsString( 'server-side WP-CLI', $result->message );
        }
    }

    public function test_role_policy_repair_re_signs_only_verified_previously_approved_continuity(): void {
        $actor_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $actor = get_userdata( $actor_id );
        self::assertInstanceOf( WP_User::class, $actor );

        $unapproved_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $unapproved = get_userdata( $unapproved_id );
        self::assertInstanceOf( WP_User::class, $unapproved );
        self::assertFalse( PrivilegedAccessRegistry::is_approved( $unapproved ) );

        // Create privilege-bearing Base-owned role-policy drift without emitting
        // an intermediate review event. cb_manage_roles is forbidden on the
        // Administrator role and participates in the signed privilege fingerprint,
        // so canonical repair must remove it and rotate only proven trust continuity.
        $administrator = get_role( 'administrator' );
        self::assertNotNull( $administrator );
        PrivilegedAccessGuard::trusted_mutation(
            static function () use ( $administrator ): void {
                $administrator->add_cap( 'cb_manage_roles' );
            }
        );

        $actor = get_userdata( $actor_id );
        self::assertInstanceOf( WP_User::class, $actor );
        self::assertTrue( PrivilegedAccessRegistry::approve( $actor, 0, 'test_pre_repair_state' ) );
        self::assertFalse( RolePolicySchema::inspect( false, 'test' )['canonical'] );
        wp_set_current_user( $actor_id );

        $result = ( new RepairRolePolicy() )->execute( [] );
        self::assertSame( 'success', $result->status );
        self::assertTrue( (bool) $result->data['canonical'] );
        self::assertGreaterThanOrEqual( 1, (int) $result->data['trust_continuity']['restored'] );

        $actor = get_userdata( $actor_id );
        self::assertInstanceOf( WP_User::class, $actor );
        self::assertTrue(
            PrivilegedAccessRegistry::is_approved( $actor ),
            'Official Role Policy repair invalidated a previously approved identity despite proven continuity.'
        );

        $unapproved = get_userdata( $unapproved_id );
        self::assertInstanceOf( WP_User::class, $unapproved );
        self::assertFalse(
            PrivilegedAccessRegistry::is_approved( $unapproved ),
            'Role Policy repair minted trust for an identity that was not approved before repair.'
        );
    }

    private function create_approved_admin_operator(): int {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );

        PrivilegedAccessGuard::trusted_mutation(
            static function () use ( $user ): void {
                $user->add_role( Roles::OPERATOR_ROLE );
            }
        );
        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'test_fixture' ) );

        return $user_id;
    }
}
