<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Flow\Api\FlowRenderApi;
use CoreBlueprint\Core\Design\Profile\Document\Flow\Presentation;
use CoreBlueprint\Core\Design\Profile\Document\Flow\RenderBlock;

final class CB_Design_Foundation_Flow_Render_Public_Api_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function layout(): array {
		return [
			'mode'    => 'flow',
			'units'   => 'mm',
			'page'    => [ 'width' => 210.0, 'height' => 297.0 ],
			'margins' => [ 'top' => 12.0, 'right' => 13.0, 'bottom' => 14.0, 'left' => 15.0 ],
		];
	}

	/** @return list<RenderBlock> */
	private function blocks(): array {
		return [
			RenderBlock::heading( 'Invoice', 'title' ),
			RenderBlock::text( '<unsafe> & literal' ),
			RenderBlock::table( [ 'Item', 'Value' ], [ [ 'Service', 'EUR 100.00' ] ] ),
			RenderBlock::page_footer( 'Immutable integrity line', 'Page', true ),
		];
	}

	public function test_public_facade_exposes_preview_and_pdf_targets(): void {
		self::assertTrue( class_exists( FlowRenderApi::class ) );
		self::assertTrue( method_exists( FlowRenderApi::class, 'preview_html' ) );
		self::assertTrue( method_exists( FlowRenderApi::class, 'pdf' ) );
		self::assertTrue( method_exists( FlowRenderApi::class, 'pdf_with_page_count' ) );
		self::assertSame( 1, \CoreBlueprint\Core\Design\Editor\Assets::FLOW_PAGED_PAGE_COUNT_API );
		self::assertTrue( method_exists( FlowRenderApi::class, 'is_pdf_available' ) );
	}

	public function test_preview_is_continuous_safe_document_using_flow_margins(): void {
		$html = FlowRenderApi::preview_html(
			$this->layout(),
			$this->blocks(),
			'en_GB',
			Presentation::from_accent( '#0ea5e9' )
		);

		self::assertStringStartsWith( '<!doctype html>', $html );
		self::assertMatchesRegularExpression( '/<html\b[^>]*\blang="en-GB"[^>]*>/', $html );
		self::assertStringContainsString( 'http-equiv="Content-Security-Policy"', $html );
		self::assertStringContainsString( 'default-src &#39;none&#39;', $html );
		self::assertStringContainsString( 'img-src data:', $html );
		self::assertStringContainsString( 'style-src &#39;unsafe-inline&#39;', $html );
		self::assertStringContainsString( 'data-cb-flow-preview', $html );
		self::assertStringContainsString( 'max-width:210mm;min-height:297mm', $html );
		self::assertStringContainsString( 'padding:12mm 13mm 14mm 15mm', $html );
		self::assertStringContainsString( 'color:#0ea5e9', $html );
		self::assertStringContainsString( '&lt;unsafe&gt; &amp; literal', $html );
		self::assertStringContainsString( 'position:static', $html );
		self::assertStringContainsString( '.cb-flow-page-footer__page{display:none;}', $html );
		self::assertStringNotContainsString( 'position:fixed', $html );
		self::assertStringNotContainsString( 'counter(page)', $html );
		// The canonical preview now includes one static, CSP-hashed sizing bridge.
		self::assertSame( 1, substr_count( strtolower( $html ), '<script' ) );
		self::assertSame( 1, preg_match( '/<script data-cb-core-flow-preview-sizing="1">(.*?)<\/script>/s', $html, $bridge ) );
		$hash = base64_encode( hash( 'sha256', $bridge[1], true ) );
		self::assertStringContainsString( "script-src &#39;sha256-{$hash}&#39;", $html );
		self::assertStringNotContainsString( "script-src &#39;unsafe-inline&#39;", $html );
		self::assertStringNotContainsString( '<form', strtolower( $html ) );
	}

	public function test_preview_rejects_invalid_layout_at_public_boundary(): void {
		$layout = $this->layout();
		$layout['paper'] = 'A4';

		$this->expectException( InvalidArgumentException::class );
		FlowRenderApi::preview_html( $layout, [ RenderBlock::text( 'test' ) ], 'en_GB' );
	}

	public function test_preview_rejects_invalid_locale_at_public_boundary(): void {
		$this->expectException( InvalidArgumentException::class );
		FlowRenderApi::preview_html( $this->layout(), [ RenderBlock::text( 'test' ) ], '' );
	}

	public function test_pdf_target_remains_authoritative_paged_output(): void {
		if ( ! FlowRenderApi::is_pdf_available() ) {
			self::markTestSkipped( 'Bundled PDF backend is not available in this runtime.' );
		}

		$pdf = FlowRenderApi::pdf(
			$this->layout(),
			[ RenderBlock::text( 'Page one', [ 'break_after' => true ] ), RenderBlock::text( 'Page two' ) ],
			'en_GB'
		);

		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThanOrEqual( 2, preg_match_all( '/\/Type\s*\/Page\b/', $pdf ) );
	}

	public function test_authoritative_pdf_count_has_one_or_more_actual_pages(): void {
		if ( ! FlowRenderApi::is_pdf_available() ) {
			self::markTestSkipped( 'Bundled PDF backend is not available in this runtime.' );
		}
		$single = FlowRenderApi::pdf_with_page_count(
			$this->layout(), [ RenderBlock::text( 'Single A4 page' ) ], 'en_GB'
		);
		self::assertStringStartsWith( '%PDF-', $single['pdf'] );
		self::assertSame( 1, $single['page_count'] );

		$broken = FlowRenderApi::pdf_with_page_count(
			$this->layout(),
			[ RenderBlock::text( 'First page', [ 'break_after' => true ] ), RenderBlock::text( 'Second page' ) ],
			'en_GB'
		);
		self::assertStringStartsWith( '%PDF-', $broken['pdf'] );
		self::assertGreaterThanOrEqual( 2, $broken['page_count'] );
		// Byte-level page markers are a secondary regression assertion only.
		// The implementation obtains truth from the rendered Dompdf Canvas.
		self::assertSame( $broken['page_count'], preg_match_all( '/\/Type\s*\/Page\b/', $broken['pdf'] ) );
	}

	public function test_authoritative_pdf_count_handles_natural_a4_overflow(): void {
		if ( ! FlowRenderApi::is_pdf_available() ) {
			self::markTestSkipped( 'Bundled PDF backend is not available in this runtime.' );
		}
		$long = array_fill( 0, 48, RenderBlock::text( str_repeat( 'Natural A4 overflow paragraph. ', 5 ) ) );
		$pages = FlowRenderApi::pdf_with_page_count( $this->layout(), $long, 'en_GB' );
		self::assertStringStartsWith( '%PDF-', $pages['pdf'] );
		self::assertGreaterThanOrEqual( 2, $pages['page_count'] );
	}

	public function test_public_docs_keep_internal_renderers_outside_consumer_contract(): void {
		$root = dirname( __DIR__, 2 );
		$docs = (string) file_get_contents( $root . '/docs/DOCUMENT-FLOW-RENDERING.md' );
		$foundation = (string) file_get_contents( $root . '/docs/foundation-v1-contract.md' );

		self::assertStringContainsString( 'FlowRenderApi::preview_html', $docs );
		self::assertStringContainsString( 'FlowRenderApi::pdf', $docs );
		self::assertStringContainsString( 'are **not** public consumer APIs', $docs );
		self::assertStringContainsString( 'DOCUMENT-FLOW-RENDERING.md', $foundation );
		self::assertStringContainsString( 'Consumers must not depend on `HtmlRenderer`, `PdfRenderer`', $foundation );
	}
}
