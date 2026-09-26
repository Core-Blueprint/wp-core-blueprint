<?php
declare(strict_types=1);

use CB\Core\Admin\AdminModuleCatalog;
use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Ajax\Handlers\TwoFactorPolicy;
use CB\Core\Ajax\SecurityRouter;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\Roles;
use CB\Core\Security\TwoFactor\CredentialStore;

final class CB_Base_Two_Factor_Safeguards_Policy_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $original_get = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_get = $_GET;
		$_GET['page'] = Safeguards::SLUG;
		$_GET['tab'] = 'two-factor';
		RolePolicySchema::repair();
		update_option( 'cb_core_privileged_guard_bootstrapped', time(), false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_GET = $this->original_get;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_tg1_security_router_registers_password_reconfirmed_policy_endpoint(): void {
		TwoFactorPolicy::init();
		self::assertNotFalse(
			has_action( 'wp_ajax_cb_core_set_two_factor_policy', [ TwoFactorPolicy::class, 'set_mode' ] )
		);

		$handler_file = ( new ReflectionClass( TwoFactorPolicy::class ) )->getFileName();
		self::assertIsString( $handler_file );
		$handler_source = (string) file_get_contents( $handler_file );
		self::assertStringContainsString( 'self::require_password_reconfirm();', $handler_source );
		self::assertStringContainsString( 'PolicyMutation::set_mode(', $handler_source );

		$router_file = ( new ReflectionClass( SecurityRouter::class ) )->getFileName();
		self::assertIsString( $router_file );
		$router_source = (string) file_get_contents( $router_file );
		self::assertStringContainsString( 'TwoFactorPolicy::init();', $router_source );
	}

	public function test_tg2_admin_asset_catalog_contains_two_factor_policy_module(): void {
		self::assertContains( '@cb-core/two-factor-policy', AdminModuleCatalog::ids() );
	}

	public function test_tg3_regular_administrator_gets_read_only_policy_status(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue(
			PrivilegedAccessRegistry::approve( $user, 0, 'two_factor_safeguards_read_only_fixture' )
		);
		wp_set_current_user( $user_id );

		ob_start();
		( new Safeguards() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Two-factor authentication', $html );
		self::assertStringContainsString( 'Policy is read-only for this account.', $html );
		self::assertStringNotContainsString( 'data-cb-core-two-factor-mode', $html );
	}

	public function test_tg4_trusted_operator_gets_policy_controls_and_enrollment_guidance(): void {
		$user_id = $this->create_trusted_operator();
		wp_set_current_user( $user_id );

		ob_start();
		( new Safeguards() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-cb-core-two-factor-mode', $html );
		self::assertStringContainsString( 'Enroll Base two-factor authentication before enforcing.', $html );
		self::assertStringContainsString( 'profile.php#cb-core-two-factor', $html );

		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );

		ob_start();
		( new Safeguards() )->render();
		$enrolled_html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Enroll Base two-factor authentication before enforcing.', $enrolled_html );
		self::assertStringContainsString( 'Base two-factor authentication is active.', $enrolled_html );
	}

	private function create_trusted_operator(): int {
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
		self::assertTrue(
			PrivilegedAccessRegistry::approve( $user, 0, 'two_factor_safeguards_fixture' )
		);

		return $user_id;
	}
}
