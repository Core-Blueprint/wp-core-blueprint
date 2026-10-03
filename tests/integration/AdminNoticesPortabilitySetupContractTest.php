<?php
declare(strict_types=1);

use CoreBlueprint\Core\AdminNotices\Capabilities as AdminNoticeCapabilities;
use CoreBlueprint\Core\AdminNotices\Policy;
use CoreBlueprint\Core\AdminNotices\SourceLedger;
use CoreBlueprint\Core\Profiles\SectionInterface;
use CoreBlueprint\Core\Profiles\SectionRegistry as ProfileSectionRegistry;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Registry as SetupRegistry;

final class CB_Base_Admin_Notices_Portability_Setup_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_policy;
	private mixed $saved_ledger;

	public function set_up(): void {
		parent::set_up();
		$this->saved_policy = get_option( Policy::OPTION, '__cb_admin_notices_missing__' );
		$this->saved_ledger = get_option( SourceLedger::OPTION, '__cb_admin_notices_missing__' );
		delete_option( Policy::OPTION );
		delete_option( SourceLedger::OPTION );
	}

	public function tear_down(): void {
		if ( '__cb_admin_notices_missing__' === $this->saved_policy ) {
			delete_option( Policy::OPTION );
		} else {
			update_option( Policy::OPTION, $this->saved_policy, false );
		}
		if ( '__cb_admin_notices_missing__' === $this->saved_ledger ) {
			delete_option( SourceLedger::OPTION );
		} else {
			update_option( SourceLedger::OPTION, $this->saved_ledger, false );
		}
		parent::tear_down();
	}

	public function test_anps1_profile_section_round_trips_policy_without_runtime_ledger_state(): void {
		$section = ProfileSectionRegistry::get( 'admin-notices' );
		self::assertInstanceOf( SectionInterface::class, $section );

		$policy = [
			'version' => Policy::VERSION,
			'rules' => [
				[
					'source' => 'plugin:example-plugin',
					'visibility' => Policy::OPERATORS_ONLY,
					'audience' => [ 'roles' => [], 'capabilities' => [] ],
				],
			],
		];
		self::assertTrue( Policy::replace( $policy, 'test:admin-notices-profile' ) );
		update_option(
			SourceLedger::OPTION,
			[
				'version' => 1,
				'sources' => [
					'plugin:runtime-only-plugin' => [
						'id' => 'plugin:runtime-only-plugin',
					],
				],
			],
			false
		);

		$exported = $section->export();
		self::assertSame( [ 'rules' ], array_keys( $exported ) );
		self::assertSame( $policy['rules'], $exported['rules'] );
		$serialized = (string) wp_json_encode( $exported );
		self::assertStringNotContainsString( SourceLedger::OPTION, $serialized );
		self::assertStringNotContainsString( 'plugin:runtime-only-plugin', $serialized );

		self::assertTrue( Policy::reset( 'test:admin-notices-profile-reset' ) );
		$section->apply( $exported, 'test:admin-notices-profile-apply' );
		self::assertSame( $policy, Policy::get() );
		self::assertTrue( $section->verify( $exported ) );
	}

	public function test_anps2_core_setup_check_uses_notice_capability_and_metadata_only_evidence(): void {
		$check = SetupRegistry::get( 'admin-notices' );
		self::assertInstanceOf( CheckInterface::class, $check );
		self::assertSame( AdminNoticeCapabilities::MANAGE, $check->capability() );
		self::assertSame( CheckInterface::KIND_OPTIONAL, $check->kind() );
		self::assertStringContainsString( 'tab=admin-notices', $check->configuration_url() );

		$default = $check->evidence();
		self::assertTrue( $check->allows_not_applicable( $default ) );
		self::assertFalse( (bool) ( $default->context()['active'] ?? true ) );

		self::assertTrue( Policy::replace(
			[
				'version' => Policy::VERSION,
				'rules' => [
					[
						'source' => 'plugin:example-plugin',
						'visibility' => Policy::SELECTED,
						'audience' => [
							'roles' => [ 'editor' ],
							'capabilities' => [],
						],
					],
				],
			],
			'test:admin-notices-setup'
		) );

		$configured = $check->evidence();
		self::assertFalse( $check->allows_not_applicable( $configured ) );
		self::assertTrue( (bool) ( $configured->context()['active'] ?? false ) );
		self::assertSame( 1, $configured->context()['counts']['selected'] ?? null );

		$serialized = (string) wp_json_encode( $configured->fingerprint_data() );
		self::assertStringNotContainsString( 'plugin:example-plugin', $serialized );
		self::assertStringNotContainsString( 'editor', $serialized );
	}
}
