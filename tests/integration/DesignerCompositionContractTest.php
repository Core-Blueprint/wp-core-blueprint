<?php
declare(strict_types=1);

final class CB_Designer_Composition_Contract_Test extends WP_UnitTestCase {

	private function source( string $relative_path ): string {
		$path = CB_CORE_DIR . $relative_path;
		self::assertFileExists( $path );
		$source = file_get_contents( $path );
		self::assertIsString( $source );
		return $source;
	}

	public function test_designer_mode_owns_the_canonical_composition_asset(): void {
		$assets = $this->source( 'src/Design/Editor/Assets.php' );

		self::assertStringContainsString( "public const DESIGNER_COMPOSITION_STYLE = 'cb-core-designer-composition';", $assets );
		self::assertStringContainsString( "CB_CORE_URL . 'assets/css/design/designer-composition.css'", $assets );
		self::assertStringContainsString( '[ self::DESIGNER_MODE_STYLE ]', $assets );
	}

	public function test_composition_layer_exposes_the_canonical_canvas_grammar(): void {
		$css = $this->source( 'assets/css/design/designer-composition.css' );

		foreach ( [
			'.cb-core-design-shell__canvas--composed',
			'.cb-core-design-shell__canvas-header',
			'.cb-core-design-shell__canvas-heading',
			'.cb-core-design-shell__canvas-title',
			'.cb-core-design-shell__canvas-description',
			'.cb-core-design-shell__canvas-actions',
			'.cb-core-design-shell__canvas-workarea',
			'.cb-core-design-shell__surface',
			'.cb-core-design-shell__surface--document',
			'.cb-core-design-shell__empty-state',
			'.cb-core-design-shell__composition-stack',
		] as $selector ) {
			self::assertStringContainsString( $selector, $css );
		}
	}

	public function test_composition_layer_exposes_the_canonical_panel_grammar(): void {
		$css = $this->source( 'assets/css/design/designer-composition.css' );

		foreach ( [
			'.cb-core-design-shell__palette--composed',
			'.cb-core-design-shell__sidebar--composed',
			'.cb-core-design-shell__panel-body',
			'.cb-core-design-shell__panel-section',
			'.cb-core-design-shell__panel-section-title',
			'.cb-core-design-shell__panel-section-description',
			'.cb-core-design-shell__field',
			'.cb-core-design-shell__field-label',
			'.cb-core-design-shell__field-hint',
			'.cb-core-design-shell__panel-actions',
			'.cb-core-design-shell__palette-grid',
			'.cb-core-design-shell__palette-item',
		] as $selector ) {
			self::assertStringContainsString( $selector, $css );
		}
	}

	public function test_panel_grammar_is_product_neutral(): void {
		$css = $this->source( 'assets/css/design/designer-composition.css' );

		self::assertStringNotContainsString( 'cb-contracts', $css );
		self::assertStringNotContainsString( 'cb-commerce', $css );
		self::assertStringNotContainsString( 'cb-certificates', $css );
		self::assertStringNotContainsString( 'cb-automations', $css );
		self::assertStringNotContainsString( 'cb-mail', $css );
	}

	public function test_manual_launch_rhythm_is_base_owned_without_core_admin_scope_hacks(): void {
		$css = $this->source( 'assets/css/design/designer-composition.css' );

		self::assertStringContainsString( '.cb-core-design-launch-wrap', $css );
		self::assertStringContainsString( 'margin-top: var(--cb-space-3);', $css );
		self::assertStringNotContainsString( '.cb-core-wrap', $css );
	}

	public function test_document_surface_customization_is_limited_to_public_variables(): void {
		$css = $this->source( 'assets/css/design/designer-composition.css' );

		self::assertStringContainsString( '--cb-design-document-width', $css );
		self::assertStringContainsString( '--cb-design-document-min-height', $css );
		self::assertStringContainsString( '--cb-design-document-padding', $css );
	}

	public function test_public_designer_contract_documents_composition_ownership(): void {
		$docs = $this->source( 'docs/DESIGNER-MODE.md' );

		self::assertStringContainsString( '## Canonical composition grammar', $docs );
		self::assertStringContainsString( '### Canonical panel composition', $docs );
		self::assertStringContainsString( 'Base owns Designer Mode. Consumers own what is being designed.', $docs );
		self::assertStringContainsString( 'A viewport switcher is **not** mandatory merely because Mail uses one', $docs );
		self::assertStringContainsString( 'must not restyle the canonical rail locally', $docs );
		self::assertStringContainsString( 'Consumers must not redefine panel padding, section dividers, field rhythm or palette item presentation locally.', $docs );
	}
}
