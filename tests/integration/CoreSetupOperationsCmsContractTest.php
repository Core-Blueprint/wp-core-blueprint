<?php
declare(strict_types=1);

use CoreBlueprint\Core\AdminColumns\PolicyRepository as AdminColumnsPolicy;
use CoreBlueprint\Core\AdminNavigation\Policy as AdminNavigationPolicy;
use CoreBlueprint\Core\Mail\Settings as MailSettings;
use CoreBlueprint\Core\Notes\State as NotesState;
use CoreBlueprint\Core\Reports\State as ReportsState;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Registry;
use CoreBlueprint\Core\Setup\ReviewRepository;
use CoreBlueprint\Core\Setup\StatusResolver;

final class CB_Base_Core_Setup_Operations_Cms_Contract_Test extends WP_UnitTestCase {

	private array $saved_options = [];

	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_cache();

		foreach ( [
			CB_CORE_SETTINGS,
			ReviewRepository::OPTION,
			MailSettings::OPTION,
			'active_plugins',
			'admin_email',
			AdminNavigationPolicy::OPTION,
			AdminColumnsPolicy::OPTION,
			'cb_core_content_models_enabled',
			'cb_core_user_roles_enabled',
			'cb_core_media_replace_enabled',
			'cb_core_package_download_enabled',
			'cb_core_media_formats',
			'cb_core_snippets_settings',
		] as $option ) {
			$this->saved_options[ $option ] = get_option( $option, '__cb_missing__' );
		}

		delete_option( ReviewRepository::OPTION );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->saved_options as $option => $value ) {
			$this->restore_option( (string) $option, $value );
		}
		$this->reset_settings_cache();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_oc1_registry_exposes_the_complete_30_check_v1_baseline(): void {
		self::assertSame(
			[
				'environment-identity',
				'environment-indexing-protection',
				'access-mode',
				'privileged-access-protection',
				'privileged-access-review',
				'two-factor-readiness',
				'failsafe-readiness',
				'core-shield',
				'login-shield',
				'core-scanner-policy',
				'core-scanner-readiness',
				'operational-logs',
				'notifications-policy',
				'operational-tools',
				'mail-delivery-strategy',
				'mail-delivery-readiness',
				'mail-designer',
				'privacy-ip-handling',
				'audit-retention',
				'audit-verbosity',
				'content-models',
				'snippets',
				'user-roles',
				'media-replace',
				'media-formats',
				'package-downloads',
				'admin-navigation',
				'admin-columns',
				'admin-notices',
				'routing-urls',
			],
			array_keys( Registry::all() )
		);

		$sections = Registry::sections();
		self::assertSame(
			[
				'environment-availability',
				'administrator-recovery',
				'safeguards',
				'operations',
				'mail',
				'privacy-governance',
				'cms-tools',
			],
			array_keys( $sections )
		);
		self::assertCount( 3, $sections['environment-availability'] );
		self::assertCount( 4, $sections['administrator-recovery'] );
		self::assertCount( 4, $sections['safeguards'] );
		self::assertCount( 3, $sections['operations'] );
		self::assertCount( 3, $sections['mail'] );
		self::assertCount( 3, $sections['privacy-governance'] );
		self::assertCount( 10, $sections['cms-tools'] );
		self::assertCount( 30, Registry::all() );
	}

	public function test_oc2_mail_strategy_stays_a_decision_while_readiness_owns_transport_failures(): void {
		$mail = MailSettings::defaults();
		$mail['delivery_enabled'] = true;
		$mail['designer_enabled'] = false;
		$mail['provider'] = 'smtp';
		$mail['from_email'] = 'sender@example.test';
		$mail['from_name'] = 'Private Sender';
		$mail['smtp_host'] = 'smtp.private.example.test';
		$mail['smtp_auth'] = true;
		$mail['smtp_username'] = '';
		$mail['smtp_password'] = 'raw-secret-password';
		update_option( MailSettings::OPTION, $mail, false );
		update_option( 'active_plugins', [], false );

		$strategy = Registry::get( 'mail-delivery-strategy' );
		$readiness = Registry::get( 'mail-delivery-readiness' );
		self::assertInstanceOf( CheckInterface::class, $strategy );
		self::assertInstanceOf( CheckInterface::class, $readiness );

		$strategy_evidence = $strategy->evidence();
		$readiness_evidence = $readiness->evidence();

		self::assertSame( Evidence::HEALTH_OK, $strategy_evidence->health() );
		self::assertSame( Evidence::HEALTH_ATTENTION, $readiness_evidence->health() );

		$serialized = wp_json_encode( [
			'strategy' => $strategy_evidence->fingerprint_data(),
			'readiness' => $readiness_evidence->fingerprint_data(),
		] );
		self::assertIsString( $serialized );
		foreach ( [
			'sender@example.test',
			'Private Sender',
			'smtp.private.example.test',
			'raw-secret-password',
		] as $secret ) {
			self::assertStringNotContainsString( $secret, $serialized );
		}
	}

	public function test_oc3_disabled_mail_delivery_readiness_can_be_not_applicable(): void {
		$mail = MailSettings::defaults();
		$mail['delivery_enabled'] = false;
		update_option( MailSettings::OPTION, $mail, false );

		$check = Registry::get( 'mail-delivery-readiness' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$evidence = $check->evidence();

		self::assertSame( Evidence::HEALTH_OK, $evidence->health() );
		self::assertTrue( $check->allows_not_applicable( $evidence ) );
		self::assertTrue( ReviewRepository::mark_not_applicable( $check->id(), $evidence, 'Delivery handled elsewhere.', 21 ) );
		self::assertSame( StatusResolver::NOT_APPLICABLE, StatusResolver::resolve( $check, $evidence ) );
	}

	public function test_oc4_notification_routing_fingerprint_detects_drift_without_persisting_addresses(): void {
		$settings = Settings::get();
		$audit = is_array( $settings['audit'] ?? null ) ? $settings['audit'] : [];
		$audit['email_recipient'] = 'ops@example.test';
		$audit['email_alerts']['critical'] = true;
		Settings::set_key( 'audit', $audit, 'test:core-setup' );

		$check = Registry::get( 'notifications-policy' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$before = $check->evidence();
		self::assertSame( Evidence::HEALTH_OK, $before->health() );

		$serialized = wp_json_encode( $before->fingerprint_data() );
		self::assertIsString( $serialized );
		self::assertStringNotContainsString( 'ops@example.test', $serialized );

		self::assertTrue( ReviewRepository::mark_reviewed( $check->id(), $before, 22 ) );
		self::assertSame( StatusResolver::CONFIGURED, StatusResolver::resolve( $check, $before ) );

		$audit['email_recipient'] = 'security@example.test';
		Settings::set_key( 'audit', $audit, 'test:core-setup' );

		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
		self::assertStringNotContainsString(
			'security@example.test',
			(string) wp_json_encode( $check->evidence()->fingerprint_data() )
		);
	}

	public function test_oc5_optional_operational_tools_can_be_explicitly_not_applicable_when_both_are_disabled(): void {
		NotesState::set_enabled( false, 'test:core-setup' );
		ReportsState::set_enabled( false, 'test:core-setup' );

		$check = Registry::get( 'operational-tools' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$evidence = $check->evidence();

		self::assertSame( Evidence::HEALTH_OK, $evidence->health() );
		self::assertTrue( $check->allows_not_applicable( $evidence ) );
		self::assertTrue( ReviewRepository::mark_not_applicable( $check->id(), $evidence, 'No operational tools needed.', 23 ) );
		self::assertSame( StatusResolver::NOT_APPLICABLE, StatusResolver::resolve( $check, $evidence ) );
	}

	public function test_oc6_content_models_activation_drift_invalidates_a_not_applicable_review(): void {
		update_option( 'cb_core_content_models_enabled', '0', false );

		$check = Registry::get( 'content-models' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$disabled = $check->evidence();

		self::assertSame( Evidence::HEALTH_OK, $disabled->health() );
		self::assertTrue( $check->allows_not_applicable( $disabled ) );
		self::assertTrue( ReviewRepository::mark_not_applicable( $check->id(), $disabled, 'No custom schema needed.', 24 ) );
		self::assertSame( StatusResolver::NOT_APPLICABLE, StatusResolver::resolve( $check, $disabled ) );

		update_option( 'cb_core_content_models_enabled', '1', false );
		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
	}

	public function test_oc7_simple_cms_module_checks_use_canonical_activation_capabilities_and_disabled_na_semantics(): void {
		update_option( 'cb_core_user_roles_enabled', '0', false );
		update_option( 'cb_core_media_replace_enabled', '0', false );
		update_option( 'cb_core_package_download_enabled', '0', false );

		$expected = [
			'user-roles'        => 'cb_manage_roles',
			'media-replace'     => 'cb_manage_media_replace',
			'package-downloads' => 'manage_options',
		];

		foreach ( $expected as $id => $capability ) {
			$check = Registry::get( $id );
			self::assertInstanceOf( CheckInterface::class, $check );
			self::assertSame( $capability, $check->capability(), $id );

			$evidence = $check->evidence();
			self::assertSame( Evidence::HEALTH_OK, $evidence->health(), $id );
			self::assertTrue( $check->allows_not_applicable( $evidence ), $id );
		}
	}

	public function test_oc8_admin_navigation_policy_change_invalidates_a_default_not_applicable_review(): void {
		delete_option( AdminNavigationPolicy::OPTION );

		$check = Registry::get( 'admin-navigation' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$default = $check->evidence();

		self::assertTrue( $check->allows_not_applicable( $default ) );
		self::assertTrue( ReviewRepository::mark_not_applicable( $check->id(), $default, 'Use the WordPress default navigation.', 25 ) );
		self::assertSame( StatusResolver::NOT_APPLICABLE, StatusResolver::resolve( $check, $default ) );

		self::assertTrue( AdminNavigationPolicy::replace(
			[
				'version' => AdminNavigationPolicy::VERSION,
				'menu' => [
					'order' => [ 'index.php' ],
					'hidden' => [],
				],
				'toolbar' => [
					'hidden' => [],
					'renamed' => [],
				],
			],
			'test:core-setup'
		) );

		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
	}

	public function test_oc9_admin_columns_policy_change_invalidates_a_default_not_applicable_review(): void {
		delete_option( AdminColumnsPolicy::OPTION );

		$check = Registry::get( 'admin-columns' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$default = $check->evidence();

		self::assertTrue( $check->allows_not_applicable( $default ) );
		self::assertTrue( ReviewRepository::mark_not_applicable( $check->id(), $default, 'Use native columns.', 26 ) );
		self::assertSame( StatusResolver::NOT_APPLICABLE, StatusResolver::resolve( $check, $default ) );

		self::assertTrue( AdminColumnsPolicy::replace(
			[
				'schema_version' => AdminColumnsPolicy::SCHEMA_VERSION,
				'screens' => [
					'edit-post' => [
						'order'      => [ 'cb', 'title' ],
						'hidden'     => [],
						'taxonomies' => [],
						'meta'       => [],
					],
				],
			],
			'test:core-setup'
		) );

		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
	}

	public function test_oc10_disabled_media_formats_and_snippets_are_valid_optional_choices(): void {
		update_option( 'cb_core_media_formats', [ 'enabled' => false ], false );
		update_option( 'cb_core_snippets_settings', [ 'enabled' => false ], true );

		foreach ( [ 'media-formats', 'snippets' ] as $id ) {
			$check = Registry::get( $id );
			self::assertInstanceOf( CheckInterface::class, $check );
			$evidence = $check->evidence();

			self::assertSame( Evidence::HEALTH_OK, $evidence->health(), $id );
			self::assertTrue( $check->allows_not_applicable( $evidence ), $id );
		}
	}

	private function reset_settings_cache(): void {
		$reflection = new ReflectionClass( Settings::class );
		$cached = $reflection->getProperty( 'cached' );
		$cached->setValue( null, null );
	}

	private function restore_option( string $name, mixed $value ): void {
		if ( '__cb_missing__' === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
