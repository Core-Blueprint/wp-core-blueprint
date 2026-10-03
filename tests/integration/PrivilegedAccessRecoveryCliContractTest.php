<?php
declare(strict_types=1);

use CoreBlueprint\Core\CLI\Commands\Operator\Add as OperatorAdd;
use CoreBlueprint\Core\CLI\Commands\Operator\Recover as OperatorRecover;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\RolePolicySchema;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Permissions\UserRoleAssignments;

final class CB_Base_Privileged_Access_Recovery_CLI_Contract_Test extends WP_UnitTestCase {

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_trusted_cli_recovery_reapproves_exact_state_without_replacing_base_role(): void {
        if ( ! defined( 'WP_CLI' ) ) {
            define( 'WP_CLI', true );
        }
        self::assertTrue( (bool) WP_CLI, 'Recovery success contract requires a trusted WP-CLI process.' );

        RolePolicySchema::repair();
        update_option( 'cb_core_privileged_guard_bootstrapped', time(), false );

        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $add = ( new OperatorAdd() )->execute( [ 'user' => (string) $user_id ] );
        self::assertSame( 'success', $add->status );

        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertContains( 'administrator', (array) $user->roles );
        self::assertContains( Roles::OPERATOR_ROLE, (array) $user->roles );
        self::assertTrue( PrivilegedAccessRegistry::is_approved( $user ) );

        // Simulate an out-of-band privileged fingerprint change. The existing
        // Operator assignment stays present, but its prior signed approval no
        // longer matches the exact current identity state.
        $user->add_cap( 'cb_manage_snippets', true );
        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertFalse( PrivilegedAccessRegistry::is_approved( $user ) );
        self::assertNotEmpty( PrivilegedAccessRegistry::review_state( $user ) );

        $recover = ( new OperatorRecover() )->execute( [ 'user' => (string) $user_id ] );
        self::assertSame( 'success', $recover->status );

        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertContains( 'administrator', (array) $user->roles );
        self::assertContains( Roles::OPERATOR_ROLE, (array) $user->roles );
        self::assertSame( 'administrator', UserRoleAssignments::base_role( $user ) );
        self::assertTrue( PrivilegedAccessRegistry::is_approved( $user ) );
        self::assertSame( [], PrivilegedAccessRegistry::review_state( $user ) );
    }
}
