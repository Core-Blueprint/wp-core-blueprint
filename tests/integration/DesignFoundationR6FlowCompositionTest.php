<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Flow\PdfRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Flow\Presentation;
use CoreBlueprint\Core\Design\Profile\Document\Flow\RenderBlock;
use CoreBlueprint\Core\Design\Profile\Document\Flow\TableColumn;

final class CB_Design_Foundation_R6_Flow_Composition_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function layout(): array {
		return [
			'mode'    => 'flow',
			'units'   => 'mm',
			'page'    => [ 'width' => 210.0, 'height' => 297.0 ],
			'margins' => [ 'top' => 12.0, 'right' => 12.0, 'bottom' => 15.0, 'left' => 12.0 ],
		];
	}

	/** @return list<RenderBlock> */
	private function financial_like_blocks(): array {
		return [
			RenderBlock::heading( 'Invoice <2026>', 'title', [ 'space_after' => 5.0 ] ),
			RenderBlock::columns(
				[
					[
						RenderBlock::heading( 'Issuer', 'section', [ 'space_after' => 1.0 ] ),
						RenderBlock::text( "Core Blueprint B.V.\nVAT NL123" ),
					],
					[
						RenderBlock::heading( 'Bill to', 'section', [ 'space_after' => 1.0 ] ),
						RenderBlock::text( "Example <Buyer>\nRotterdam" ),
					],
				],
				[ 3.0, 2.0 ],
				[ 'space_after' => 5.0, 'keep_together' => true ]
			),
			RenderBlock::table(
				[ 'Description', 'Qty', 'Net', 'VAT', 'Total' ],
				[
					[ 'Service <A>', '1', '€ 100.00', '21%', '€ 121.00' ],
					[ 'Support', '2', '€ 40.00', '21%', '€ 96.80' ],
				],
				[ 'space_after' => 5.0 ],
				[
					TableColumn::left( 4.0 ),
					TableColumn::right( 0.8, true ),
					TableColumn::right( 1.4, true ),
					TableColumn::right( 1.0, true ),
					TableColumn::right( 1.5, true ),
				]
			),
			RenderBlock::columns(
				[
					[ RenderBlock::text( 'Reverse charge / VAT notice area.' ) ],
					[
						RenderBlock::table(
							[ '', '' ],
							[ [ 'Subtotal', '€ 180.00' ], [ 'VAT', '€ 37.80' ], [ 'Total', '€ 217.80' ] ],
							[],
							[ TableColumn::left( 2.0 ), TableColumn::right( 1.0, true ) ],
							false
						),
					],
				],
				[ 1.4, 1.0 ]
			),
			RenderBlock::page_footer( 'Immutable financial document · ID: doc_<unsafe>', '', false ),
		];
	}

	public function test_flow_composition_is_bounded_structured_and_escaped(): void {
		$html = ( new HtmlRenderer() )->render(
			$this->layout(),
			$this->financial_like_blocks(),
			'en_GB',
			Presentation::from_accent( '#123abc' )
		);

		self::assertStringContainsString( '<html lang="en-GB">', $html );
		self::assertStringContainsString( '<h1 class="cb-flow-heading--title">Invoice &lt;2026&gt;</h1>', $html );
		self::assertStringContainsString( '<h2 class="cb-flow-heading--section">Issuer</h2>', $html );
		self::assertStringContainsString( 'Example &lt;Buyer&gt;', $html );
		self::assertStringContainsString( 'width:60%', $html );
		self::assertStringContainsString( 'width:40%', $html );
		self::assertStringContainsString( 'text-align:right;white-space:nowrap;', $html );
		self::assertStringContainsString( 'Service &lt;A&gt;', $html );
		self::assertStringContainsString( 'Immutable financial document · ID: doc_&lt;unsafe&gt;', $html );
		self::assertStringNotContainsString( '<td class="cb-flow-page-footer__page">', $html );
		self::assertSame( 1, substr_count( $html, '<thead>' ), 'The headerless totals table must not add a second table header.' );
		self::assertStringNotContainsString( '<Buyer>', $html );
		self::assertStringNotContainsString( 'doc_<unsafe>', $html );
	}

	public function test_legacy_table_call_keeps_r4_r5_render_shape(): void {
		$html = ( new HtmlRenderer() )->render(
			$this->layout(),
			[ RenderBlock::table( [ 'Name', 'Value' ], [ [ 'Core', '1' ] ] ) ],
			'en_GB'
		);

		self::assertStringContainsString( '<table class="cb-flow-table"><thead><tr>', $html );
		self::assertStringNotContainsString( '<colgroup>', $html );
		self::assertStringNotContainsString( '<th style=', $html );
		self::assertStringNotContainsString( '<td style=', $html );
	}

	public function test_heading_roles_fail_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		RenderBlock::heading( 'Unsafe role', 'style="display:none"' );
	}

	public function test_composition_weights_fail_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		RenderBlock::columns(
			[ [ RenderBlock::text( 'A' ) ], [ RenderBlock::text( 'B' ) ] ],
			[ 1.0 ]
		);
	}

	public function test_table_column_weight_fails_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		TableColumn::right( 1000.0 );
	}

	public function test_table_column_count_must_match_table(): void {
		$this->expectException( InvalidArgumentException::class );
		RenderBlock::table(
			[ 'A', 'B' ],
			[ [ '1', '2' ] ],
			[],
			[ TableColumn::left() ]
		);
	}

	public function test_page_footer_requires_label_only_when_counter_is_enabled(): void {
		$without_counter = RenderBlock::page_footer( 'Document ID', '', false );
		self::assertSame( 'page_footer', $without_counter->type() );

		$this->expectException( InvalidArgumentException::class );
		RenderBlock::page_footer( 'Document ID' );
	}

	public function test_composed_flow_document_renders_real_pdf(): void {
		$pdf = ( new PdfRenderer() )->render(
			$this->layout(),
			$this->financial_like_blocks(),
			'en_GB',
			Presentation::from_accent( '#123abc' )
		);

		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThan( 1000, strlen( $pdf ) );
	}

	public function test_r6_flow_runtime_stays_consumer_neutral_and_style_bounded(): void {
		$root = dirname( __DIR__, 2 );
		$flow = $root . '/src/Design/Profile/Document/Flow';
		foreach ( glob( $flow . '/*.php' ) ?: [] as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertStringNotContainsString( 'CommerceEssentials', $source, basename( $file ) );
			self::assertStringNotContainsString( 'Certificates', $source, basename( $file ) );
			self::assertStringNotContainsString( 'CoreBlueprint\\Core\\PDF\\Renderer', $source, basename( $file ) );
		}

		$render_block = (string) file_get_contents( $flow . '/RenderBlock.php' );
		$table_column = (string) file_get_contents( $flow . '/TableColumn.php' );
		self::assertStringNotContainsString( '$css', $render_block );
		self::assertStringNotContainsString( '$style', $render_block );
		self::assertStringNotContainsString( '$css', $table_column );
		self::assertStringNotContainsString( '$style', $table_column );
	}
}
