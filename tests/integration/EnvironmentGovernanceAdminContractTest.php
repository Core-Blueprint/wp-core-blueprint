<?php
declare(strict_types=1);

use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Admin\ScreenAssetRegistry;
use CB\Core\Admin\ScreenContext;
use CB\Core\Environment\Admin as EnvironmentAdmin;
use CB\Core\Environment\EnvironmentTypeTestShim;
use CB\Core\Environment\Governance;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Settings;

final class CB_Base_Environment_Governance_Admin_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $original_get = [];


	/** @var array<string,mixed> */
	private array $previous_policy = [];

	public function set_up(): void {
		parent::set_up();

		$this->original_get = $_GET;
		EnvironmentTypeTestShim::reset();
		$this->previous_policy = is_array( Settings::get()[ Governance::POLICY_KEY ] ?? null )
			? Settings::get()[ Governance::POLICY_KEY ]
			: Governance::default_policy();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'environment-governance-admin-fixture' ) );
		wp_set_current_user( $user_id );
		self::assertTrue( current_user_can( 'manage_options' ) );
	}

	public function tear_down(): void {
		remove_action( 'admin_post_' . EnvironmentAdmin::SAVE_ACTION, [ EnvironmentAdmin::class, 'save' ] );
		remove_action( 'admin_bar_menu', [ EnvironmentAdmin::class, 'admin_bar_notice' ], 100 );
		Settings::set_key( Governance::POLICY_KEY, $this->previous_policy, 'test:environment-governance-admin-restore' );
		$_GET = $this->original_get;
		wp_set_current_user( 0 );
		EnvironmentTypeTestShim::reset();

		$GLOBALS['current_screen'] = null;
		parent::tear_down();
	}

	public function test_ea1_environment_tab_renders_read_only_identity_and_portable_policy(): void {
		$this->set_environment( 'staging' );
		$_GET['page'] = Safeguards::SLUG;
		$_GET['tab']  = 'environment';

		ob_start();
		( new Safeguards() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'WordPress Environment', $html );
		self::assertStringContainsString( 'Staging', $html );
		self::assertStringContainsString( 'Environment Governance', $html );
		self::assertStringContainsString( 'Protect search indexing on non-production environments', $html );
		self::assertStringContainsString( 'A staging environment can still use Public Access Mode.', $html );
		self::assertStringContainsString( 'It is not access protection.', $html );
		self::assertStringContainsString( 'admin-post.php', $html );
		self::assertStringContainsString( 'name="action" value="' . EnvironmentAdmin::SAVE_ACTION . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_ea2_admin_transport_uses_canonical_authority_nonce_and_settings_boundary(): void {
		remove_action( 'admin_post_' . EnvironmentAdmin::SAVE_ACTION, [ EnvironmentAdmin::class, 'save' ] );
		EnvironmentAdmin::boot();

		self::assertNotFalse(
			has_action( 'admin_post_' . EnvironmentAdmin::SAVE_ACTION, [ EnvironmentAdmin::class, 'save' ] )
		);

		$file = ( new ReflectionClass( EnvironmentAdmin::class ) )->getFileName();
		self::assertIsString( $file );
		$source = (string) file_get_contents( $file );

		self::assertStringContainsString( "current_user_can( 'manage_options' )", $source );
		self::assertStringContainsString( 'check_admin_referer( self::NONCE_ACTION );', $source );
		self::assertStringContainsString( 'Settings::set_key(', $source );
		self::assertStringContainsString( 'Governance::POLICY_KEY', $source );
		self::assertStringNotContainsString( 'update_option(', $source );
	}

	public function test_ea3_indicator_is_admin_only_non_production_and_status_only(): void {
		$this->set_environment( 'staging' );
		set_current_screen( 'dashboard' );

		$bar = new WP_Admin_Bar();
		EnvironmentAdmin::admin_bar_notice( $bar );
		$node = $bar->get_node( 'cb-core-environment-notice' );

		self::assertNotNull( $node );
		self::assertSame( 'Environment: Staging', wp_strip_all_tags( (string) $node->title ) );
		self::assertStringContainsString( 'tab=environment', (string) $node->href );

		$this->set_environment( 'production' );
		$production_bar = new WP_Admin_Bar();
		EnvironmentAdmin::admin_bar_notice( $production_bar );
		self::assertNull( $production_bar->get_node( 'cb-core-environment-notice' ) );
	}

	public function test_ea4_environment_tab_route_and_assets_are_scoped_without_new_script_module(): void {
		$normalize = new ReflectionMethod( ScreenContext::class, 'normalize_tab' );
		$normalize->setAccessible( true );
		self::assertSame(
			'environment',
			$normalize->invoke( null, Safeguards::SLUG, 'environment' )
		);
		self::assertSame(
			'two-factor',
			$normalize->invoke( null, Safeguards::SLUG, 'two-factor' )
		);

		$requirements = new ReflectionMethod( ScreenAssetRegistry::class, 'safeguards_requirements' );
		$requirements->setAccessible( true );
		$assets = $requirements->invoke( null, 'environment' );

		self::assertContains( 'page.security', $assets );
		self::assertContains( 'component.nav-tabs', $assets );
		self::assertContains( 'component.panels', $assets );

		$two_factor_assets = $requirements->invoke( null, 'two-factor' );
		self::assertContains( 'component.panels', $two_factor_assets );
		self::assertContains( 'component.radio-card', $two_factor_assets );
		self::assertContains( 'foundation.modal', $two_factor_assets );
		self::assertContains( 'foundation.toast', $two_factor_assets );
		self::assertContains( 'module.two-factor-policy', $two_factor_assets );

		self::assertSame( [], array_values( array_filter(
			$assets,
			static fn( string $asset ): bool => str_starts_with( $asset, 'module.' ) || str_starts_with( $asset, 'foundation.' )
		) ) );
	}

	private function set_environment( string $type ): void {
		EnvironmentTypeTestShim::set( $type );
	}
}
