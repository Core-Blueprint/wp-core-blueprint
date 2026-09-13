<?php
declare(strict_types=1);

use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CB\Core\Design\Profile\Document\Flow\PdfRenderer;
use CB\Core\Design\Profile\Document\Flow\Presentation;
use CB\Core\Design\Profile\Document\Flow\RenderBlock;

final class CB_Design_Foundation_R7_Flow_Semantic_Blocks_Test extends WP_UnitTestCase {
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
	private function blocks(): array {
		return [
			RenderBlock::callout(
				'Site status <healthy>',
				"Everything is current.\nNo action required <today>.",
				'success',
				[ 'space_after' => 4.0, 'keep_together' => true ]
			),
			RenderBlock::metrics(
				[
					[ 'label' => 'Updates <performed>', 'value' => '12', 'detail' => 'Plugins + theme' ],
					[ 'label' => 'Pending', 'value' => '0' ],
					[ 'label' => 'Backups', 'value' => '4', 'detail' => 'Latest today' ],
					[ 'label' => 'Users', 'value' => '18' ],
					[ 'label' => 'Security', 'value' => '<0>', 'detail' => 'No issues' ],
				],
				[ 'space_after' => 4.0, 'keep_together' => true ]
			),
			RenderBlock::rule( [ 'space_after' => 4.0 ] ),
			RenderBlock::heading( 'Details', 'section' ),
		];
	}

	public function test_semantic_blocks_render_bounded_modern_document_html(): void {
		$html = ( new HtmlRenderer() )->render(
			$this->layout(),
			$this->blocks(),
			'en_GB',
			Presentation::from_accent( '#3455db' )
		);

		self::assertStringContainsString( 'cb-flow-callout cb-flow-callout--success', $html );
		self::assertStringContainsString( 'Site status &lt;healthy&gt;', $html );
		self::assertStringContainsString( 'No action required &lt;today&gt;.', $html );
		self::assertStringContainsString( 'cb-flow-metrics-table', $html );
		self::assertSame( 5, substr_count( $html, 'cb-flow-metric-card__label' ) );
		self::assertStringContainsString( 'Updates &lt;performed&gt;', $html );
		self::assertStringContainsString( '&lt;0&gt;', $html );
		self::assertStringContainsString( 'cb-flow-rule__line', $html );
		self::assertStringContainsString( 'color:#3455db', $html );
		self::assertStringNotContainsString( '<healthy>', $html );
		self::assertStringNotContainsString( '<today>', $html );
	}

	public function test_callout_tone_is_fail_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		RenderBlock::callout( 'Status', 'Unsafe', 'style="display:none"' );
	}

	public function test_callout_requires_a_title(): void {
		$this->expectException( InvalidArgumentException::class );
		RenderBlock::callout( '   ', 'Body' );
	}

	public function test_metrics_reject_unknown_keys(): void {
		$this->expectException( InvalidArgumentException::class );
		RenderBlock::metrics( [ [ 'label' => 'A', 'value' => '1', 'style' => 'display:none' ] ] );
	}

	public function test_metrics_are_bounded_to_eight_items(): void {
		$items = [];
		for ( $index = 0; $index < 9; $index++ ) {
			$items[] = [ 'label' => 'Metric ' . $index, 'value' => (string) $index ];
		}

		$this->expectException( InvalidArgumentException::class );
		RenderBlock::metrics( $items );
	}

	public function test_semantic_blocks_render_real_pdf(): void {
		$pdf = ( new PdfRenderer() )->render(
			$this->layout(),
			$this->blocks(),
			'en_GB',
			Presentation::from_accent( '#3455db' )
		);

		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThan( 1000, strlen( $pdf ) );
	}

	public function test_semantic_flow_api_exposes_no_consumer_style_payload(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Design/Profile/Document/Flow/RenderBlock.php' );
		self::assertStringContainsString( "private const CALLOUT_TONES = [ 'neutral', 'info', 'success', 'warning', 'critical' ];", $source );
		self::assertStringContainsString( 'private const MAX_METRICS = 8;', $source );
		self::assertStringNotContainsString( '$css', $source );
		self::assertStringNotContainsString( '$style', $source );
		self::assertStringNotContainsString( 'Reports', $source );
	}
}
