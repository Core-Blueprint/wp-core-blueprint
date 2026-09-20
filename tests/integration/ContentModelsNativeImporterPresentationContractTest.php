<?php
declare(strict_types=1);

final class ContentModelsNativeImporterPresentationContractTest extends WP_UnitTestCase {

	private function source( string $path ): string {
		$source = file_get_contents( CB_CORE_DIR . $path );
		self::assertIsString( $source, $path );
		return $source;
	}

	public function test_content_models_action_alignment_is_scoped_to_explicit_action_columns(): void {
		$css = $this->source( 'assets/css/pages/content-models.css' );

		self::assertStringNotContainsString( '.widefat th:last-child', $css );
		self::assertStringNotContainsString( '.widefat td:last-child', $css );
		self::assertStringContainsString(
			".cb-content-models-wrap .cb-content-models-col-actions {\n\ttext-align: right;",
			$css
		);
	}

	public function test_native_importer_reuses_core_admin_composition_primitives(): void {
		$source = $this->source( 'src/ContentModels/Importers/NativeWordPress/Bootstrap.php' );

		self::assertStringContainsString( 'use CB\\Core\\UI\\Field;', $source );
		self::assertStringContainsString( 'use CB\\Core\\UI\\Status;', $source );
		self::assertStringContainsString( 'Field::render(', $source );
		self::assertStringContainsString( 'Status::render(', $source );
		self::assertStringContainsString( 'cb-core-stack cb-core-stack--loose', $source );
		self::assertStringContainsString( 'cb-core-stack cb-core-stack--compact', $source );
		self::assertStringNotContainsString( '<p><label><strong><?php esc_html_e( \'Field Group title\'', $source );
	}
}
