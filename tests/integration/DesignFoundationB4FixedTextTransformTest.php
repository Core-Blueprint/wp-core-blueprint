<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Fixed\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\PdfRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\RenderFragment;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\TextStyle;

final class CB_Design_Foundation_B4_Fixed_Text_Transform_Test extends WP_UnitTestCase {

	/** @return array{width:float,height:float} */
	private function page(): array {
		return [ 'width' => 210.0, 'height' => 297.0 ];
	}

	/** @return array{x:float,y:float,width:float,height:float} */
	private function frame(): array {
		return [ 'x' => 10.0, 'y' => 20.0, 'width' => 100.0, 'height' => 30.0 ];
	}

	public function test_uppercase_is_presentation_only_and_preserves_source_text(): void {
		$text = 'École für España';
		$style = new TextStyle(
			'DejaVu Sans',
			12.0,
			400,
			1.25,
			0.08,
			'left',
			'#17191c',
			'wrap',
			'uppercase'
		);

		$html = ( new HtmlRenderer() )->render(
			$this->page(),
			[ RenderFragment::text( $this->frame(), $text, $style ) ],
			'fr_FR'
		);

		self::assertStringContainsString( 'text-transform:uppercase;', $html );
		self::assertStringContainsString( $text, $html );
		self::assertStringNotContainsString( 'ÉCOLE FÜR ESPAÑA', $html );
		self::assertSame( 'uppercase', $style->text_transform() );
	}

	public function test_default_none_keeps_existing_html_contract_unchanged(): void {
		$html = ( new HtmlRenderer() )->render(
			$this->page(),
			[ RenderFragment::text( $this->frame(), 'Default', new TextStyle() ) ],
			'en_GB'
		);

		self::assertStringNotContainsString( 'text-transform:', $html );
	}

	public function test_text_transform_allowlist_fails_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		new TextStyle(
			'DejaVu Sans',
			12.0,
			400,
			1.25,
			0.0,
			'left',
			'#17191c',
			'wrap',
			'uppercase;position:fixed'
		);
	}

	public function test_uppercase_presentation_renders_real_pdf(): void {
		$pdf = ( new PdfRenderer() )->render(
			$this->page(),
			[
				RenderFragment::text(
					$this->frame(),
					'École für España',
					new TextStyle( 'DejaVu Sans', 12.0, 400, 1.25, 0.0, 'left', '#17191c', 'wrap', 'uppercase' )
				),
			],
			'es_ES'
		);

		self::assertStringStartsWith( '%PDF-', $pdf );
	}
}
