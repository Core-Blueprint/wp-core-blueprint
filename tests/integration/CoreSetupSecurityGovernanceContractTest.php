<?php
declare(strict_types=1);

use CoreBlueprint\Core\Environment\EnvironmentTypeTestShim;
use CoreBlueprint\Core\Governance\RetentionPolicy;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Log\Verbosity;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Privacy\Anonymizer;
use CoreBlueprint\Core\Security\AccessMode;
use CoreBlueprint\Core\Security\AccessModeState;
use CoreBlueprint\Core\Security\TwoFactor\CredentialStore;
use CoreBlueprint\Core\Security\TwoFactor\Policy as TwoFactorPolicy;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Registry;
use CoreBlueprint\Core\Setup\ReviewRepository;
use CoreBlueprint\Core\Setup\StatusResolver;

final class CB_Base_Core_Setup_Security_Governance_Contract_Test extends WP_UnitTestCase {

	private array $saved_options = [];

	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_cache();

		foreach ( [
			CB_CORE_SETTINGS,
			ReviewRepository::OPTION,
			AccessMode::OPTION_KEY,
			AccessMode::CONFIG_OPTION_KEY,
			Anonymizer::OPTION_KEY,
			RetentionPolicy::OPTION_KEY,
			Verbosity::OPTION_KEY,
		] as $option ) {
			$this->saved_options[ $option ] = get_option( $option, '__cb_missing__' );
		}

		delete_option( ReviewRepository::OPTION );
		EnvironmentTypeTestShim::reset();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->saved_options as $option => $value ) {
			$this->restore_option( (string) $option, $value );
		}
		$this->reset_settings_cache();
		EnvironmentTypeTestShim::reset();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_sg1_registry_preserves_the_phase_two_security_and_governance_contract(): void {
		$checks = Registry::all();
		foreach ( [
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
			'mail-delivery-strategy',
			'privacy-ip-handling',
			'audit-retention',
			'audit-verbosity',
		] as $id ) {
			self::assertArrayHasKey( $id, $checks, $id );
		}

		$sections = Registry::sections();
		self::assertCount( 3, $sections['environment-availability'] );
		self::assertCount( 4, $sections['administrator-recovery'] );
		self::assertCount( 4, $sections['safeguards'] );
		self::assertGreaterThanOrEqual( 1, count( $sections['mail'] ) );
		self::assertCount( 3, $sections['privacy-governance'] );
	}

	public function test_sg2_access_mode_is_a_reviewed_decision_until_the_active_configuration_is_invalid(): void {
		update_option( AccessMode::OPTION_KEY, AccessMode::MODE_PUBLIC, false );
		update_option( AccessMode::CONFIG_OPTION_KEY, AccessModeState::default_config(), false );

		$check = Registry::get( 'access-mode' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$public = $check->evidence();
		self::assertSame( Evidence::HEALTH_OK, $public->health() );
		self::assertTrue( ReviewRepository::mark_reviewed( $check->id(), $public, 11 ) );
		self::assertSame( StatusResolver::CONFIGURED, StatusResolver::resolve( $check, $public ) );

		update_option( AccessMode::OPTION_KEY, AccessMode::MODE_MAINTENANCE, false );
		$invalid = $check->evidence();
		self::assertSame( Evidence::HEALTH_ATTENTION, $invalid->health() );
		self::assertSame( StatusResolver::ATTENTION, StatusResolver::resolve( $check, $invalid ) );
	}

	public function test_sg3_privileged_access_protection_mode_is_reviewable_and_drift_sensitive(): void {
		$settings = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];
		$permissions['privileged_access_mode'] = 'monitor';
		Settings::set_key( 'permissions', $permissions, 'test:core-setup' );

		$check = Registry::get( 'privileged-access-protection' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$monitor = $check->evidence();
		self::assertSame( Evidence::HEALTH_OK, $monitor->health() );
		self::assertTrue( ReviewRepository::mark_reviewed( $check->id(), $monitor, 12 ) );
		self::assertSame( StatusResolver::CONFIGURED, StatusResolver::resolve( $check, $monitor ) );

		$permissions['privileged_access_mode'] = 'enforce';
		Settings::set_key( 'permissions', $permissions, 'test:core-setup' );
		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
	}

	public function test_sg4_pending_privileged_identity_is_attention_and_cannot_be_hidden_by_later(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		PrivilegedAccessRegistry::flag_for_review( $user, 'core_setup_fixture', 'test' );

		$check = Registry::get( 'privileged-access-review' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$evidence = $check->evidence();
		self::assertSame( Evidence::HEALTH_ATTENTION, $evidence->health() );
		self::assertGreaterThanOrEqual( 1, (int) ( $evidence->context()['pending_count'] ?? 0 ) );

		self::assertTrue( ReviewRepository::mark_later( $check->id(), $evidence, 'Cannot suppress active review.', 13 ) );
		self::assertSame( StatusResolver::ATTENTION, StatusResolver::resolve( $check, $evidence ) );
	}

	public function test_sg5_enforced_two_factor_surfaces_unprotected_privileged_identities_without_leaking_secrets(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Settings::set_key(
			TwoFactorPolicy::SETTINGS_KEY,
			[
				'mode'  => TwoFactorPolicy::MODE_ENFORCE,
				'scope' => TwoFactorPolicy::SCOPE_PRIVILEGED,
			],
			'test:core-setup'
		);

		$check = Registry::get( 'two-factor-readiness' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$before = $check->evidence();
		self::assertSame( Evidence::HEALTH_ATTENTION, $before->health() );
		self::assertGreaterThanOrEqual( 1, (int) ( $before->context()['unprotected'] ?? 0 ) );

		$secret = 'JBSWY3DPEHPK3PXP';
		CredentialStore::store_totp_secret( $user_id, $secret );
		$after = $check->evidence();
		$serialized = wp_json_encode( $after->fingerprint_data() );

		self::assertIsString( $serialized );
		self::assertStringNotContainsString( $secret, $serialized );
		self::assertGreaterThanOrEqual( 1, (int) ( $after->context()['protected'] ?? 0 ) );
	}

	public function test_sg6_disabled_scanner_can_be_reviewed_as_not_applicable_without_becoming_attention(): void {
		$scanner = ResultRepository::settings();
		$scanner['enabled'] = false;
		Settings::set_key( 'integrity', $scanner, 'test:core-setup' );

		foreach ( [ 'core-scanner-policy', 'core-scanner-readiness' ] as $id ) {
			$check = Registry::get( $id );
			self::assertInstanceOf( CheckInterface::class, $check );
			$evidence = $check->evidence();

			self::assertSame( Evidence::HEALTH_OK, $evidence->health(), $id );
			self::assertTrue( $check->allows_not_applicable( $evidence ), $id );
			self::assertTrue( ReviewRepository::mark_not_applicable( $id, $evidence, 'Scanner handled elsewhere.', 14 ) );
			self::assertSame( StatusResolver::NOT_APPLICABLE, StatusResolver::resolve( $check, $evidence ), $id );
		}
	}

	public function test_sg7_privacy_ip_policy_change_invalidates_a_previous_review(): void {
		update_option( Anonymizer::OPTION_KEY, [ 'ip_mode' => Anonymizer::MODE_ANONYMIZED ], false );

		$check = Registry::get( 'privacy-ip-handling' );
		self::assertInstanceOf( CheckInterface::class, $check );
		$before = $check->evidence();
		self::assertTrue( ReviewRepository::mark_reviewed( $check->id(), $before, 15 ) );
		self::assertSame( StatusResolver::CONFIGURED, StatusResolver::resolve( $check, $before ) );

		update_option( Anonymizer::OPTION_KEY, [ 'ip_mode' => Anonymizer::MODE_NONE ], false );
		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
	}

	public function test_sg8_retention_and_verbosity_use_canonical_governance_state_for_drift(): void {
		$retention = Registry::get( 'audit-retention' );
		$verbosity = Registry::get( 'audit-verbosity' );
		self::assertInstanceOf( CheckInterface::class, $retention );
		self::assertInstanceOf( CheckInterface::class, $verbosity );

		$retention_before = $retention->evidence()->fingerprint();
		$verbosity_before = $verbosity->evidence()->fingerprint();

		RetentionPolicy::update( [ 'logins' => 180 ] );
		Verbosity::set_level( 'logins', Verbosity::LEVEL_DISABLED );

		self::assertNotSame( $retention_before, $retention->evidence()->fingerprint() );
		self::assertNotSame( $verbosity_before, $verbosity->evidence()->fingerprint() );
	}

	public function test_sg9_environment_identity_is_independent_from_indexing_policy(): void {
		$check = Registry::get( 'environment-identity' );
		self::assertInstanceOf( CheckInterface::class, $check );

		\CoreBlueprint\Core\Environment\EnvironmentTypeTestShim::set( 'production' );
		$production = $check->evidence();
		self::assertTrue( ReviewRepository::mark_reviewed( $check->id(), $production, 16 ) );
		self::assertSame( StatusResolver::CONFIGURED, StatusResolver::resolve( $check, $production ) );

		\CoreBlueprint\Core\Environment\EnvironmentTypeTestShim::set( 'staging' );
		self::assertSame( StatusResolver::NEEDS_REVIEW, StatusResolver::resolve( $check ) );
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
