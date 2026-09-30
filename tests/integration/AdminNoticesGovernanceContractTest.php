<?php
declare(strict_types=1);

use CB\Core\AdminNotices\Capabilities;
use CB\Core\AdminNotices\Discovery;
use CB\Core\AdminNotices\Policy;
use CB\Core\AdminNotices\Runtime;
use CB\Core\AdminNotices\SourceResolver;
use CB\Core\AdminNotices\Visibility;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessPolicy;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\Roles;

final class CB_Base_Admin_Notices_Governance_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_policy;
	private mixed $saved_role_schema;
	private mixed $saved_role_drift;
	private bool $operator_had_cap = false;

	/** @var list<array{hook:string,callback:callable,priority:int}> */
	private array $added_callbacks = [];

	public function set_up(): void {
		parent::set_up();

		$this->saved_policy      = get_option( Policy::OPTION, '__cb_notices_missing__' );
		$this->saved_role_schema = get_option( 'cb_core_role_policy_schema_version', '__cb_notices_missing__' );
		$this->saved_role_drift  = get_option( 'cb_core_role_policy_drift', '__cb_notices_missing__' );

		delete_option( Policy::OPTION );
		SourceResolver::_set_resolver_for_testing( null );

		Roles::ensure_operator_role();
		$operator = get_role( Roles::OPERATOR_ROLE );
		self::assertNotNull( $operator );
		$this->operator_had_cap = $operator->has_cap( Capabilities::MANAGE );

		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->added_callbacks as $entry ) {
			remove_action( $entry['hook'], $entry['callback'], $entry['priority'] );
		}
		$this->added_callbacks = [];
		SourceResolver::_set_resolver_for_testing( null );

		$this->restore_option( Policy::OPTION, $this->saved_policy );
		$this->restore_option( 'cb_core_role_policy_schema_version', $this->saved_role_schema );
		$this->restore_option( 'cb_core_role_policy_drift', $this->saved_role_drift );

		$operator = get_role( Roles::OPERATOR_ROLE );
		if ( $operator ) {
			PrivilegedAccessGuard::trusted_mutation( function () use ( $operator ): void {
				if ( $this->operator_had_cap ) {
					$operator->add_cap( Capabilities::MANAGE );
				} else {
					$operator->remove_cap( Capabilities::MANAGE );
				}
			} );
		}

		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_an1_policy_is_bounded_and_corrupt_storage_fails_open(): void {
		self::assertSame(
			[ 'version', 'rules' ],
			array_keys( Policy::defaults() )
		);
		self::assertSame( [], Policy::defaults()['rules'] );

		update_option( Policy::OPTION, [ 'version' => 999, 'rules' => [] ], false );
		self::assertSame( Policy::defaults(), Policy::get() );
		self::assertFalse( Policy::has_restrictions() );

		$invalid = Policy::defaults();
		$invalid['rules'][] = [
			'source'     => 'unknown:not-manageable',
			'visibility' => Policy::OPERATORS_ONLY,
			'audience'   => [ 'roles' => [], 'capabilities' => [] ],
		];

		$this->expectException( InvalidArgumentException::class );
		Policy::normalize( $invalid );
	}

	public function test_an1b_governance_fails_open_until_an_operator_is_effectively_authorized(): void {
		$operator_id = self::factory()->user->create( [ 'role' => Roles::OPERATOR_ROLE ] );
		$operator = get_userdata( $operator_id );
		self::assertInstanceOf( WP_User::class, $operator );

		$policy = Policy::defaults();
		$policy['rules'][] = [
			'source'     => 'plugin:example-plugin',
			'visibility' => Policy::OPERATORS_ONLY,
			'audience'   => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy, 'test-fail-open' ) );

		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		if ( user_can( $operator, Capabilities::MANAGE ) ) {
			self::markTestSkipped( 'Current Privileged Access mode leaves the unapproved operator effective; fail-open quarantine branch is not active.' );
		}

		self::assertFalse( Visibility::governance_available() );
		self::assertTrue( Visibility::allows_current_user( 'plugin:example-plugin' ) );

		self::assertTrue( PrivilegedAccessRegistry::approve( $operator, 0, 'admin_notices_fail_open_fixture' ) );
		self::assertTrue( Visibility::governance_available() );
		self::assertFalse( Visibility::allows_current_user( 'plugin:example-plugin' ) );
	}

	public function test_an2_operator_always_sees_restricted_sources_and_editor_does_not(): void {
		$operator_id = $this->approved_operator();
		$policy = Policy::defaults();
		$policy['rules'][] = [
			'source'     => 'plugin:example-plugin',
			'visibility' => Policy::OPERATORS_ONLY,
			'audience'   => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy, 'test' ) );

		wp_set_current_user( $operator_id );
		self::assertTrue( current_user_can( Capabilities::MANAGE ) );
		self::assertTrue( Visibility::allows_current_user( 'plugin:example-plugin' ) );

		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		self::assertFalse( current_user_can( Capabilities::MANAGE ) );
		self::assertFalse( Visibility::allows_current_user( 'plugin:example-plugin' ) );
	}

	public function test_an3_selected_audience_adds_client_visibility_without_removing_operator_visibility(): void {
		$this->approved_operator();

		$policy = Policy::defaults();
		$policy['rules'][] = [
			'source'     => 'plugin:example-plugin',
			'visibility' => Policy::SELECTED,
			'audience'   => [
				'roles'        => [ 'editor' ],
				'capabilities' => [ 'edit_posts' ],
			],
		];
		self::assertTrue( Policy::replace( $policy, 'test' ) );

		$operator_id = $this->approved_operator();
		wp_set_current_user( $operator_id );
		self::assertTrue( Visibility::allows_current_user( 'plugin:example-plugin' ) );

		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		self::assertTrue( Visibility::allows_current_user( 'plugin:example-plugin' ) );

		$capability_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$capability_user = get_userdata( $capability_user_id );
		self::assertInstanceOf( WP_User::class, $capability_user );
		$capability_user->add_cap( 'cb_notice_fixture_cap' );

		$policy['rules'][0]['audience'] = [
			'roles'        => [ 'editor' ],
			'capabilities' => [ 'cb_notice_fixture_cap' ],
		];
		self::assertTrue( Policy::replace( $policy, 'test-or-semantics' ) );

		wp_set_current_user( $editor_id );
		self::assertTrue(
			Visibility::allows_current_user( 'plugin:example-plugin' ),
			'A selected role must be sufficient even when the selected capability is absent.'
		);

		wp_set_current_user( $capability_user_id );
		self::assertTrue(
			Visibility::allows_current_user( 'plugin:example-plugin' ),
			'A selected capability must be sufficient even when the selected role is absent.'
		);

		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );
		self::assertFalse( Visibility::allows_current_user( 'plugin:example-plugin' ) );
	}

	public function test_an4_runtime_removes_only_managed_restricted_callbacks_and_keeps_unknown_sources(): void {
		$this->approved_operator();

		$policy = Policy::defaults();
		$policy['rules'][] = [
			'source'     => 'plugin:fixture',
			'visibility' => Policy::OPERATORS_ONLY,
			'audience'   => [ 'roles' => [], 'capabilities' => [] ],
		];
		self::assertTrue( Policy::replace( $policy, 'test' ) );

		$managed = static function (): void {};
		$unknown = static function (): void {};
		$this->add_notice_callback( 'admin_notices', $managed, 40 );
		$this->add_notice_callback( 'admin_notices', $unknown, 41 );

		SourceResolver::_set_resolver_for_testing(
			static function ( mixed $callback ) use ( $managed, $unknown ): ?array {
				if ( $callback === $managed ) {
					return [
						'id'         => 'plugin:fixture',
						'label'      => 'Fixture',
						'kind'       => SourceResolver::KIND_PLUGIN,
						'manageable' => true,
						'protected'  => false,
					];
				}
				if ( $callback === $unknown ) {
					return [
						'id'         => 'unknown:fixture',
						'label'      => 'Unknown source',
						'kind'       => SourceResolver::KIND_UNKNOWN,
						'manageable' => false,
						'protected'  => true,
					];
				}
				return null;
			}
		);

		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$removed = Runtime::apply( 'admin_notices' );

		self::assertSame( [ 'plugin:fixture' ], $removed );
		self::assertFalse( has_action( 'admin_notices', $managed ) );
		self::assertSame( 41, has_action( 'admin_notices', $unknown ) );
	}

	public function test_an5_discovery_contains_metadata_only_and_never_notice_markup(): void {
		$callback = static function (): void {
			echo '<div class="notice notice-error">SECRET_NOTICE_HTML</div>';
		};
		$this->add_notice_callback( 'admin_notices', $callback, 55 );

		SourceResolver::_set_resolver_for_testing(
			static function ( mixed $candidate ) use ( $callback ): ?array {
				if ( $candidate !== $callback ) {
					return null;
				}
				return [
					'id'         => 'plugin:fixture',
					'label'      => 'Fixture',
					'kind'       => SourceResolver::KIND_PLUGIN,
					'manageable' => true,
					'protected'  => false,
				];
			}
		);

		$sources = Discovery::sources();
		self::assertArrayHasKey( 'plugin:fixture', $sources );
		self::assertSame(
			[ 'id', 'label', 'kind', 'manageable', 'protected', 'hooks', 'callback_count' ],
			array_keys( $sources['plugin:fixture'] )
		);
		self::assertStringNotContainsString( 'SECRET_NOTICE_HTML', (string) wp_json_encode( $sources ) );
	}

	public function test_an6_wordpress_core_callbacks_are_stable_manageable_protected_sources(): void {
		if ( ! function_exists( 'update_nag' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$source = SourceResolver::resolve( 'update_nag' );

		self::assertSame( 'wordpress:core', $source['id'] );
		self::assertSame( SourceResolver::KIND_WORDPRESS, $source['kind'] );
		self::assertTrue( $source['manageable'] );
		self::assertTrue( $source['protected'] );
	}

	public function test_an7_role_policy_schema_2_adds_notice_capability_without_invalidating_operator_approval(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
			$user->add_role( Roles::OPERATOR_ROLE );
		} );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'admin_notices_schema_fixture' ) );
		self::assertTrue( PrivilegedAccessRegistry::is_approved( $user ) );

		$operator = get_role( Roles::OPERATOR_ROLE );
		self::assertNotNull( $operator );
		PrivilegedAccessGuard::trusted_mutation( static function () use ( $operator ): void {
			$operator->remove_cap( Capabilities::MANAGE );
		} );
		update_option( 'cb_core_role_policy_schema_version', 1, false );

		$before = PrivilegedAccessPolicy::fingerprint( get_userdata( $user_id ) );
		RolePolicySchema::maybe_migrate();

		$operator = get_role( Roles::OPERATOR_ROLE );
		self::assertNotNull( $operator );
		self::assertTrue( $operator->has_cap( Capabilities::MANAGE ) );
		self::assertSame( 2, RolePolicySchema::stored_schema() );

		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertSame( $before, PrivilegedAccessPolicy::fingerprint( $user ) );
		self::assertTrue( PrivilegedAccessRegistry::is_approved( $user ) );
	}

	public function test_an8_source_contract_contains_no_dom_css_or_notice_html_parsing(): void {
		$root = CB_CORE_DIR . 'src/AdminNotices/';
		$source = '';
		foreach ( [ 'SourceResolver.php', 'Discovery.php', 'Policy.php', 'Visibility.php', 'Runtime.php', 'Bootstrap.php' ] as $file ) {
			$contents = file_get_contents( $root . $file );
			self::assertIsString( $contents );
			$source .= "\n" . $contents;
		}

		foreach ( [ 'querySelector', 'jQuery', 'display:none', 'display: none', 'wp_admin_notice_markup', 'ob_start(', 'preg_match( $markup' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
		self::assertStringContainsString( 'remove_action( $hook, $entry[\'callback\']', $source );
		self::assertStringContainsString( 'add_action( $hook, [ __CLASS__, \'govern_current_hook\' ], PHP_INT_MIN )', $source );
	}

	private function approved_operator(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
			$user->add_role( Roles::OPERATOR_ROLE );
		} );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'admin_notices_fixture' ) );
		return $user_id;
	}

	private function add_notice_callback( string $hook, callable $callback, int $priority ): void {
		add_action( $hook, $callback, $priority );
		$this->added_callbacks[] = [
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
		];
	}

	private function restore_option( string $name, mixed $value ): void {
		if ( '__cb_notices_missing__' === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
