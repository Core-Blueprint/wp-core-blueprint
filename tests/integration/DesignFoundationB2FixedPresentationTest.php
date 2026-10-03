<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Fixed\BoxStyle;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\PdfRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\RenderFragment;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\TextStyle;
use CoreBlueprint\Core\PDF\Api\PdfApi;

final class CB_Design_Foundation_B2_Fixed_Presentation_Test extends WP_UnitTestCase {

	/** @return array{width:float,height:float} */
	private function page(): array {
		return [ 'width' => 210.0, 'height' => 297.0 ];
	}

	/** @return array{x:float,y:float,width:float,height:float} */
	private function frame(): array {
		return [ 'x' => 20.0, 'y' => 30.0, 'width' => 120.0, 'height' => 20.0 ];
	}

	public function test_styled_text_is_bounded_and_escaped(): void {
		$style = new TextStyle(
			font_family: 'DejaVu Serif',
			font_size_pt: 18.0,
			font_weight: 700,
			line_height: 1.4,
			letter_spacing_em: 0.08,
			alignment: 'center',
			color: '#AABBCC',
			overflow: 'wrap',
		);
		$html = ( new HtmlRenderer() )->render(
			$this->page(),
			[ RenderFragment::text( $this->frame(), '<Certificate & Name>', $style ) ],
			'en_GB'
		);

		self::assertStringContainsString( 'font-family:DejaVu Serif,sans-serif;', $html );
		self::assertStringContainsString( 'font-size:18pt;', $html );
		self::assertStringContainsString( 'font-weight:700;', $html );
		self::assertStringContainsString( 'line-height:1.4;', $html );
		self::assertStringContainsString( 'letter-spacing:0.08em;', $html );
		self::assertStringContainsString( 'text-align:center;', $html );
		self::assertStringContainsString( 'color:#aabbcc;', $html );
		self::assertStringContainsString( 'overflow:visible;', $html );
		self::assertStringContainsString( '&lt;Certificate &amp; Name&gt;', $html );
		self::assertStringNotContainsString( '<Certificate & Name>', $html );
	}

	public function test_clip_mode_overrides_wrap_visibility_within_the_fragment_frame(): void {
		$html = ( new HtmlRenderer() )->render(
			$this->page(),
			[ RenderFragment::text( $this->frame(), 'Clipped', new TextStyle( overflow: 'clip' ) ) ],
			'nl_NL'
		);
		self::assertStringContainsString( 'word-wrap:break-word;overflow:hidden;', $html );
	}

	public function test_box_presentation_is_typed_and_bounded(): void {
		$box = new BoxStyle(
			fill_enabled: true,
			fill_color: '#F0F1F2',
			border_enabled: true,
			border_width_mm: 1.2,
			border_color: '#17191C',
			border_style: 'dashed',
		);
		$html = ( new HtmlRenderer() )->render(
			$this->page(),
			[ RenderFragment::box( $this->frame(), $box ) ],
			'en_GB'
		);

		self::assertStringContainsString( 'cb-fixed-box', $html );
		self::assertStringContainsString( 'background-color:#f0f1f2;', $html );
		self::assertStringContainsString( 'border:1.2mm dashed #17191c;', $html );
	}

	public function test_text_and_box_styles_reject_unbounded_css_values(): void {
		try {
			new TextStyle( font_family: 'DejaVu Sans;url(x)' );
			self::fail( 'Unbounded font family should be rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'font family', $exception->getMessage() );
		}

		try {
			new TextStyle( color: 'red;position:fixed' );
			self::fail( 'Unbounded text color should be rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'color', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		new BoxStyle( border_style: 'double' );
	}

	public function test_styled_text_and_box_render_real_pdf_through_public_pdf_boundary(): void {
		self::assertTrue( PdfApi::is_available() );
		$pdf = ( new PdfRenderer() )->render(
			$this->page(),
			[
				RenderFragment::box(
					[ 'x' => 10.0, 'y' => 10.0, 'width' => 190.0, 'height' => 277.0 ],
					new BoxStyle( border_enabled: true, border_width_mm: 0.5, border_color: '#17191c' )
				),
				RenderFragment::text(
					$this->frame(),
					'Core Blueprint Certificate',
					new TextStyle( font_size_pt: 20.0, font_weight: 700, alignment: 'center' )
				),
			],
			'en_GB'
		);
		self::assertStringStartsWith( '%PDF-', $pdf );
	}
}
