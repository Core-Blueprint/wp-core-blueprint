<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Flow\Api\FlowRenderApi;
use CoreBlueprint\Core\Design\Profile\Document\Flow\RenderBlock;
use CoreBlueprint\Core\PDF\PageRegionCollector;

final class CB_Design_Foundation_Flow_Page_Regions_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function layout(): array {
		return [
			'mode' => 'flow',
			'units' => 'mm',
			'page' => [ 'width' => 210.0, 'height' => 297.0 ],
			'margins' => [ 'top' => 15.0, 'right' => 18.0, 'bottom' => 15.0, 'left' => 18.0 ],
		];
	}

	public function test_single_pass_pdf_exposes_true_page_boxes_for_separate_blocks(): void {
		if ( ! FlowRenderApi::is_pdf_available() ) {
			self::markTestSkipped( 'Bundled PDF renderer unavailable.' );
		}
		$blocks = [
			RenderBlock::text( 'First physical A4 page.', [ 'break_after' => true ] ),
			RenderBlock::text( 'Second physical A4 page.' ),
		];
		$result = FlowRenderApi::pdf_with_page_regions(
			$this->layout(), $blocks, 'en_GB', null,
			[ 'r0' => [ 0 ], 'r1' => [ 1 ] ]
		);
		self::assertStringStartsWith( '%PDF-', $result['pdf'] );
		self::assertSame( 2, $result['page_count'] );
		self::assertEqualsWithDelta( 595.28, $result['page_width_pt'], 1.0 );
		self::assertEqualsWithDelta( 841.89, $result['page_height_pt'], 1.0 );
		self::assertSame( [ 'r0', 'r1' ], array_keys( $result['regions'] ) );
		self::assertSame( [ 1 ], array_column( $result['regions']['r0'], 'page' ) );
		self::assertSame( [ 2 ], array_column( $result['regions']['r1'], 'page' ) );
		foreach ( $result['regions'] as $boxes ) {
			foreach ( $boxes as $box ) {
				self::assertGreaterThan( 0, $box['width'] );
				self::assertGreaterThan( 0, $box['height'] );
				self::assertGreaterThanOrEqual( 0, $box['x'] );
				self::assertGreaterThanOrEqual( 0, $box['y'] );
				self::assertLessThanOrEqual( $result['page_width_pt'] + 0.01, $box['x'] + $box['width'] );
				self::assertLessThanOrEqual( $result['page_height_pt'] + 0.01, $box['y'] + $box['height'] );
			}
		}
	}

	public function test_split_paragraph_preserves_region_identity_across_physical_pages(): void {
		if ( ! FlowRenderApi::is_pdf_available() ) {
			self::markTestSkipped( 'Bundled PDF renderer unavailable.' );
		}
		$text = str_repeat( 'Semantic report paragraph continues across A4 boundaries. ', 480 );
		$result = FlowRenderApi::pdf_with_page_regions(
			$this->layout(), [ RenderBlock::text( $text ) ], 'en_GB', null,
			[ 'r0' => [ 0 ] ]
		);
		self::assertGreaterThanOrEqual( 2, $result['page_count'] );
		$pages = array_values( array_unique( array_column( $result['regions']['r0'], 'page' ) ) );
		sort( $pages, SORT_NUMERIC );
		self::assertSame( range( 1, $result['page_count'] ), $pages );
	}

	public function test_page_geometry_rejects_invalid_render_paths_before_pdf_execution(): void {
		$this->expectException( InvalidArgumentException::class );
		FlowRenderApi::pdf_with_page_regions(
			$this->layout(), [ RenderBlock::text( 'Only first block exists.' ) ], 'en_GB', null,
			[ 'r0' => [ 1 ] ]
		);
	}

	public function test_collector_rejects_duplicate_or_unsafe_semantic_ids(): void {
		$this->expectException( InvalidArgumentException::class );
		new PageRegionCollector( [ 'r0', 'r0' ], 595.28, 841.89 );
	}

	public function test_existing_pdf_and_continuous_preview_unchanged_by_opt_in_api(): void {
		$blocks = [ RenderBlock::text( 'A sample' ) ];
		$preview = FlowRenderApi::preview_html( $this->layout(), $blocks, 'en_GB' );
		self::assertStringContainsString( 'cb-flow-preview-page', $preview );
		self::assertStringNotContainsString( 'page-break-before:always!important', $preview );
		if ( ! FlowRenderApi::is_pdf_available() ) {
			self::markTestSkipped( 'Bundled PDF renderer unavailable.' );
		}
		self::assertStringStartsWith( '%PDF-', FlowRenderApi::pdf( $this->layout(), $blocks, 'en_GB' ) );
		self::assertSame( 1, FlowRenderApi::pdf_with_page_count( $this->layout(), $blocks, 'en_GB' )['page_count'] );
	}
}
