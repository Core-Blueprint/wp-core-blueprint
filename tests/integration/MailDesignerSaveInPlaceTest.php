<?php
declare(strict_types=1);

use CoreBlueprint\Core\Mail\Admin\TemplateActions;

final class CB_Mail_Designer_Save_In_Place_Test extends WP_UnitTestCase {

	public function test_template_save_keeps_admin_post_fallback_and_registers_authenticated_ajax(): void {
		TemplateActions::boot();

		self::assertNotFalse( has_action( 'admin_post_cb_core_mail_template_save', [ TemplateActions::class, 'save' ] ) );
		self::assertNotFalse( has_action( 'wp_ajax_cb_core_mail_template_save', [ TemplateActions::class, 'save_ajax' ] ) );
	}

	public function test_mail_bootstrap_loads_template_actions_for_ajax_requests(): void {
		$root = dirname( __DIR__, 2 );
		$source = (string) file_get_contents( $root . '/src/Mail/Bootstrap.php' );
		$ajax_pos = strpos( $source, 'if ( RequestContext::is_ajax() )' );

		self::assertNotFalse( $ajax_pos );
		$ajax_block = substr( $source, (int) $ajax_pos, 220 );
		self::assertStringContainsString( 'DesignerAjax::boot();', $ajax_block );
		self::assertStringContainsString( 'TemplateActions::boot();', $ajax_block );
	}

	public function test_admin_post_and_ajax_reuse_one_canonical_persistence_path(): void {
		$root = dirname( __DIR__, 2 );
		$source = (string) file_get_contents( $root . '/src/Mail/Admin/TemplateActions.php' );

		self::assertStringContainsString( '$result = self::persist(', $source );
		self::assertSame( 1, substr_count( $source, 'TemplateRepository::save(' ) );
		self::assertStringContainsString( "check_ajax_referer( 'cb_core_mail_template_save' )", $source );
		self::assertStringContainsString( "current_user_can( 'manage_options' )", $source );
		self::assertStringContainsString( 'wp_send_json_success(', $source );
		self::assertStringContainsString( 'wp_send_json_error(', $source );
	}

	public function test_mail_save_adapter_preserves_fallback_and_does_not_navigate_on_success(): void {
		$root = dirname( __DIR__, 2 );
		$adapter = (string) file_get_contents( $root . '/assets/js/features/mail-designer-save.js' );
		$assets = (string) file_get_contents( $root . '/src/Mail/Admin/DesignerAssets.php' );

		self::assertStringContainsString( "form && ajaxUrl && typeof fetch === 'function' && typeof FormData === 'function'", $adapter );
		self::assertStringContainsString( "form.addEventListener('submit'", $adapter );
		self::assertStringContainsString( 'event.preventDefault()', $adapter );
		self::assertStringContainsString( 'body: new FormData(form)', $adapter );
		self::assertStringContainsString( "credentials: 'same-origin'", $adapter );
		self::assertStringContainsString( "announceSaveState('saving')", $adapter );
		self::assertStringContainsString( "announceSaveState('saved'", $adapter );
		self::assertStringContainsString( "announceSaveState('error'", $adapter );
		self::assertStringNotContainsString( 'location.assign', $adapter );
		self::assertStringNotContainsString( 'location.replace', $adapter );
		self::assertStringNotContainsString( 'location.reload', $adapter );
		self::assertStringContainsString( "public const SAVE_MODULE_ID = '@cb-core/mail-designer-save';", $assets );
		self::assertStringContainsString( "[ self::MODULE_ID ]", $assets );
	}

	public function test_save_adapter_is_race_safe_and_shared_designer_owns_save_presentation(): void {
		$root = dirname( __DIR__, 2 );
		$adapter = (string) file_get_contents( $root . '/assets/js/features/mail-designer-save.js' );
		$launch = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );

		self::assertStringContainsString( 'saveController?.abort()', $adapter );
		self::assertStringContainsString( 'const controller = new AbortController()', $adapter );
		self::assertStringContainsString( 'signal: controller.signal', $adapter );
		self::assertStringContainsString( 'if (saveController === controller) saveController = null', $adapter );
		self::assertStringContainsString( "const SAVE_EVENT = 'cb:design-shell:savechange';", $launch );
		self::assertStringContainsString( 'save.disabled = busy', $launch );
		self::assertStringContainsString( "save.setAttribute('aria-busy', 'true')", $launch );
		self::assertStringContainsString( "save.removeAttribute('aria-busy')", $launch );
		self::assertStringContainsString( "status.classList.toggle('is-error', state === 'error')", $launch );
		self::assertStringContainsString( 'shell.dataset.cbDesignShellSaveState = state', $launch );
		self::assertStringNotContainsString( 'data-cb-mail-', $launch );
	}
}
