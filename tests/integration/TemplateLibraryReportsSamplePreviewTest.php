<?php
declare(strict_types=1);

use CoreBlueprint\Core\Permissions\PrivilegedAccessGuard;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Reports\DesignerPreview;
use CoreBlueprint\Core\Reports\NativeTemplateSource;
use CoreBlueprint\Core\Reports\State as ReportsState;
use CoreBlueprint\Core\TemplateLibrary\PreviewHtmlProjection;
use CoreBlueprint\Core\TemplateLibrary\TemplatePreview;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Reports_Sample_Preview_Test extends WP_UnitTestCase {
	private int $actor;
	private bool $was_enabled;

	public function set_up(): void {
		parent::set_up();
		Roles::ensure_operator_role();
		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $this->actor );
		self::assertInstanceOf( WP_User::class, $user );
		PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
			$user->add_role( Roles::OPERATOR_ROLE );
		} );
		self::assertTrue( PrivilegedAccessRegistry::approve(
			$user, 0, 'reports_library_preview_fixture'
		) );
		wp_set_current_user( $this->actor );
		self::assertTrue( current_user_can( 'cb_manage_branding' ) );
		$this->was_enabled = ReportsState::is_enabled();
		if ( ! $this->was_enabled ) {
			ReportsState::set_enabled( true, 'reports_library_preview_fixture' );
		}
		TemplateSourceRegistry::_reset_for_testing();
	}

	public function tear_down(): void {
		TemplateSourceRegistry::_reset_for_testing();
		wp_set_current_user( $this->actor );
		if ( ReportsState::is_enabled() !== $this->was_enabled ) {
			ReportsState::set_enabled( $this->was_enabled, 'reports_library_preview_fixture' );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_reports_template_renders_sample_only_without_site_or_user_state(): void {
		$previous_name = get_option( 'blogname' );
		update_option( 'blogname', 'CONFIDENTIAL-ARCHIVED-WEBSITE' );
		try {
			$preview = TemplateSourceRegistry::preview(
				NativeTemplateSource::SOURCE_ID, NativeTemplateSource::TEMPLATE_ID, '1', null
			);
			self::assertInstanceOf( TemplatePreview::class, $preview );
			self::assertStringContainsString( 'Example Website', $preview->html );
			self::assertStringContainsString( 'Example Plugin', $preview->html );
			self::assertStringContainsString( 'cb-flow-', $preview->html );
			self::assertStringNotContainsString( 'CONFIDENTIAL-ARCHIVED-WEBSITE', $preview->html );
			self::assertStringNotContainsString( get_userdata( $this->actor )->user_email, $preview->html );
			self::assertStringNotContainsString( '<script', $preview->html );
			self::assertStringNotContainsString( '<style', $preview->html );
			self::assertStringNotContainsString( '<img', $preview->html );
			self::assertStringNotContainsString( 'src=', $preview->html );
			self::assertStringNotContainsString( 'cb-core-flow-preview-sizing', $preview->html );
			self::assertLessThanOrEqual( TemplatePreview::MAX_HTML_BYTES, strlen( $preview->html ) );
		} finally {
			update_option( 'blogname', $previous_name );
		}
	}

	public function test_reports_preview_refuses_unknown_revision_scope_and_disabled_source(): void {
		$source = new NativeTemplateSource();
		self::assertTrue( $source->can_preview( 'maintenance', $this->actor ) );
		self::assertNull( $source->render_preview( 'maintenance', '2', $this->actor ) );
		self::assertNull( TemplateSourceRegistry::preview(
			NativeTemplateSource::SOURCE_ID, 'maintenance', '1',
			[ 'extension' => 'core-blueprint', 'designer' => 'mail' ]
		) );
		ReportsState::set_enabled( false, 'reports_library_preview_disabled_fixture' );
		self::assertFalse( $source->can_preview( 'maintenance', $this->actor ) );
		self::assertNull( TemplateSourceRegistry::preview(
			NativeTemplateSource::SOURCE_ID, 'maintenance', '1', null
		) );
		ReportsState::set_enabled( true, 'reports_library_preview_fixture' );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		self::assertNull( TemplateSourceRegistry::preview(
			NativeTemplateSource::SOURCE_ID, 'maintenance', '1', null
		) );
		wp_set_current_user( $this->actor );
	}

	public function test_flow_designer_preview_keeps_its_separate_archived_snapshot_path(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Reports/DesignerPreview.php'
		);
		self::assertStringContainsString( 'public function render_with_source(', $source );
		self::assertStringContainsString( 'return $this->render_report( $this->preview_report(), $native );', $source );
		self::assertStringContainsString( 'return $this->render_report( $this->sample_report( true ), $native )', $source );
		self::assertStringContainsString( 'Storage::find_recent(', $source );
		self::assertSame( 'string', get_debug_type(
			( new DesignerPreview() )->render_library_sample( [
				'project' => \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_project(),
				'fields' => array_replace(
					\CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_fields(),
					[ 'show_logo' => 'off', 'logo_attachment_id' => 0 ]
				),
			] )
		) );
	}

	public function test_shared_projection_removes_entire_active_node_subtrees(): void {
		$raw = '<!doctype html><html><head><style>body{color:red}</style></head>'
			. '<body><div>Safe sample</div><script>PRIVATE_SCRIPT_TEXT()</script>'
			. '<noscript>PRIVATE_NOSCRIPT_TEXT</noscript>'
			. '<img src="https://remote.example/x.png" alt="Private">'
			. '<a href="https://remote.example/secret">Visible label</a></body></html>';
		$preview = PreviewHtmlProjection::from_rendered_html( '1', $raw );
		self::assertStringContainsString( 'Safe sample', $preview->html );
		self::assertStringContainsString( 'Visible label', $preview->html );
		foreach ( [ 'PRIVATE_SCRIPT_TEXT', 'PRIVATE_NOSCRIPT_TEXT', 'remote.example', '<img', 'href=', 'src=' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $preview->html );
		}
		$this->expectException( InvalidArgumentException::class );
		PreviewHtmlProjection::from_rendered_html( '1', str_repeat( 'X', 262145 ) );
	}
}
