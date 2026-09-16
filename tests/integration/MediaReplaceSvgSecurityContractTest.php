<?php
declare(strict_types=1);

use CB\Core\MediaFormats\Environment;
use CB\Core\MediaFormats\Svg\Sanitizer as SvgSanitizer;

final class CB_Base_Media_Replace_Svg_Security_Contract_Test extends WP_UnitTestCase {

	public function test_svg_direct_links_are_removed_while_self_contained_references_survive(): void {
		if ( ! Environment::svg_supported() ) {
			self::markTestSkipped( 'SVG runtime is unavailable in this test environment.' );
		}

		$file = wp_tempnam( 'cb-svg-links.svg' );
		self::assertIsString( $file );
		try {
			foreach ( [ 'href', 'xlink:href' ] as $attribute ) {
				foreach ( [ 'https://example.com/a.png', 'http://example.com/a.png', '//example.com/a.png', '/a.png' ] as $url ) {
					$xml = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image ' . $attribute . '="' . $url . '" width="10" height="10" /></svg>';
					self::assertNotFalse( file_put_contents( $file, $xml ) );
					self::assertTrue( SvgSanitizer::sanitize_file( $file ) );
					$doc = new DOMDocument();
					self::assertTrue( $doc->load( $file, LIBXML_NONET ) );
					$image = $doc->getElementsByTagName( 'image' )->item( 0 );
					self::assertInstanceOf( DOMElement::class, $image );
					self::assertFalse( $image->hasAttribute( $attribute ), $attribute . ': ' . $url );
				}
			}

			$xml = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="paint"><stop offset="0" stop-color="red" /></linearGradient></defs><rect width="10" height="10" fill="url(#paint)" /><image href="#local" /><image href="data:image/png;base64,iVBORw0KGgo=" /></svg>';
			self::assertNotFalse( file_put_contents( $file, $xml ) );
			self::assertTrue( SvgSanitizer::sanitize_file( $file ) );
			$clean = file_get_contents( $file );
			self::assertIsString( $clean );
			self::assertStringContainsString( 'url(#paint)', $clean );
			self::assertStringContainsString( 'href="#local"', $clean );
			self::assertStringContainsString( 'data:image/png;base64,iVBORw0KGgo=', $clean );
		} finally {
			@unlink( $file );
		}
	}

	public function test_replace_service_sanitizes_svg_before_staging_and_revalidates_type(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/MediaReplace/ReplaceService.php' );
		self::assertIsString( $source );

		$replace_start  = strpos( $source, 'public function replace' );
		$validate_start = strpos( $source, 'private function validate_upload' );
		$next_method    = strpos( $source, 'private function assert_local_upload_path', (int) $validate_start );
		self::assertIsInt( $replace_start );
		self::assertIsInt( $validate_start );
		self::assertIsInt( $next_method );

		$replace_section = substr( $source, $replace_start, $validate_start - $replace_start );
		$validate_section = substr( $source, $validate_start, $next_method - $validate_start );
		self::assertIsString( $replace_section );
		self::assertIsString( $validate_section );

		$validation_call = strpos( $replace_section, '$this->validate_upload' );
		$stage_call      = strpos( $replace_section, '$this->stage_upload' );
		self::assertIsInt( $validation_call );
		self::assertIsInt( $stage_call );
		self::assertLessThan( $stage_call, $validation_call );

		self::assertStringContainsString( 'SvgSanitizer::sanitize_file( $tmp_name )', $validate_section );
		self::assertStringContainsString( "'svg_sanitize_failed'", $validate_section );
		self::assertStringContainsString( "'svg_type_changed'", $validate_section );
		self::assertGreaterThanOrEqual( 2, substr_count( $validate_section, 'wp_check_filetype_and_ext' ) );
	}

	public function test_svg_sanitizer_removes_active_and_remote_content_used_by_replacement_guard(): void {
		if ( ! Environment::svg_supported() ) {
			self::markTestSkipped( 'SVG runtime is unavailable in this test environment.' );
		}

		$file = wp_tempnam( 'cb-media-replace-security.svg' );
		self::assertIsString( $file );
		$dirty = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><image href="https://example.com/tracker.png" width="10" height="10" /></svg>';
		self::assertNotFalse( file_put_contents( $file, $dirty ) );

		try {
			self::assertTrue( SvgSanitizer::sanitize_file( $file ) );
			$clean = file_get_contents( $file );
			self::assertIsString( $clean );
			self::assertStringNotContainsString( '<script', strtolower( $clean ) );
			self::assertStringNotContainsString( 'onload=', strtolower( $clean ) );
			self::assertStringNotContainsString( 'example.com', strtolower( $clean ) );
		} finally {
			@unlink( $file );
		}
	}
}
