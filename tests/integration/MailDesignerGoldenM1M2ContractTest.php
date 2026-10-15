<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Mail\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Mail\RichText;
use CoreBlueprint\Core\Design\Profile\Mail\Validator;

/** Proposed Golden contract tests. Run with WP/PHPUnit only after Base reconciliation. */
final class CB_Mail_Designer_Golden_M1_M2_Contract_Test extends WP_UnitTestCase {
	/** @param list<array<string,mixed>> $section_children */
	private function project( array $section_children ): array {
		return [
			'schema_version' => 0,
			'design_type' => 'mail-template',
			'root' => [
				'type' => 'mail.root',
				'provider' => 'core',
				'properties' => [ 'layout' => [ 'width' => 600 ] ],
				'children' => [
					[
						'type' => 'mail.section',
						'provider' => 'core',
						'properties' => [ 'background' => '#ffffff', 'padding' => 20 ],
						'children' => $section_children,
					],
				],
			],
		];
	}

	private function node( string $type, array $properties = [], array $children = [] ): array {
		return [ 'type' => $type, 'provider' => 'core', 'properties' => $properties, 'children' => $children ];
	}

	public function test_allowlisted_rich_text_preserves_semantics_but_rejects_active_markup(): void {
		$html = RichText::render(
			'<p onclick="alert(1)">Hello <strong>{{contact.name}}</strong> '
			. '<a href="javascript:alert(1)" onmouseover="bad()">Click</a>'
			. '<script>alert(1)</script><img src="https://tracker.invalid/pixel">'
			. '<a href="https://example.test/info">Safe</a></p>',
			[ 'contact.name' => '<img src=x onerror=alert(1)>' ]
		);
		self::assertStringContainsString( '<strong>&lt;img', $html );
		self::assertStringContainsString( '<a href="https://example.test/info">Safe</a>', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringNotContainsString( 'onmouseover', $html );
		self::assertStringNotContainsString( 'onclick', $html );
	}

	public function test_rich_text_renderer_keeps_plain_text_block_contract_unchanged(): void {
		$project = $this->project( [
			$this->node( 'mail.text', [ 'text' => '<strong>Plain stays escaped</strong>' ] ),
			$this->node( 'mail.rich-text', [ 'markup' => '<p>Welcome <em>{{person.name}}</em></p>' ] ),
		] );
		self::assertFalse( ( new Validator() )->validate( $project )->has_errors() );
		$html = ( new HtmlRenderer() )->render( $project, [ 'person.name' => 'Pat' ] );
		self::assertStringContainsString( '&lt;strong&gt;Plain stays escaped&lt;/strong&gt;', $html );
		self::assertStringContainsString( '<p>Welcome <em>Pat</em></p>', $html );
	}

	public function test_mail_columns_render_table_layout_mobile_stack_and_editor_paths(): void {
		$project = $this->project( [
			$this->node( 'mail.columns', [ 'ratio' => 'left-third', 'gap' => 12 ], [
				$this->node( 'mail.column', [], [ $this->node( 'mail.heading', [ 'text' => 'Left' ] ) ] ),
				$this->node( 'mail.column', [], [ $this->node( 'mail.text', [ 'text' => 'Right' ] ) ] ),
			] ),
		] );
		self::assertFalse( ( new Validator() )->validate( $project )->has_errors() );
		$html = ( new HtmlRenderer() )->render( $project, [], [ 'editor_markers' => true ] );
		self::assertStringContainsString( 'class="cb-mail-columns"', $html );
		self::assertStringContainsString( 'width="33%"', $html );
		self::assertStringContainsString( 'width="67%"', $html );
		self::assertStringContainsString( '.cb-mail-column{display:block!important;width:100%!important;', $html );
		self::assertStringContainsString( 'data-cb-mail-path="[0,0,0]"', $html );
		self::assertStringContainsString( 'data-cb-mail-path="[0,0,1]"', $html );
		self::assertStringContainsString( 'Left', $html );
		self::assertStringContainsString( 'Right', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_incomplete_or_orphan_columns_are_rejected(): void {
		$one_column = $this->project( [ $this->node( 'mail.columns', [], [ $this->node( 'mail.column' ) ] ) ] );
		self::assertTrue( ( new Validator() )->validate( $one_column )->has_errors() );
		$orphan = $this->project( [ $this->node( 'mail.column' ) ] );
		self::assertTrue( ( new Validator() )->validate( $orphan )->has_errors() );
		$nested = $this->project( [
			$this->node( 'mail.columns', [], [
				$this->node( 'mail.column', [], [
					$this->node( 'mail.columns', [], [
						$this->node( 'mail.column' ),
						$this->node( 'mail.column' ),
					] ),
				] ),
				$this->node( 'mail.column' ),
			] ),
		] );
		self::assertTrue( ( new Validator() )->validate( $nested )->has_errors() );
	}

	public function test_oversized_rich_text_is_rejected_before_rendering(): void {
		$project = $this->project( [
			$this->node( 'mail.rich-text', [ 'markup' => str_repeat( 'x', 100001 ) ] ),
		] );
		self::assertTrue( ( new Validator() )->validate( $project )->has_errors() );
		$this->expectException( \InvalidArgumentException::class );
		RichText::render( str_repeat( 'x', 100001 ) );
	}
}
