<?php
declare(strict_types=1);

final class CB_Design_Foundation_Kernel_Boundary_Test extends WP_UnitTestCase {
	public function test_php_kernel_has_no_document_or_pdf_dependencies(): void {
		$directory = CB_CORE_DIR . 'src/Design/Kernel';
		self::assertDirectoryExists( $directory );
		$files = glob( $directory . '/*.php' ) ?: [];
		self::assertNotEmpty( $files );

		$forbidden = [
			'Dompdf',
			'PdfApi',
			'CoreBlueprint\\Core\\PDF',
			'Profile\\Document',
			'pagination',
			'paper_size',
			'setPaper',
		];

		foreach ( $files as $file ) {
			$source = file_get_contents( $file );
			self::assertIsString( $source );
			foreach ( $forbidden as $fragment ) {
				self::assertStringNotContainsString( $fragment, $source, basename( $file ) . ' depends on a Document/PDF concern.' );
			}
		}
	}

	public function test_no_generic_profile_framework_was_introduced(): void {
		$design_root = CB_CORE_DIR . 'src/Design';
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $design_root, FilesystemIterator::SKIP_DOTS )
		);
		$forbidden_names = [ 'ProfileInterface.php', 'ProfileRegistry.php', 'GenericLayoutProvider.php' ];
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				self::assertNotContains( $file->getFilename(), $forbidden_names );
			}
		}
	}
}
