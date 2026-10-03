<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Fixed\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\ImageStyle;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\PdfRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\RenderFragment;
use CoreBlueprint\Core\Design\Profile\Document\Render\ImageDataUri;
use CoreBlueprint\Core\Design\Profile\Document\Render\SvgSanitizer;
use CoreBlueprint\Core\PDF\Api\PdfApi;

final class CB_Design_Foundation_B3_Fixed_Image_Presentation_Test extends WP_UnitTestCase {

	private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
	private const WEBP_1PX = 'UklGRh4AAABXRUJQVlA4TBEAAAAvAAAAAAfQ//73v/+BiOh/AAA=';

	/** @return array{width:float,height:float} */
	private function page(): array {
		return [ 'width' => 210.0, 'height' => 297.0 ];
	}

	/** @return array{x:float,y:float,width:float,height:float} */
	private function frame(): array {
		return [ 'x' => 10.0, 'y' => 20.0, 'width' => 100.0, 'height' => 50.0 ];
	}

	private function png_uri(): string {
		return 'data:image/png;base64,' . self::PNG_1PX;
	}

	private function svg_uri( string $svg ): string {
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function test_raster_validation_keeps_png_compatible_and_accepts_real_bounded_webp(): void {
		self::assertSame( $this->png_uri(), ImageDataUri::assert_valid( $this->png_uri() ) );

		$webp = 'data:image/webp;base64,' . self::WEBP_1PX;
		self::assertSame( $webp, ImageDataUri::assert_valid( $webp ) );
	}

	public function test_fake_webp_signature_is_not_enough_to_enter_renderer(): void {
		$this->expectException( InvalidArgumentException::class );
		ImageDataUri::assert_valid( 'data:image/webp;base64,' . base64_encode( "RIFF\x04\x00\x00\x00WEBP" ) );
	}

	public function test_svg_data_uri_is_sanitized_before_rendering(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">'
			. '<defs><linearGradient id="g"><stop offset="0" stop-color="#000000"/></linearGradient></defs>'
			. '<unknown><nested><rect id="kept" x="0" y="0" width="100" height="50" fill="url(#g)" onclick="alert(1)"/>'
			. '<use href="https://example.invalid/remote.svg#shape"/></nested></unknown>'
			. '<script>alert(1)</script></svg>';

		$validated = ImageDataUri::assert_valid( $this->svg_uri( $svg ) );
		self::assertStringStartsWith( 'data:image/svg+xml;base64,', $validated );
		$sanitized = base64_decode( substr( $validated, strlen( 'data:image/svg+xml;base64,' ) ), true );
		self::assertIsString( $sanitized );
		self::assertStringContainsString( 'id="kept"', $sanitized );
		self::assertStringContainsString( 'fill="url(#g)"', $sanitized );
		self::assertStringNotContainsString( '<unknown', $sanitized );
		self::assertStringNotContainsString( '<nested', $sanitized );
		self::assertStringNotContainsString( '<script', strtolower( $sanitized ) );
		self::assertStringNotContainsString( 'onclick=', strtolower( $sanitized ) );
		self::assertStringNotContainsString( 'https://', strtolower( $sanitized ) );
	}

	public function test_svg_declarations_and_external_entity_surface_fail_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		SvgSanitizer::sanitize(
			'<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>'
		);
	}

	public function test_sanitized_svg_still_rejects_foreign_element_namespaces(): void {
		$this->expectException( InvalidArgumentException::class );
		ImageDataUri::assert_valid(
			$this->svg_uri(
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" xmlns:foreign="urn:foreign">'
				. '<foreign:rect x="0" y="0" width="100" height="50" fill="#336699"/></svg>'
			)
		);
	}

	public function test_svg_dimensions_are_bounded_before_pdf_rendering(): void {
		$this->expectException( InvalidArgumentException::class );
		ImageDataUri::assert_valid(
			$this->svg_uri( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20000 100"><rect width="20000" height="100"/></svg>' )
		);
	}

	public function test_contain_cover_and_stretch_are_deterministic_inside_the_bounded_fragment(): void {
		$renderer = new HtmlRenderer();

		$contain = $renderer->render(
			$this->page(),
			[ RenderFragment::image( $this->frame(), $this->png_uri(), new ImageStyle( 1.0, 'contain' ) ) ],
			'en_GB'
		);
		self::assertStringContainsString( 'left:25mm;top:0mm;width:50mm;height:50mm;border:0;', $contain );

		$cover = $renderer->render(
			$this->page(),
			[ RenderFragment::image( $this->frame(), $this->png_uri(), new ImageStyle( 1.0, 'cover' ) ) ],
			'en_GB'
		);
		self::assertStringContainsString( 'left:0mm;top:-25mm;width:100mm;height:100mm;border:0;', $cover );
		self::assertStringContainsString( 'width:100mm;height:50mm;box-sizing:border-box;overflow:hidden;', $cover );

		$stretch = $renderer->render(
			$this->page(),
			[ RenderFragment::image( $this->frame(), $this->png_uri(), new ImageStyle( 1.0, 'stretch' ) ) ],
			'en_GB'
		);
		self::assertStringContainsString( 'left:0mm;top:0mm;width:100mm;height:50mm;border:0;', $stretch );
	}

	public function test_image_style_rejects_unbounded_fit_and_ratio_values(): void {
		try {
			new ImageStyle( 0.0, 'contain' );
			self::fail( 'Zero aspect ratio should fail closed.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'aspect ratio', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		new ImageStyle( 1.0, 'object-fit:url(https://example.invalid)' );
	}

	public function test_sanitized_svg_renders_real_pdf_through_public_pdf_boundary(): void {
		self::assertTrue( PdfApi::is_available() );
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">'
			. '<rect x="0" y="0" width="100" height="50" fill="#336699"/>'
			. '<text x="10" y="30" font-family="DejaVu Sans" font-size="12">CB</text>'
			. '</svg>';
		$asset = ImageDataUri::assert_valid( $this->svg_uri( $svg ) );

		$pdf = ( new PdfRenderer() )->render(
			$this->page(),
			[ RenderFragment::image( $this->frame(), $asset, new ImageStyle( 2.0, 'contain' ) ) ],
			'en_GB'
		);
		self::assertStringStartsWith( '%PDF-', $pdf );
	}
}
