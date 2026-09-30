<?php
declare(strict_types=1);

use CB\Core\MediaFormats\Environment;
use CB\Core\MediaFormats\Svg\Sanitizer as SvgSanitizer;

final class CB_Base_Media_Formats_Svg_Sanitizer_Security_Contract_Test extends WP_UnitTestCase {

	public function test_vendored_svg_sanitizer_is_security_fixed_release(): void {
		self::assertSame( '1.0.0', SvgSanitizer::VERSION );
	}

	public function test_dtd_entity_href_bypass_is_rejected(): void {
		if ( ! Environment::svg_supported() ) {
			self::markTestSkipped( 'SVG runtime is unavailable in this test environment.' );
		}

		$file = wp_tempnam( 'cb-svg-dtd-entity.svg' );
		self::assertIsString( $file );
		$dirty = <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE svg [
  <!ENTITY Tab "#">
]>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
  <a xlink:href="&Tab;javascript:alert(1)"><text x="10" y="20">click</text></a>
</svg>
SVG;
		self::assertNotFalse( file_put_contents( $file, $dirty ) );

		try {
			$result = SvgSanitizer::sanitize_file( $file );
			self::assertWPError( $result );
			self::assertSame( 'cb_media_formats_svg_invalid', $result->get_error_code() );
		} finally {
			@unlink( $file );
		}
	}

	public function test_dtd_default_attribute_is_removed_before_svg_parsing(): void {
		if ( ! Environment::svg_supported() ) {
			self::markTestSkipped( 'SVG runtime is unavailable in this test environment.' );
		}

		$file = wp_tempnam( 'cb-svg-dtd-attlist.svg' );
		self::assertIsString( $file );
		$dirty = <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE svg [
  <!ATTLIST svg badhref CDATA #FIXED "javascript:alert(1)">
]>
<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>
SVG;
		self::assertNotFalse( file_put_contents( $file, $dirty ) );

		try {
			self::assertTrue( SvgSanitizer::sanitize_file( $file ) );
			$clean = file_get_contents( $file );
			self::assertIsString( $clean );
			self::assertStringNotContainsString( '<!DOCTYPE', $clean );
			self::assertStringNotContainsString( '<!ATTLIST', $clean );
			self::assertStringNotContainsString( 'badhref', $clean );
			self::assertStringNotContainsString( 'javascript:', $clean );
		} finally {
			@unlink( $file );
		}
	}

	public function test_mixed_case_use_href_cannot_hide_recursive_reference_graph(): void {
		if ( ! Environment::svg_supported() ) {
			self::markTestSkipped( 'SVG runtime is unavailable in this test environment.' );
		}

		$file = wp_tempnam( 'cb-svg-use-loop.svg' );
		self::assertIsString( $file );
		$dirty = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg">
  <g id="ping"><use HrEf="#pong" /></g>
  <g id="pong"><use HREF="#ping" /></g>
</svg>
SVG;
		self::assertNotFalse( file_put_contents( $file, $dirty ) );

		try {
			self::assertTrue( SvgSanitizer::sanitize_file( $file ) );
			$clean = file_get_contents( $file );
			self::assertIsString( $clean );
			self::assertSame( 0, preg_match( '/<use\b/i', $clean ) );
		} finally {
			@unlink( $file );
		}
	}

	public function test_remote_css_references_are_removed(): void {
		if ( ! Environment::svg_supported() ) {
			self::markTestSkipped( 'SVG runtime is unavailable in this test environment.' );
		}

		$file = wp_tempnam( 'cb-svg-remote-css.svg' );
		self::assertIsString( $file );
		$dirty = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg">
  <style>@\69 mport url(https://example.invalid/remote.css); rect { fill: \75 rl(//example.invalid/fill.svg#paint); }</style>
  <rect width="10" height="10" style="stroke: image-set('https://example.invalid/stroke.png' 1x);" />
</svg>
SVG;
		self::assertNotFalse( file_put_contents( $file, $dirty ) );

		try {
			self::assertTrue( SvgSanitizer::sanitize_file( $file ) );
			$clean = file_get_contents( $file );
			self::assertIsString( $clean );
			self::assertStringNotContainsString( 'example.invalid', $clean );
			self::assertStringNotContainsString( '@import', strtolower( $clean ) );
		} finally {
			@unlink( $file );
		}
	}

}
