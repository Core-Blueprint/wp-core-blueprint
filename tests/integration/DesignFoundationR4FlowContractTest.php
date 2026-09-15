<?php
declare(strict_types=1);

use CB\Core\Design\Kernel\DesignProject;
use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CB\Core\Design\Profile\Document\Flow\PdfRenderer;
use CB\Core\Design\Profile\Document\Flow\RenderBlock;
use CB\Core\Design\Profile\Document\Flow\Validator;

final class CB_Design_Foundation_R4_Flow_Contract_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function layout(): array {
		return [ 'mode' => 'flow', 'units' => 'mm', 'page' => [ 'width' => 210.0, 'height' => 297.0 ], 'margins' => [ 'top' => 12.0, 'right' => 12.0, 'bottom' => 15.0, 'left' => 12.0 ] ];
	}

	/** @return array<string,mixed> */
	private function node( string $type, array $properties = [], array $children = [] ): array {
		return [ 'type' => $type, 'provider' => 'fixture.flow', 'properties' => $properties, 'children' => $children ];
	}

	private function project( array $root ): DesignProject { return new DesignProject( 0, 'fixture.flow.document', $root ); }

	/** @return array<string,mixed> */
	private function root(): array {
		return $this->node( 'document', [ 'layout' => $this->layout() ], [ $this->node( 'text' ), $this->node( 'container', [ 'flow' => [ 'space_after' => 4.0, 'keep_together' => true ] ], [ $this->node( 'text' ) ] ), $this->node( 'table' ) ] );
	}

	public function test_flow_accepts_ordered_document_with_table_node(): void {
		$diagnostics = ( new Validator() )->validate( $this->project( $this->root() ) );
		self::assertFalse( $diagnostics->has_errors(), wp_json_encode( $diagnostics->to_array() ) );
	}

	public function test_flow_rejects_fixed_frames_and_nested_layout_contexts(): void {
		$root = $this->root();
		$root['children'][0]['properties']['frame'] = [ 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10 ];
		$root['children'][1]['properties']['layout'] = $this->layout();
		$codes = array_column( ( new Validator() )->validate( $this->project( $root ) )->to_array(), 'code' );
		self::assertContains( 'flow.frame_forbidden', $codes );
		self::assertContains( 'flow.nested_layout_forbidden', $codes );
	}

	public function test_flow_requires_positive_content_area_and_typed_hints(): void {
		$root = $this->root();
		$root['properties']['layout']['margins']['left'] = 105.0;
		$root['properties']['layout']['margins']['right'] = 105.0;
		$root['children'][0]['properties']['flow'] = [ 'break_before' => 1 ];
		$codes = array_column( ( new Validator() )->validate( $this->project( $root ) )->to_array(), 'code' );
		self::assertContains( 'flow.content_area_invalid', $codes );
		self::assertContains( 'flow.hints_invalid', $codes );
	}

	public function test_flow_html_uses_typed_blocks_and_escapes_table_content(): void {
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=';
		$blocks = [ RenderBlock::text( '<unsafe>' ), RenderBlock::container( [ RenderBlock::image( $png ) ], [ 'keep_together' => true ] ), RenderBlock::table( [ 'Name', 'Value' ], [ [ 'Core', '<1>' ], [ 'PHP', '8.4+' ] ] ) ];
		$html = ( new HtmlRenderer() )->render( $this->layout(), $blocks, 'en_GB' );
		self::assertStringContainsString( '<html lang="en-GB">', $html );
		self::assertStringContainsString( '&lt;unsafe&gt;', $html );
		self::assertStringContainsString( '<table class="cb-flow-table">', $html );
		self::assertStringContainsString( '&lt;1&gt;', $html );
		self::assertStringNotContainsString( '<unsafe>', $html );
	}

	public function test_flow_page_margins_remain_owned_by_page_root(): void {
		$html = ( new HtmlRenderer() )->render( $this->layout(), [ RenderBlock::text( 'Margin proof' ) ], 'en_GB' );
		self::assertStringContainsString( '@page{size:210mm 297mm;margin:12mm 12mm 15mm 12mm;}', $html );
		self::assertStringContainsString( 'html{padding:0;}body{margin:0;padding:0;}', $html );
		self::assertStringNotContainsString( 'html,body{margin:0', $html );
		self::assertStringNotContainsString( 'html{margin:0', $html );
	}

	public function test_flow_render_boundary_rejects_unknown_layout_keys(): void {
		$layout = $this->layout();
		$layout['paper'] = 'A4';
		$this->expectException( InvalidArgumentException::class );
		( new HtmlRenderer() )->render( $layout, [ RenderBlock::text( 'test' ) ], 'en_GB' );
	}

	public function test_flow_render_requires_explicit_valid_locale(): void {
		$this->expectException( InvalidArgumentException::class );
		( new HtmlRenderer() )->render( $this->layout(), [ RenderBlock::text( 'test' ) ], '' );
	}

	public function test_flow_pdf_pagination_is_server_authoritative(): void {
		$blocks = [ RenderBlock::text( 'Page one', [ 'break_after' => true ] ), RenderBlock::text( 'Page two' ) ];
		$pdf = ( new PdfRenderer() )->render( $this->layout(), $blocks, 'en_GB' );
		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThanOrEqual( 2, preg_match_all( '/\/Type\s*\/Page\b/', $pdf ) );
	}

	public function test_flow_and_shared_document_render_code_do_not_depend_on_internal_pdf_backend(): void {
		$flow = dirname( __DIR__, 2 ) . '/src/Design/Profile/Document/Flow';
		foreach ( glob( $flow . '/*.php' ) ?: [] as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertDoesNotMatchRegularExpression( '/\\\\Dompdf\\\\|CB\\\\Core\\\\PDF\\\\Renderer\b/', $source, basename( $file ) );
		}
		$shared = dirname( __DIR__, 2 ) . '/src/Design/Profile/Document/Render';
		foreach ( glob( $shared . '/*.php' ) ?: [] as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertDoesNotMatchRegularExpression( '/\\\\Dompdf\\\\|CB\\\\Core\\\\PDF\\\\Renderer\b|PdfApi/', $source, basename( $file ) );
		}
	}
}
