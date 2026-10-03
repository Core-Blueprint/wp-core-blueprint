<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\ScreenContext;
use CoreBlueprint\Core\Design\Profile\Mail\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Mail\Validator;
use CoreBlueprint\Core\Mail\Designer\BindingRegistry;
use CoreBlueprint\Core\Mail\Designer\ComponentRegistry;
use CoreBlueprint\Core\Mail\Designer\Renderer;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\Designer\WordPressIntegration;
use CoreBlueprint\Core\Mail\Settings as MailSettings;

final class CB_Mail_Designer_Foundation_Test extends WP_UnitTestCase {
	private mixed $original_settings;

	public function set_up(): void {
		parent::set_up();
		$this->original_settings = get_option( MailSettings::OPTION, null );
		TemplateRegistry::_reset_for_testing();
		BindingRegistry::_reset_for_testing();
		ComponentRegistry::_reset_for_testing();
	}

	public function tear_down(): void {
		if ( null === $this->original_settings ) {
			delete_option( MailSettings::OPTION );
		} else {
			update_option( MailSettings::OPTION, $this->original_settings, false );
		}
		TemplateRegistry::_reset_for_testing();
		BindingRegistry::_reset_for_testing();
		ComponentRegistry::_reset_for_testing();
		parent::tear_down();
	}

	public function test_mail_settings_persist_only_independent_v1_states(): void {
		delete_option( MailSettings::OPTION );

		$settings = MailSettings::defaults();
		$settings['delivery_enabled'] = true;
		$settings['designer_enabled'] = false;
		self::assertTrue( MailSettings::save( $settings ) );

		$stored = get_option( MailSettings::OPTION, null );
		self::assertIsArray( $stored );
		self::assertArrayNotHasKey( 'enabled', $stored );
		self::assertTrue( (bool) $stored['delivery_enabled'] );
		self::assertFalse( (bool) $stored['designer_enabled'] );
		self::assertTrue( MailSettings::enabled() );
	}

	public function test_designer_can_be_enabled_while_core_blueprint_delivery_is_disabled(): void {
		$settings = MailSettings::defaults();
		$settings['delivery_enabled'] = false;
		$settings['designer_enabled'] = true;
		MailSettings::save( $settings );

		self::assertFalse( MailSettings::delivery_enabled() );
		self::assertTrue( MailSettings::designer_enabled() );
		self::assertTrue( MailSettings::enabled() );
	}

	public function test_mail_screen_context_matches_renderer_tab_routes(): void {
		$original_get = $_GET;

		try {
			$routes = [
				''          => 'overview',
				'overview'  => 'overview',
				'templates' => 'templates',
				'settings'  => 'settings',
				'test'      => 'test',
				'logs'      => 'logs',
			];

			foreach ( $routes as $requested => $expected ) {
				$_GET = [ 'page' => 'core-blueprint-mail' ];
				if ( '' !== $requested ) {
					$_GET['tab'] = $requested;
				}

				$context = ScreenContext::from_request( 'core-blueprint_page_core-blueprint-mail' );
				self::assertSame( $expected, $context->tab(), 'Mail ScreenContext route drifted from the renderer for tab: ' . ( '' === $requested ? '(default)' : $requested ) );
			}
		} finally {
			$_GET = $original_get;
		}
	}

	public function test_wordpress_templates_use_the_single_mail_design_profile(): void {
		$templates = TemplateRegistry::all();
		self::assertArrayHasKey( 'wordpress.password-reset', $templates );
		self::assertArrayHasKey( 'wordpress.new-user', $templates );
		self::assertSame( 'mail-template', $templates['wordpress.password-reset']['project']['design_type'] );
		self::assertSame( 'mail.root', $templates['wordpress.password-reset']['project']['root']['type'] );
	}

	public function test_mail_renderer_escapes_user_content_and_interpolates_scalar_bindings(): void {
		$project = $this->project( [
			$this->node( 'mail.text', [ 'text' => 'Hello {{user.display_name}} <script>alert(1)</script>' ] ),
		] );
		$diagnostics = ( new Validator() )->validate( $project );
		self::assertFalse( $diagnostics->has_errors(), wp_json_encode( $diagnostics->to_array() ) );

		$html = ( new HtmlRenderer() )->render( $project, [ 'user.display_name' => '<Jane>' ] );
		self::assertStringContainsString( 'Hello &lt;Jane&gt; &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_mail_renderer_emits_email_client_compatibility_fallbacks(): void {
		$project = $this->project( [
			$this->node( 'mail.button', [
				'label' => 'Open account',
				'url' => 'https://example.test/account',
				'background' => '#2563eb',
				'color' => '#ffffff',
				'radius' => 6,
			] ),
			$this->node( 'mail.spacer', [ 'height' => 24 ] ),
		] );

		$html = ( new HtmlRenderer() )->render( $project );

		self::assertStringContainsString( '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">', $html );
		self::assertStringContainsString( '<!--[if mso]><table role="presentation" width="600" align="center"', $html );
		self::assertStringContainsString( 'mso-table-lspace:0pt;mso-table-rspace:0pt', $html );
		self::assertStringContainsString( 'bgcolor="#ffffff"', $html );
		self::assertStringContainsString( 'class="cb-mail-button-table"', $html );
		self::assertStringContainsString( 'mso-padding-alt:12px 20px', $html );
		self::assertStringContainsString( 'mso-line-height-rule:exactly', $html );
	}

	public function test_authoring_markers_are_preview_only(): void {
		$project = $this->project( [
			$this->node( 'mail.text', [ 'text' => 'Preview marker boundary' ] ),
		] );
		$renderer = new HtmlRenderer();
		$runtime_html = $renderer->render( $project );
		$preview_html = $renderer->render( $project, [], [ 'editor_markers' => true ] );

		self::assertStringNotContainsString( 'data-cb-mail-editor-node', $runtime_html );
		self::assertStringNotContainsString( 'data-cb-mail-path', $runtime_html );
		self::assertStringContainsString( 'data-cb-mail-editor-node="1"', $preview_html );
		self::assertStringContainsString( 'data-cb-mail-path="[0]"', $preview_html );
		self::assertStringContainsString( 'data-cb-mail-path="[0,0]"', $preview_html );
	}

	public function test_registered_template_preview_renders_without_enabling_delivery(): void {
		$settings = MailSettings::defaults();
		$settings['delivery_enabled'] = false;
		$settings['designer_enabled'] = true;
		MailSettings::save( $settings );

		$preview = Renderer::preview( 'wordpress.password-reset' );
		self::assertIsArray( $preview );
		self::assertStringContainsString( '<!doctype html>', $preview['html'] );
		self::assertStringContainsString( 'data-cb-mail-editor-node="1"', $preview['html'] );
		self::assertStringContainsString( 'Reset', $preview['subject'] );
		self::assertFalse( MailSettings::delivery_enabled() );
	}

	public function test_wordpress_password_reset_adapter_preserves_recipient_and_replaces_presentation_only(): void {
		$user_id = self::factory()->user->create( [
			'user_login'   => 'mail_designer_user',
			'user_email'   => 'mail-designer@example.test',
			'display_name' => 'Mail Designer User',
		] );
		$user = get_user_by( 'id', $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		$canonical = [
			'to'      => 'mail-designer@example.test',
			'subject' => 'Canonical subject',
			'message' => 'Canonical body',
			'headers' => '',
		];
		$rendered = WordPressIntegration::password_reset( $canonical, 'test-key', $user->user_login, $user );

		self::assertSame( $canonical['to'], $rendered['to'] );
		self::assertNotSame( $canonical['subject'], $rendered['subject'] );
		self::assertStringContainsString( '<!doctype html>', $rendered['message'] );
		self::assertStringNotContainsString( 'data-cb-mail-editor-node', $rendered['message'] );
		self::assertContains( 'Content-Type: text/html; charset=UTF-8', $rendered['headers'] );
		self::assertStringContainsString( 'test-key', $rendered['message'] );
	}

	public function test_mail_designer_declares_workspace_identity_separately_from_template_context(): void {
		$root     = dirname( __DIR__, 2 );
		$template = (string) file_get_contents( $root . '/templates/mail-designer.php' );

		self::assertStringContainsString( 'data-cb-design-title="<?php esc_attr_e( \'Mail Designer\', \'core-blueprint\' ); ?>"', $template );
		self::assertStringContainsString( 'data-cb-design-shell-context', $template );
		self::assertStringContainsString( 'data-cb-mail-template-select', $template );
		self::assertStringContainsString( 'data-cb-design-shell-viewport="desktop"', $template );
		self::assertStringContainsString( 'data-cb-design-shell-viewport="tablet"', $template );
		self::assertStringContainsString( 'data-cb-design-shell-viewport="mobile"', $template );
	}

	public function test_core_textual_components_use_content_label_for_editable_copy(): void {
		$components = ComponentRegistry::all();

		foreach ( [ 'heading', 'text' ] as $component_id ) {
			self::assertArrayHasKey( $component_id, $components );
			$fields = array_values( array_filter(
				$components[ $component_id ]['inspector'],
				static fn ( array $field ): bool => 'text' === ( $field['key'] ?? '' )
			) );
			self::assertCount( 1, $fields );
			self::assertSame( __( 'Content', 'core-blueprint' ), $fields[0]['label'] );
		}
	}

	public function test_mail_designer_uses_canonical_layers_and_selection_lifecycle(): void {
		$root = dirname( __DIR__, 2 );
		$feature = (string) file_get_contents( $root . '/assets/js/features/mail-designer.js' );

		self::assertStringContainsString( 'createDesignerLayerTree', $feature );
		self::assertStringContainsString( 'onSelectItem: (path, options) => selectionController?.select(path, options)', $feature );
		self::assertStringContainsString( 'path: [...path]', $feature );
		self::assertStringNotContainsString( 'onSelect: () => selectionController?.select(path)', $feature );
		self::assertStringContainsString( 'createDesignerSelectionController', $feature );
		self::assertStringContainsString( 'renderLayers: renderStructure', $feature );
		self::assertStringContainsString( 'renderInspector,', $feature );
		self::assertStringContainsString( 'syncCanvas: syncPreviewSelection', $feature );
		self::assertStringContainsString( 'context?.inspector?.target ?? session.inspector().target', $feature );
		self::assertStringContainsString( 'selectedEntry?.node ?? null', $feature );
		self::assertStringNotContainsString( 'const nodeAt =', $feature );
		self::assertStringContainsString( 'resetSelection: true', $feature );
		self::assertStringContainsString( "remove: node.type !== 'mail.section'", $feature );
		self::assertStringNotContainsString( 'renderSelectionViews', $feature );
		self::assertStringNotContainsString( 'session.editorState.selection.select', $feature );
		self::assertStringNotContainsString( 'session.editorState.selection.clear', $feature );
		self::assertStringNotContainsString( 'createDesignerLayerRow', $feature );
		self::assertStringNotContainsString( 'dragPath', $feature );
		self::assertStringNotContainsString( 'decorateDesignerControl', $feature );
		self::assertStringNotContainsString( "remove.textContent = 'Remove element';", $feature );
	}

	public function test_mail_designer_uses_shared_clipboard_feedback_without_mutating_token_labels(): void {
		$root = dirname( __DIR__, 2 );
		$feature = (string) file_get_contents( $root . '/assets/js/features/mail-designer.js' );
		$assets = (string) file_get_contents( $root . '/src/Mail/Admin/DesignerAssets.php' );
		$manifest = (string) file_get_contents( $root . '/src/Admin/ScreenAssetRegistry.php' );

		self::assertStringContainsString( "window.cbCore?.clipboard", $feature );
		self::assertStringContainsString( 'clipboard.enhance(button, {', $feature );
		self::assertStringContainsString( 'text: token,', $feature );
		self::assertStringContainsString( 'icon: false,', $feature );
		self::assertStringNotContainsString( 'navigator.clipboard.writeText', $feature );
		self::assertStringNotContainsString( "button.textContent = 'Copied';", $feature );
		self::assertStringContainsString( "[ DesignEditorAssets::MODULE_ID, '@cb-core/clipboard' ]", $assets );
		self::assertStringContainsString( "'templates' === \$tab", $manifest );
		self::assertStringContainsString( "'foundation.clipboard'", $manifest );
	}

	public function test_mail_designer_shell_starts_hidden_until_shared_launch_activates_it(): void {
		$root = dirname( __DIR__, 2 );
		$template = (string) file_get_contents( $root . '/templates/mail-designer.php' );

		self::assertStringContainsString( '<div class="cb-core-design-shell" data-cb-design-shell hidden>', $template );
	}

	public function test_mail_designer_opts_into_shared_fullscreen_without_owning_fullscreen_runtime(): void {
		$root = dirname( __DIR__, 2 );
		$template = (string) file_get_contents( $root . '/templates/mail-designer.php' );
		$feature = (string) file_get_contents( $root . '/assets/js/features/mail-designer.js' );

		self::assertStringContainsString( 'data-cb-design-shell-fullscreen', $template );
		self::assertStringContainsString( 'aria-pressed="false"', $template );
		self::assertStringContainsString( "__( 'Fullscreen mode', 'default' )", $template );
		self::assertStringNotContainsString( "'Fullscreen mode', 'core-blueprint'", $template );
		self::assertStringNotContainsString( 'toggleFullscreen', $feature );
		self::assertStringNotContainsString( 'enterFullscreen', $feature );
		self::assertStringNotContainsString( 'exitFullscreen', $feature );
	}

	/** @param list<array<string,mixed>> $children @return array<string,mixed> */
	private function project( array $children ): array {
		return [
			'schema_version' => 0,
			'design_type' => 'mail-template',
			'root' => [
				'type' => 'mail.root',
				'provider' => 'core',
				'properties' => [
					'layout' => [
						'width' => 600,
						'background' => '#f3f4f6',
						'contentBackground' => '#ffffff',
						'fontFamily' => 'Arial, Helvetica, sans-serif',
						'textColor' => '#1f2937',
						'accentColor' => '#2563eb',
					],
				],
				'children' => [ [
					'type' => 'mail.section',
					'provider' => 'core',
					'properties' => [ 'padding' => 32, 'background' => '#ffffff' ],
					'children' => $children,
				] ],
			],
		];
	}

	/** @param array<string,mixed> $properties @return array<string,mixed> */
	private function node( string $type, array $properties ): array {
		return [ 'type' => $type, 'provider' => 'core', 'properties' => $properties, 'children' => [] ];
	}
}
