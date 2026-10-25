<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Mail\BindingInterpolator;
use CoreBlueprint\Core\Design\Profile\Mail\Contract as NativeMailContract;
use CoreBlueprint\Core\Mail\ProjectRenderer;

final class CB_Mail_Project_Renderer_Contract_Test extends WP_UnitTestCase {

	public function test_project_renderer_uses_one_binding_contract_for_subject_and_html(): void {
		$project = $this->project( [
			$this->node( 'mail.text', [ 'text' => 'Hello {{contact.first_name}} / {{contact.missing}}' ] ),
		] );

		$rendered = ( new ProjectRenderer() )->render(
			$project,
			[ 'subject' => 'Welcome {{contact.first_name}} / {{contact.missing}}', 'preheader' => '' ],
			[ 'contact.first_name' => 'Jane & Friends' ]
		);

		self::assertSame( 'Welcome Jane & Friends /', $rendered['subject'] );
		self::assertSame( '', $rendered['preheader'] );
		self::assertStringContainsString( 'Hello Jane &amp; Friends / ', $rendered['html'] );
		self::assertStringNotContainsString( '{{contact.first_name}}', $rendered['html'] );
	}

	public function test_project_renderer_forwards_preview_options_without_owning_editor_state(): void {
		$project = $this->project( [
			$this->node( 'mail.text', [ 'text' => 'Preview' ] ),
		] );

		$runtime = ( new ProjectRenderer() )->render( $project, [ 'subject' => 'Runtime', 'preheader' => '' ] );
		$preview = ( new ProjectRenderer() )->render(
			$project, [ 'subject' => 'Preview', 'preheader' => '' ], [], [ 'editor_markers' => true ]
		);

		self::assertStringNotContainsString( 'data-cb-mail-editor-node', $runtime['html'] );
		self::assertStringContainsString( 'data-cb-mail-editor-node="1"', $preview['html'] );
	}

	public function test_project_renderer_propagates_invalid_project_validation_failure(): void {
		$this->expectException( InvalidArgumentException::class );
		( new ProjectRenderer() )->render(
			[ 'design_type' => 'mail-template' ],
			[ 'subject' => 'Invalid project', 'preheader' => '' ]
		);
	}

	public function test_project_renderer_refuses_non_native_field_envelopes(): void {
		$project = NativeMailContract::default_document()['project'];
		foreach ( [
			[ 'subject' => 'Valid but missing preheader' ],
			[ 'subject' => '', 'preheader' => '' ],
			[ 'subject' => 'Valid', 'preheader' => '', 'legacy' => true ],
		] as $fields ) {
			try {
				( new ProjectRenderer() )->render( $project, $fields );
				self::fail( 'Renderer accepted invalid native Mail fields.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertStringContainsString( 'invalid', strtolower( $error->getMessage() ) );
			}
		}
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
		$project = NativeMailContract::default_document()['project'];
		$project['root']['children'] = [ NativeMailContract::node( 'mail.section', $children ) ];
		return $project;
	}

	/** @param array<string,mixed> $properties @return array<string,mixed> */
	private function node( string $type, array $properties ): array {
		return NativeMailContract::node( $type, [], $properties );
	}
}
