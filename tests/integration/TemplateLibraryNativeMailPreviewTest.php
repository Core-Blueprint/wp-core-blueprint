<?php
declare(strict_types=1);

use CoreBlueprint\Core\Mail\Designer\LibraryPreview;
use CoreBlueprint\Core\Mail\Designer\NativeTemplateSource;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreview;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Native_Mail_Preview_Test extends WP_UnitTestCase {
	private int $actor;
	private bool $was_enabled;

	public function set_up(): void {
		parent::set_up();
		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'mail_library_preview_fixture' ) );
		wp_set_current_user( $this->actor );
		$this->was_enabled = DesignerState::is_enabled();
		if ( ! $this->was_enabled ) {
			DesignerState::set_enabled( true, 'mail_library_preview_fixture' );
		}
		TemplateSourceRegistry::_reset_for_testing();
	}

	public function tear_down(): void {
		TemplateSourceRegistry::_reset_for_testing();
		TemplateRegistry::_reset_for_testing();
		wp_set_current_user( $this->actor );
		if ( DesignerState::is_enabled() !== $this->was_enabled ) {
			DesignerState::set_enabled( $this->was_enabled, 'mail_library_preview_fixture' );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_all_base_wordpress_mail_defaults_render_inert_synthetic_fragments(): void {
		$source = new NativeTemplateSource();
		self::assertTrue( TemplateSourceRegistry::supports_preview( NativeTemplateSource::SOURCE_ID ) );
		$defaults = TemplateRegistry::all();
		$checked = 0;
		foreach ( $defaults as $id => $definition ) {
			if ( ! str_starts_with( (string) $id, 'wordpress.' ) ) continue;
			self::assertTrue( $source->can_preview( (string) $id, $this->actor ) );
			$result = TemplateSourceRegistry::preview( NativeTemplateSource::SOURCE_ID,
				(string) $id, '1', null );
			self::assertInstanceOf( TemplatePreview::class, $result, (string) $id );
			self::assertSame( '1', $result->version );
			self::assertStringContainsString( '<table', $result->html );
			self::assertStringContainsString( 'Example Site', $result->html );
			self::assertStringNotContainsString( '<script', $result->html );
			self::assertStringNotContainsString( '<img', $result->html );
			self::assertStringNotContainsString( '<a ', $result->html );
			self::assertStringNotContainsString( 'href=', $result->html );
			self::assertStringNotContainsString( 'src=', $result->html );
			self::assertStringNotContainsString( 'https://example.invalid/action', $result->html );
			++$checked;
		}
		self::assertSame( 6, $checked );
	}

	public function test_native_mail_preview_refuses_stale_version_and_third_party_provider_defaults(): void {
		$source = new NativeTemplateSource();
		self::assertNull( $source->render_preview( 'wordpress.new-user', '2', $this->actor ) );
		self::assertNull( TemplateSourceRegistry::preview(
			NativeTemplateSource::SOURCE_ID, 'wordpress.new-user', '2', null
		) );
		self::assertNull( $source->render_preview( 'wordpress.missing', '1', $this->actor ) );
		$external = [
			'id' => 'bookings.fixture', 'provider' => 'bookings',
			'group' => 'bookings', 'label' => 'Example external',
			'document' => WordPressTemplates::default_document( 'wordpress.new-user' ),
		];
		self::assertTrue( TemplateRegistry::register( $external ) );
		self::assertTrue( $source->can_view( 'bookings.fixture', $this->actor ) );
		self::assertFalse( $source->can_preview( 'bookings.fixture', $this->actor ) );
		self::assertNull( TemplateSourceRegistry::preview(
			NativeTemplateSource::SOURCE_ID, 'bookings.fixture', '1', null
		) );
	}

	public function test_synthetic_bindings_never_pull_live_site_or_user_values(): void {
		$old_title = get_option( 'blogname' );
		update_option( 'blogname', 'CONFIDENTIAL-LIVE-SITE-NAME' );
		try {
			$preview = LibraryPreview::render(
				WordPressTemplates::default_document( 'wordpress.new-user' ), '1'
			);
			self::assertStringContainsString( 'Example Site', $preview->html );
			self::assertStringNotContainsString( 'CONFIDENTIAL-LIVE-SITE-NAME', $preview->html );
			self::assertStringNotContainsString( get_userdata( $this->actor )->user_email, $preview->html );
		} finally {
			update_option( 'blogname', $old_title );
		}
	}
}
