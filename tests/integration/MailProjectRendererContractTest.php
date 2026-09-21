<?php
declare(strict_types=1);

use CB\Core\Design\Profile\Mail\BindingInterpolator;
use CB\Core\Mail\ProjectRenderer;

final class CB_Mail_Project_Renderer_Contract_Test extends WP_UnitTestCase {

	public function test_project_renderer_uses_one_binding_contract_for_subject_and_html(): void {
		$project = $this->project( [
			$this->node( 'mail.text', [ 'text' => 'Hello {{contact.first_name}} / {{contact.missing}}' ] ),
		] );

		$rendered = ( new ProjectRenderer() )->render(
			$project,
			'Welcome {{contact.first_name}} / {{contact.missing}}',
			[ 'contact.first_name' => '<Jane>' ]
		);

		self::assertSame( 'Welcome <Jane> / ', $rendered['subject'] );
		self::assertStringContainsString( 'Hello &lt;Jane&gt; / ', $rendered['html'] );
		self::assertStringNotContainsString( '{{contact.first_name}}', $rendered['html'] );
	}

	public function test_project_renderer_forwards_preview_options_without_owning_editor_state(): void {
		$project = $this->project( [
			$this->node( 'mail.text', [ 'text' => 'Preview' ] ),
		] );

		$runtime = ( new ProjectRenderer() )->render( $project, 'Runtime' );
		$preview = ( new ProjectRenderer() )->render( $project, 'Preview', [], [ 'editor_markers' => true ] );

		self::assertStringNotContainsString( 'data-cb-mail-editor-node', $runtime['html'] );
		self::assertStringContainsString( 'data-cb-mail-editor-node="1"', $preview['html'] );
	}

	public function test_project_renderer_propagates_invalid_project_validation_failure(): void {
		$this->expectException( InvalidArgumentException::class );

		( new ProjectRenderer() )->render(
			[ 'design_type' => 'mail-template' ],
			'Invalid project'
		);
	}

	public function test_project_renderer_keeps_storage_resolution_and_transport_outside_public_contract(): void {
		$root = dirname( __DIR__, 2 );
		$source = (string) file_get_contents( $root . '/src/Mail/ProjectRenderer.php' );

		self::assertStringNotContainsString( 'TemplateRepository', $source );
		self::assertStringNotContainsString( 'BindingRegistry', $source );
		self::assertStringNotContainsString( 'Sender::', $source );
		self::assertStringNotContainsString( 'wp_mail(', $source );
		self::assertStringNotContainsString( 'get_option(', $source );
		self::assertStringNotContainsString( 'update_option(', $source );
	}

	public function test_mail_renderers_share_the_canonical_binding_interpolator(): void {
		self::assertSame(
			'Hello Jane / ',
			BindingInterpolator::interpolate(
				'Hello {{ contact.first_name }} / {{contact.missing}}',
				[ 'contact.first_name' => 'Jane' ]
			)
		);

		$root = dirname( __DIR__, 2 );
		$html_renderer = (string) file_get_contents( $root . '/src/Design/Profile/Mail/HtmlRenderer.php' );
		$designer_renderer = (string) file_get_contents( $root . '/src/Mail/Designer/Renderer.php' );

		self::assertStringContainsString( 'BindingInterpolator::interpolate(', $html_renderer );
		self::assertStringNotContainsString( 'private function interpolate(', $html_renderer );
		self::assertStringContainsString( 'new ProjectRenderer()', $designer_renderer );
		self::assertStringNotContainsString( 'preg_replace_callback(', $designer_renderer );
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
