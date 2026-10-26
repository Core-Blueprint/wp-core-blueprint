<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\Design\Profile\Mail\Contract;
use CoreBlueprint\Core\Mail\Admin\TemplateActions;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;

final class CB_Mail_Designer_Save_In_Place_Test extends WP_UnitTestCase {
	public function test_historical_admin_post_and_ajax_save_have_no_write_authority(): void {
		TemplateActions::boot();
		self::assertFalse( has_action( 'admin_post_cb_core_mail_template_save', [ TemplateActions::class, 'save' ] ) );
		self::assertFalse( has_action( 'wp_ajax_cb_core_mail_template_save', [ TemplateActions::class, 'save_ajax' ] ) );
		self::assertFalse( has_action( 'admin_post_cb_core_mail_template_reset', [ TemplateActions::class, 'reset' ] ) );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Mail/Admin/TemplateActions.php' );
		self::assertStringContainsString( "'response' => 410", $source );
	}

	public function test_obsolete_mail_repository_mutations_refuse_and_leave_document_unchanged(): void {
		$template = WordPressTemplates::default_document( 'wordpress.password-reset' );
		self::assertIsArray( $template );
		$before = Repository::get( Repository::MAIL, 'wordpress.password-reset' );
		self::assertSame( 'blocked', TemplateRepository::mutate(
			'wordpress.password-reset', '0', 'Injected subject', $template['project']
		)['status'] );
		self::assertSame( 'blocked', TemplateRepository::reset_revisioned( 'wordpress.password-reset', '0' )['status'] );
		self::assertFalse( TemplateRepository::save(
			'wordpress.password-reset', 'Injected subject', $template['project']
		) );
		self::assertFalse( TemplateRepository::reset( 'wordpress.password-reset' ) );
		self::assertSame( [], TemplateRepository::overrides() );
		self::assertSame( $before, Repository::get( Repository::MAIL, 'wordpress.password-reset' ) );
	}

	public function test_native_platform_validates_revisioned_project_and_fields_without_legacy_conversion(): void {
		$valid = WordPressTemplates::default_document( 'wordpress.password-reset' );
		self::assertIsArray( $valid );
		self::assertSame( Contract::DESIGN_TYPE, $valid['project']['design_type'] );
		self::assertTrue( PlatformTransport::valid_native_document( Repository::MAIL, $valid ) );
		$invalid = $valid;
		$invalid['fields']['extra'] = 'legacy';
		self::assertFalse( PlatformTransport::valid_native_document( Repository::MAIL, $invalid ) );
		$invalid = $valid;
		$invalid['project']['schema_version'] = 0;
		self::assertFalse( PlatformTransport::valid_native_document( Repository::MAIL, $invalid ) );
		self::assertTrue( Repository::valid_revision( '0' ) );
		self::assertTrue( Repository::valid_revision( '1' ) );
		self::assertFalse( Repository::valid_revision( '-1' ) );
		self::assertFalse( Repository::valid_revision( '01' ) );
	}

	public function test_native_transport_enforces_rest_nonce_and_single_transactional_save_authority(): void {
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/designer-platform/save' );
		self::assertFalse( PlatformTransport::permission( $request ) );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Design/Editor/PlatformTransport.php' );
		self::assertStringContainsString( 'new TransactionalSave(', $source );
		self::assertStringContainsString( 'expectedStoreRevision', $source );
		self::assertStringContainsString( 'registryFingerprint', $source );
		self::assertStringContainsString( "'status' => 'unknown'", $source );
		self::assertStringNotContainsString( 'TemplateRepository::save(', $source );
	}

	public function test_active_editor_does_not_enqueue_the_deprecated_mail_save_adapter(): void {
		$assets = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Mail/Admin/DesignerAssets.php' );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_platform()', $assets );
		self::assertStringContainsString( 'platform_fullscreen_requested()', $assets );
		self::assertStringNotContainsString( 'wp_enqueue_script_module( self::SAVE_MODULE_ID', $assets );
		$transport = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Design/Editor/PlatformTransport.php' );
		self::assertStringContainsString( "'committed'", $transport );
		self::assertStringContainsString( "'refused'", $transport );
		self::assertStringContainsString( "'unknown'", $transport );
	}
}
