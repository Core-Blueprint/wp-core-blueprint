<?php
declare(strict_types=1);

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\Admin\Pages\Dashboard as DashboardPage;
use CB\Core\Admin\Pages\Profiles as ProfilesPage;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\Roles;
use CB\Core\Profiles\Document;
use CB\Core\Profiles\Engine;
use CB\Core\Profiles\PreviewStore;
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
		$document['sections']['unknown-section'] = [ 'schema_version' => 1, 'data' => [ 'probe' => true ] ];
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
		self::assertContains( 'audit-notifications', $ids );
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

	public function test_pf7_core_profiles_uses_unique_admin_route_and_trusted_dashboard_discovery(): void {
		$page = new ProfilesPage();
		self::assertSame( 'core-blueprint-config-profiles', $page->slug() );
		self::assertSame( 'Core Profiles', $page->title() );
		self::assertSame( 'Core Profiles', $page->menu_title() );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		$user->add_role( Roles::OPERATOR_ROLE );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'profiles_dashboard_fixture' ) );
		wp_set_current_user( $user_id );

		self::assertTrue( current_user_can( 'manage_options' ) );
		self::assertTrue( current_user_can( 'cb_manage_permissions' ) );

		ob_start();
		( new DashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Core Profiles<', $html );
		self::assertStringContainsString( 'page=' . ProfilesPage::SLUG, html_entity_decode( $html ) );
	}

	public function test_pf8_review_uses_default_change_badge_and_required_mutation_acknowledgement(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		$user->add_role( Roles::OPERATOR_ROLE );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'profiles_badge_fixture' ) );
		wp_set_current_user( $user_id );

		$document = Engine::export_document( 'Badge fixture', '', [ 'ai-governance' ] );
		$preview = Engine::preview( $document );
		$stored_preview = $preview;
		unset( $stored_preview['snapshots'], $stored_preview['document'] );
		$token = PreviewStore::put( $user_id, $preview['document'], $stored_preview );

		$_GET['preview'] = $token;
		try {
			ob_start();
			( new ProfilesPage() )->render();
			$html = (string) ob_get_clean();

			self::assertStringContainsString(
				'cb-core-state-badge cb-core-state-badge--default cb-core-state-badge--neutral',
				$html
			);
			self::assertStringContainsString( 'name="profile_apply_acknowledgement"', $html );
			self::assertStringContainsString( 'id="cb-profile-apply-acknowledgement"', $html );
			self::assertStringContainsString( 'required', $html );
			self::assertStringContainsString( 'I understand that applying this Profile changes this site', $html );
		} finally {
			PreviewStore::delete( $user_id, $token );
			$_GET = [];
		}
	}

	public function test_pf9_mutation_acknowledgement_requires_explicit_literal_confirmation(): void {
		self::assertTrue( MutationAcknowledgement::confirmed( '1' ) );
		self::assertFalse( MutationAcknowledgement::confirmed( 1 ) );
		self::assertFalse( MutationAcknowledgement::confirmed( true ) );
		self::assertFalse( MutationAcknowledgement::confirmed( 'true' ) );
		self::assertFalse( MutationAcknowledgement::confirmed( null ) );

		$this->expectException( InvalidArgumentException::class );
		MutationAcknowledgement::require_confirmed( null, 'Confirmation required.' );
	}


	public function test_pf10_audit_notification_policy_is_portable_without_recipient_state(): void {
		$section = SectionRegistry::get( 'audit-notifications' );
		self::assertNotNull( $section );

		$export = $section->export();
		self::assertSame( [ 'email_alerts' ], array_keys( $export ) );
		self::assertSame( [ 'critical', 'warning', 'notice', 'info' ], array_keys( $export['email_alerts'] ) );

		$invalid = $export;
		$invalid['email_recipient'] = 'operator@example.test';

		$this->expectException( InvalidArgumentException::class );
		$section->normalize( $invalid );
	}

}
