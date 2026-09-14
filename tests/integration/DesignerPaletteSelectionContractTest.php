<?php
declare(strict_types=1);

final class CB_Designer_Palette_Selection_Contract_Test extends WP_UnitTestCase {

	public function test_palette_item_selected_state_is_base_owned(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/design/designer-composition.css' );

		self::assertStringContainsString( '.cb-core-design-shell__palette-item.is-active,', $css );
		self::assertStringContainsString( '.cb-core-design-shell__palette-item[aria-pressed="true"]', $css );
		self::assertStringContainsString( 'border-color: var(--cb-accent);', $css );
		self::assertStringContainsString( 'background: var(--cb-surface-2);', $css );
	}
}
