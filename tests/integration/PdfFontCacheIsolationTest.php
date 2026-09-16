<?php
declare(strict_types=1);

use CB\Core\PDF\Renderer;

final class CB_Pdf_Font_Cache_Isolation_Test extends WP_UnitTestCase {

	private function font_manifest(): array {
		$root = CB_CORE_DIR . 'src/PDF/lib/dompdf/vendor/dompdf/dompdf/lib/fonts';
		$manifest = [];
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() ) {
				$manifest[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
			}
		}
		ksort( $manifest );
		return $manifest;
	}

	public function test_real_pdf_rendering_preserves_bundled_fonts_and_cleans_temporary_cache(): void {
		self::assertTrue( Renderer::is_available() );
		$before = $this->font_manifest();
		$cache_pattern = rtrim( get_temp_dir(), "/\\" ) . '/cb-pdf-fonts-*';
		$existing_caches = glob( $cache_pattern ) ?: [];
		$renderer = new Renderer();

		// Exercise the reported core-font cache path twice, including bold/italic.
		for ( $render = 0; $render < 2; ++$render ) {
			$pdf = $renderer->render(
				'<html><body style="font-family:Helvetica">Font cache <b>bold</b> <i>italic</i></body></html>',
				[ 'default_font' => 'Helvetica' ]
			);
			self::assertStringStartsWith( '%PDF-', $pdf );
			self::assertSame( $before, $this->font_manifest(), 'Rendering must not modify bundled fonts.' );
			self::assertSame( $existing_caches, glob( $cache_pattern ) ?: [], 'Render cache must be removed.' );
		}
	}
}
