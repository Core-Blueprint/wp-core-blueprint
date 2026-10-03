<?php
declare(strict_types=1);

use CoreBlueprint\Core\Environment\EnvironmentTypeTestShim;
use CoreBlueprint\Core\Environment\Governance;
use CoreBlueprint\Core\Mail\Settings as MailSettings;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Registry;
use CoreBlueprint\Core\Setup\ReviewRepository;
use CoreBlueprint\Core\Setup\StatusResolver;

final class CB_Base_Core_Setup_Foundation_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_core_settings;
	private mixed $saved_setup_state;
	private mixed $saved_mail_settings;
	private mixed $saved_active_plugins;

	public function set_up(): void {
		parent::set_up();

		$this->saved_core_settings  = get_option( CB_CORE_SETTINGS, '__cb_missing__' );
		$this->saved_setup_state    = get_option( ReviewRepository::OPTION, '__cb_missing__' );
		$this->saved_mail_settings  = get_option( MailSettings::OPTION, '__cb_missing__' );
		$this->saved_active_plugins = get_option( 'active_plugins', '__cb_missing__' );

		delete_option( ReviewRepository::OPTION );
		EnvironmentTypeTestShim::reset();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->restore_option( CB_CORE_SETTINGS, $this->saved_core_settings );
		$this->restore_option( ReviewRepository::OPTION, $this->saved_setup_state );
		$this->restore_option( MailSettings::OPTION, $this->saved_mail_settings );
		$this->restore_option( 'active_plugins', $this->saved_active_plugins );
		EnvironmentTypeTestShim::reset();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_cs1_registry_preserves_the_three_phase_one_proof_checks(): void {
		$checks = Registry::all();
		self::assertArrayHasKey( 'environment-indexing-protection', $checks );
		self::assertArrayHasKey( 'login-shield', $checks );
		self::assertArrayHasKey( 'mail-delivery-strategy', $checks );

		$sections = Registry::sections();
		self::assertArrayHasKey( 'environment-availability', $sections );
		self::assertArrayHasKey( 'safeguards', $sections );
		self::assertArrayHasKey( 'mail', $sections );
	}

	public function test_cs2_matching_review_fingerprint_is_required_for_configured_state(): void {
		EnvironmentTypeTestShim::set( 'production' );
		Settings::set_key( Governance::POLICY_KEY, Governance::default_policy(), 'test:core-setup' );

		$check = Registry::get( 'environment-indexing-protection' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$evidence = $check->evidence();

		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check, $evidence ) );
		self::assertTrue( ReviewRepository::mark_reviewed( $check->id(), $evidence, 7 ) );
		self::assertSame( StatusResolver::CONFIGURED, StatusResolver::resolve( $check, $evidence ) );

		EnvironmentTypeTestShim::set( 'staging' );
		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
	}

	public function test_cs3_live_attention_overrides_matching_later_review_intent(): void {
		EnvironmentTypeTestShim::set( 'staging' );
		Settings::set_key(
			Governance::POLICY_KEY,
			[ Governance::PROTECT_SEARCH_INDEXING => false ],
			'test:core-setup'
		);

		$check = Registry::get( 'environment-indexing-protection' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$evidence = $check->evidence();
		self::assertSame( Evidence::HEALTH_ATTENTION, $evidence->health() );
		self::assertTrue( ReviewRepository::mark_later( $check->id(), $evidence, 'Review next maintenance window.', 8 ) );
		self::assertSame( StatusResolver::ATTENTION, StatusResolver::resolve( $check, $evidence ) );
	}

	public function test_cs4_not_applicable_is_valid_only_when_the_live_check_allows_it(): void {
		EnvironmentTypeTestShim::set( 'production' );
		$check = Registry::get( 'environment-indexing-protection' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$production = $check->evidence();
		self::assertTrue( ReviewRepository::mark_not_applicable( $check->id(), $production, 'Production site.', 9 ) );
		self::assertSame( StatusResolver::NOT_APPLICABLE, StatusResolver::resolve( $check, $production ) );

		EnvironmentTypeTestShim::set( 'staging' );
		Settings::set_key(
			Governance::POLICY_KEY,
			[ Governance::PROTECT_SEARCH_INDEXING => true ],
			'test:core-setup'
		);
		$staging = $check->evidence();
		self::assertTrue( ReviewRepository::mark_not_applicable( $check->id(), $staging, 'Invalid on staging.', 9 ) );
		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check, $staging ) );
	}

	public function test_cs5_visible_registry_respects_each_check_capability(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		self::assertSame( [], Registry::visible() );

		$administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin_user = get_userdata( $administrator );
		self::assertInstanceOf( WP_User::class, $admin_user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin_user, 0, 'core_setup_visibility_fixture' ) );
		wp_set_current_user( $administrator );
		$visible = Registry::visible();
		foreach ( Registry::all() as $id => $check ) {
			self::assertSame( current_user_can( $check->capability() ), isset( $visible[ $id ] ), $id );
		}
	}

	public function test_cs6_mail_evidence_contains_no_raw_transport_secret_values(): void {
		$mail = MailSettings::defaults();
		$mail['delivery_enabled'] = true;
		$mail['provider'] = 'smtp';
		$mail['smtp_host'] = 'smtp.example.test';
		$mail['smtp_username'] = 'operator@example.test';
		$mail['smtp_password'] = 'raw-secret-password';
		$mail['brevo_api_key'] = 'raw-secret-api-key';
		update_option( MailSettings::OPTION, $mail, false );
		update_option( 'active_plugins', [], false );

		$check = Registry::get( 'mail-delivery-strategy' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$serialized = wp_json_encode( $check->evidence()->fingerprint_data() );

		self::assertIsString( $serialized );
		self::assertStringNotContainsString( 'raw-secret-password', $serialized );
		self::assertStringNotContainsString( 'raw-secret-api-key', $serialized );
		self::assertStringNotContainsString( 'smtp.example.test', $serialized );
		self::assertStringNotContainsString( 'operator@example.test', $serialized );
	}

	public function test_cs7_malformed_review_storage_fails_closed_to_needs_review(): void {
		update_option(
			ReviewRepository::OPTION,
			[
				'schema_version' => 999,
				'checks' => [
					'environment-indexing-protection' => [
						'disposition' => ReviewRepository::REVIEWED,
						'fingerprint' => str_repeat( 'a', 64 ),
					],
				],
			],
			false
		);

		EnvironmentTypeTestShim::set( 'production' );
		$check = Registry::get( 'environment-indexing-protection' );
		self::assertInstanceOf( CheckInterface::class, $check );
		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
	}

	public function test_cs8_unavailable_evidence_can_never_resolve_as_configured(): void {
		$check = new class implements CheckInterface {
			public function id(): string { return 'test-unavailable'; }
			public function section(): string { return 'test'; }
			public function label(): string { return 'Unavailable fixture'; }
			public function kind(): string { return self::KIND_REQUIRED; }
			public function capability(): string { return 'manage_options'; }
			public function evidence(): Evidence { return Evidence::unavailable( 'test.unavailable' ); }
			public function configuration_url(): string { return ''; }
			public function allows_later(): bool { return true; }
			public function allows_not_applicable( Evidence $evidence ): bool { return false; }
		};
		$evidence = $check->evidence();
		self::assertTrue( ReviewRepository::mark_reviewed( $check->id(), $evidence, 10 ) );
		self::assertSame( StatusResolver::ATTENTION, StatusResolver::resolve( $check, $evidence ) );
	}

	private function restore_option( string $name, mixed $value ): void {
		if ( '__cb_missing__' === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
