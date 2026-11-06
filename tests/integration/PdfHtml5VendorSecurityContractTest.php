<?php
declare(strict_types=1);

use CoreBlueprint\Core\PDF\Renderer;

final class CB_Base_Pdf_Html5_Vendor_Security_Contract_Test extends WP_UnitTestCase {
	private const VENDOR = 'src/PDF/lib/dompdf/vendor/';

	public function test_pdf_vendor_reports_exact_patched_html5_release(): void {
		$packages = json_decode(
			(string) file_get_contents( CB_CORE_DIR . self::VENDOR . 'composer/installed.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $packages );
		$entry = null;
		foreach ( $packages['packages'] ?? [] as $package ) {
			if ( 'masterminds/html5' === (string) ( $package['name'] ?? '' ) ) {
				$entry = $package;
			}
		}

		self::assertIsArray( $entry );
		self::assertSame( '2.12.0', $entry['version'] );
		self::assertSame( '15e16663bd6f512352209d0e3a789c0a691c0d9b', $entry['source']['reference'] );
		self::assertSame( '15e16663bd6f512352209d0e3a789c0a691c0d9b', $entry['dist']['reference'] );
		self::assertSame( '*', $entry['require']['ext-mbstring'] );
		self::assertSame( '>=7.4', $entry['require']['php'] );

		$runtime_versions = require CB_CORE_DIR . self::VENDOR . 'composer/installed.php';
		self::assertSame( '2.12.0', $runtime_versions['versions']['masterminds/html5']['pretty_version'] );
		self::assertSame( $entry['source']['reference'], $runtime_versions['versions']['masterminds/html5']['reference'] );
	}

	public function test_pdf_parser_security_relevant_files_match_upstream_git_objects(): void {
		$root = CB_CORE_DIR . self::VENDOR . 'masterminds/html5/';
		$upstream_blobs = [
			'src/HTML5/Parser/DOMTreeBuilder.php' => '0cab86fc1be41a9250e928e95150d25dd8227be7',
			'src/HTML5/Parser/Scanner.php' => '70a03f2bfd0a802b50f6c904e5b6379459e7b310',
			'src/HTML5/Parser/Tokenizer.php' => '17a3052f35209bc1e990f964b5e61f8a3c426a80',
			'src/HTML5/Parser/UTF8Utils.php' => 'c7f688f9d0413b8959d1d3a4fade8cd13464d453',
			'src/HTML5/Serializer/OutputRules.php' => '9c7b63125627283060c97680ffe635711682ef29',
		];

		foreach ( $upstream_blobs as $relative => $expected_blob ) {
			$contents = file_get_contents( $root . $relative );
			self::assertIsString( $contents, $relative );
			self::assertSame(
				$expected_blob,
				sha1( 'blob ' . strlen( $contents ) . "\0" . $contents ),
				$relative . ' does not match the pinned upstream 2.12.0 Git blob.'
			);
		}
		self::assertFileDoesNotExist( $root . 'src/HTML5/Serializer/HTML5Entities.php' );
	}

	public function test_pdf_parser_accepts_basic_html_and_preserves_attribute_text(): void {
		require_once CB_CORE_DIR . self::VENDOR . 'autoload.php';
		$parser = new \Masterminds\HTML5();
		$html = '<!doctype html><html><body><p data-label="safe">Core Blueprint</p></body></html>';
		$document = $parser->loadHTML( $html );
		self::assertInstanceOf( DOMDocument::class, $document );
		$paragraph = $document->getElementsByTagName( 'p' )->item( 0 );
		self::assertInstanceOf( DOMElement::class, $paragraph );
		self::assertSame( 'safe', $paragraph->getAttribute( 'data-label' ) );
		self::assertSame( 'Core Blueprint', $paragraph->textContent );
	}

	public function test_pdf_renderer_still_generates_pdf_with_html5_parser_enabled(): void {
		self::assertTrue( Renderer::is_available() );
		$renderer = new Renderer();
		$pdf = $renderer->render( '<!doctype html><html><body><p>Core Blueprint PDF</p></body></html>' );
		self::assertStringStartsWith( '%PDF-', $pdf );
	}
}
