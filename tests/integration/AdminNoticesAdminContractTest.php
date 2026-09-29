<?php
declare(strict_types=1);

use CB\Core\AdminNotices\Admin;
use CB\Core\AdminNotices\Capabilities;
use CB\Core\AdminNotices\Policy;
use CB\Core\AdminNotices\SourceLedger;
use CB\Core\AdminNotices\SourceResolver;
use CB\Core\AdminNotices\Visibility;
use CB\Core\Admin\Pages\Preferences;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\Roles;

final class CB_Base_Admin_Notices_Admin_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_policy;
	private mixed $saved_ledger;

	public function set_up(): void {
		parent::set_up();
		$this->saved_policy = get_option( Policy::OPTION, '__cb_notices_missing__' );
		$this->saved_ledger = get_option( SourceLedger::OPTION, '__cb_notices_missing__' );
		delete_option( Policy::OPTION );
		delete_option( SourceLedger::OPTION );
		PrivilegedAccessGuard::trusted_mutation( static function (): void {
			Roles::ensure_operator_role();
		} );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->restore_option( Policy::OPTION, $this->saved_policy );
		$this->restore_option( SourceLedger::OPTION, $this->saved_ledger );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_ana1_pure_operator_has_notice_management_authority_without_manage_options(): void {
		$user_id = self::factory()->user->create( [ 'role' => Roles::OPERATOR_ROLE ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'admin_notices_admin_fixture' ) );
		self::assertTrue( PrivilegedAccessRegistry::is_approved( $user ) );
		wp_set_current_user( $user_id );

		self::assertTrue( current_user_can( Capabilities::MANAGE ) );
		self::assertTrue( Admin::can_manage() );
		self::assertFalse( current_user_can( 'manage_options' ) );
		self::assertSame( Capabilities::MANAGE, ( new Preferences() )->capability() );
	}

	public function test_ana2_protected_sources_cannot_be_restricted_and_visibility_fails_open(): void {
		$policy = Policy::defaults();
		$policy['rules'][] = [
			'source'     => 'wordpress:core',
			'visibility' => Policy::OPERATORS_ONLY,
			'audience'   => [ 'roles' => [], 'capabilities' => [] ],
		];

		try {
			Policy::normalize( $policy );
			self::fail( 'Protected WordPress core source was accepted as restricted.' );
		} catch ( InvalidArgumentException ) {
			self::assertTrue( Visibility::allows_current_user( 'wordpress:core' ) );
		}

		$policy['rules'][0]['source'] = 'plugin:core-blueprint';
		$this->expectException( InvalidArgumentException::class );
		Policy::normalize( $policy );
	}

	public function test_ana3_selected_visibility_requires_at_least_one_role_or_capability(): void {
		$policy = Policy::defaults();
		$policy['rules'][] = [
			'source'     => 'plugin:example-plugin',
			'visibility' => Policy::SELECTED,
			'audience'   => [ 'roles' => [], 'capabilities' => [] ],
		];

		$this->expectException( InvalidArgumentException::class );
		Policy::normalize( $policy );
	}

	public function test_ana4_editor_state_combines_observed_sources_and_restricted_policy(): void {
		SourceLedger::observe_hook(
			'admin_notices',
			[
				[
					'priority' => 10,
					'callback' => static function (): void {},
					'source'   => [
						'id'         => 'plugin:example-plugin',
						'label'      => 'Example Plugin',
						'kind'       => SourceResolver::KIND_PLUGIN,
						'manageable' => true,
						'protected'  => false,
					],
				],
				[
					'priority' => 20,
					'callback' => static function (): void {},
					'source'   => [
						'id'         => 'wordpress:core',
						'label'      => 'WordPress',
						'kind'       => SourceResolver::KIND_WORDPRESS,
						'manageable' => true,
						'protected'  => true,
					],
				],
			],
			1_700_000_000
		);

		$policy = Policy::normalize( [
			'version' => Policy::VERSION,
			'rules'   => [
				[
					'source'     => 'plugin:example-plugin',
					'visibility' => Policy::OPERATORS_ONLY,
					'audience'   => [ 'roles' => [], 'capabilities' => [] ],
				],
			],
		] );
		update_option( Policy::OPTION, $policy, false );

		$state = Admin::editor_state();

		self::assertSame( 2, $state['summary']['sources'] );
		self::assertSame( 1, $state['summary']['restricted'] );
		self::assertSame( 1, $state['summary']['protected'] );
		self::assertSame( 0, $state['summary']['unknown'] );

		$rows = array_column( $state['sources'], null, 'id' );
		self::assertSame( Policy::OPERATORS_ONLY, $rows['plugin:example-plugin']['rule']['visibility'] );
		self::assertSame( Policy::EVERYONE, $rows['wordpress:core']['rule']['visibility'] );
	}

	public function test_ana5_preferences_editor_is_foundation_first_without_custom_notice_css_or_dom_hiding(): void {
		$template = file_get_contents( CB_CORE_DIR . 'templates/preferences-admin-notices.php' );
		$script   = file_get_contents( CB_CORE_DIR . 'assets/js/features/admin-notices.js' );
		$screen   = file_get_contents( CB_CORE_DIR . 'src/Admin/ScreenAssetRegistry.php' );

		self::assertIsString( $template );
		self::assertIsString( $script );
		self::assertIsString( $screen );

		foreach ( [
			'cb-core-status-strip',
			'cb-core-status-card',
			'cb-core-disclosure',
			'cb-core-field',
			'cb-core-state-badge',
			'data-cb-core-object-picker-input',
		] as $foundation_contract ) {
			self::assertStringContainsString( $foundation_contract, $template . $script );
		}

		self::assertStringContainsString( "case 'admin-notices':", $screen );
		self::assertStringContainsString( "'foundation.object-picker'", $screen );
		self::assertStringContainsString( "'module.admin-notices'", $screen );

		foreach ( [ '<style', 'style=', 'querySelectorAll(\'.notice', 'display:none', 'display: none', 'jQuery(' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $template . "\n" . $script );
		}
		self::assertFileDoesNotExist( CB_CORE_DIR . 'assets/css/pages/admin-notices.css' );
	}

	private function restore_option( string $name, mixed $value ): void {
		if ( '__cb_notices_missing__' === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
