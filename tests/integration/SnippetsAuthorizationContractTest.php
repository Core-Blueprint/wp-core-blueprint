<?php
declare(strict_types=1);

use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Snippets\Authorization;

final class CB_Base_Snippets_Authorization_Contract_Test extends WP_UnitTestCase {

	private const LIMITED_ROLE = 'cb_snippets_limited_fixture';

	/** @var int[] */
	private array $user_ids = [];

	public function tear_down(): void {
		wp_set_current_user( 0 );

		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];

		if ( null !== get_role( self::LIMITED_ROLE ) ) {
			remove_role( self::LIMITED_ROLE );
		}

		parent::tear_down();
	}

	public function test_approved_cb_operator_can_mutate_without_native_plugin_install_authority(): void {
		if ( null === get_role( Roles::OPERATOR_ROLE ) ) {
			Roles::ensure_operator_role();
		}

		$user = $this->approved_user( Roles::OPERATOR_ROLE );

		self::assertTrue( current_user_can( 'cb_manage_snippets' ) );
		self::assertFalse( current_user_can( 'install_plugins' ) );
		self::assertFalse( current_user_can( 'unfiltered_html' ) );
		self::assertTrue( Authorization::can_access() );
		self::assertTrue( Authorization::has_code_authority() );

		if ( Authorization::file_modifications_allowed() ) {
			self::assertTrue( Authorization::can_mutate() );
		}
	}

	public function test_administrator_with_explicit_snippets_cap_uses_native_wordpress_code_authority(): void {
		$user = $this->approved_user( 'administrator', [ 'cb_manage_snippets' ] );

		self::assertTrue( current_user_can( 'cb_manage_snippets' ) );
		self::assertTrue( current_user_can( 'install_plugins' ) );
		self::assertTrue( current_user_can( 'unfiltered_html' ) );
		self::assertTrue( Authorization::can_access() );
		self::assertTrue( Authorization::has_code_authority() );

		if ( Authorization::file_modifications_allowed() ) {
			self::assertTrue( Authorization::can_mutate() );
		}
	}

	public function test_custom_role_with_only_snippets_cap_cannot_mutate_executable_code(): void {
		add_role(
			self::LIMITED_ROLE,
			'Snippets Limited Fixture',
			[
				'read'               => true,
				'cb_manage_snippets' => true,
			]
		);

		$user = $this->approved_user( self::LIMITED_ROLE );

		self::assertTrue( current_user_can( 'cb_manage_snippets' ) );
		self::assertFalse( current_user_can( 'install_plugins' ) );
		self::assertFalse( current_user_can( 'unfiltered_html' ) );
		self::assertTrue( Authorization::can_access() );
		self::assertFalse( Authorization::has_code_authority() );
		self::assertFalse( Authorization::can_mutate() );
	}

	public function test_server_and_ui_consume_the_canonical_authorization_policy(): void {
		$actions   = file_get_contents( CB_CORE_DIR . 'src/Snippets/Admin/Actions.php' );
		$page      = file_get_contents( CB_CORE_DIR . 'src/Snippets/Admin/Page.php' );
		$bootstrap = file_get_contents( CB_CORE_DIR . 'src/Snippets/Bootstrap.php' );

		self::assertIsString( $actions );
		self::assertIsString( $page );
		self::assertIsString( $bootstrap );

		self::assertStringContainsString( 'Authorization::can_access()', $actions );
		self::assertStringContainsString( 'Authorization::has_code_authority()', $actions );
		self::assertStringContainsString( 'Authorization::file_modifications_allowed()', $actions );
		self::assertStringContainsString( 'Authorization::can_mutate()', $page );
		self::assertStringContainsString( 'Authorization::can_mutate()', $bootstrap );
	}

	/**
	 * @param string[] $direct_caps
	 */
	private function approved_user( string $role, array $direct_caps = [] ): \WP_User {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		$this->user_ids[] = $user_id;

		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );

		foreach ( $direct_caps as $cap ) {
			$user->add_cap( $cap );
		}

		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, $user_id, 'test' ) );

		wp_set_current_user( $user_id );
		$current = wp_get_current_user();
		self::assertSame( $user_id, (int) $current->ID );

		return $current;
	}
}
