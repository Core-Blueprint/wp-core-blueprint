<?php
declare(strict_types=1);

use CB\Core\Profiles\Document;
use CB\Core\Profiles\Engine;
use CB\Core\Profiles\SectionRegistry;
use CB\Core\Profiles\Sections\ModuleStatesSection;

final class CB_Base_Profiles_Foundation_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $saved_options = [];

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'cb_core_mail_settings', 'cb_core_bypass_token', 'cb_core_integrity_baseline', 'cb_core_integrity_latest' ] as $option ) {
			$this->saved_options[ $option ] = get_option( $option, '__cb_profiles_missing__' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->saved_options as $option => $value ) {
			if ( '__cb_profiles_missing__' === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}
		parent::tear_down();
	}
	public function test_pf1_profile_document_is_versioned_bounded_and_fail_closed(): void {
		$document = Engine::export_document( 'Agency Baseline', 'Portable baseline', [ 'ai-governance' ] );
		$json = wp_json_encode( $document );
		self::assertIsString( $json );
		$decoded = Document::decode( $json );
		self::assertSame( Document::FORMAT, $decoded['format'] );
		self::assertSame( Document::FORMAT_VERSION, $decoded['format_version'] );
		self::assertSame( 'Agency Baseline', $decoded['profile']['name'] );

		$future = $decoded;
		$future['format_version'] = Document::FORMAT_VERSION + 1;
		$this->expectException( InvalidArgumentException::class );
		Document::decode( (string) wp_json_encode( $future ) );
	}

	public function test_pf2_unknown_and_future_section_schemas_are_rejected_before_mutation(): void {
		$document = Engine::export_document( 'Baseline', '', [ 'ai-governance' ] );
		$document['sections']['unknown-section'] = [ 'schema_version' => 1, 'data' => [] ];
		try {
			Engine::preview( $document );
			self::fail( 'Unknown Profile section was accepted.' );
		} catch ( InvalidArgumentException $error ) {
			self::assertStringContainsString( 'not available', $error->getMessage() );
		}

		$document = Engine::export_document( 'Baseline', '', [ 'ai-governance' ] );
		$document['sections']['ai-governance']['schema_version'] = 999;
		$this->expectException( InvalidArgumentException::class );
		Engine::preview( $document );
	}


	public function test_pf3_builtin_registry_excludes_nonportable_domains_and_keeps_activation_last(): void {
		$ids = array_keys( SectionRegistry::all() );
		self::assertNotContains( 'mail', $ids );
		self::assertNotContains( 'snippets', $ids );
		self::assertNotContains( 'access-mode', $ids );
		self::assertSame( 'module-states', end( $ids ) );
	}

	public function test_pf4_all_builtin_exports_exclude_secret_and_runtime_evidence_values(): void {
		update_option( 'cb_core_mail_settings', [
			'brevo_api_key' => 'PROFILE_SECRET_SENTINEL_A',
			'smtp_password' => 'PROFILE_SECRET_SENTINEL_B',
			'smtp_username' => 'PROFILE_SECRET_SENTINEL_C',
		], false );
		update_option( 'cb_core_bypass_token', 'PROFILE_SECRET_SENTINEL_D', false );
		update_option( 'cb_core_integrity_baseline', [ 'secret' => 'PROFILE_SECRET_SENTINEL_E' ], false );
		update_option( 'cb_core_integrity_latest', [ 'secret' => 'PROFILE_SECRET_SENTINEL_F' ], false );

		$document = Engine::export_document( 'All portable', '', array_keys( SectionRegistry::all() ) );
		$json = (string) wp_json_encode( $document );
		foreach ( range( 'A', 'F' ) as $suffix ) {
			self::assertStringNotContainsString( 'PROFILE_SECRET_SENTINEL_' . $suffix, $json );
		}
		self::assertStringNotContainsString( 'cb_core_mail_settings', $json );
		self::assertStringNotContainsString( 'cb_core_integrity_baseline', $json );
		foreach ( [
			'site_mode',
			'redirect_custom_url',
			'default_assigned_to',
			'logo_attachment_id',
			'email_recipient',
			'brevo_api_key',
			'smtp_password',
		] as $forbidden_key ) {
			self::assertStringNotContainsString( $forbidden_key, $json );
		}
	}
	public function test_pf5_transport_types_are_strict_and_preview_metadata_is_present(): void {
		$document = Engine::export_document( 'Strict types', '', [ 'ai-governance' ] );
		$preview = Engine::preview( $document );
		self::assertArrayHasKey( 'description', $preview['sections']['ai-governance'] );
		self::assertArrayHasKey( 'warnings', $preview['sections']['ai-governance'] );

		$wrong_value_type = $document;
		$wrong_value_type['sections']['ai-governance']['data']['retention_days'] = '30';
		try {
			Engine::preview( $wrong_value_type );
			self::fail( 'A quoted integer was accepted as Profile configuration.' );
		} catch ( InvalidArgumentException $error ) {
			self::assertStringContainsString( 'type', strtolower( $error->getMessage() ) );
		}

		$wrong_format_type = $document;
		$wrong_format_type['format_version'] = (string) Document::FORMAT_VERSION;
		$this->expectException( InvalidArgumentException::class );
		Engine::preview( $wrong_format_type );
	}

	public function test_pf6_module_state_preflight_does_not_reauthorize_against_dashboard_capabilities(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		self::assertFalse( current_user_can( 'manage_options' ) );

		$section = new ModuleStatesSection();
		$current = $section->snapshot();
		$section->preflight( $current, $current );
		self::assertSame( $current, $section->snapshot() );
	}

}
