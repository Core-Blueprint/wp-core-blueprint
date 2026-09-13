<?php
declare(strict_types=1);

use CB\Core\Design\Editor\Assets as DesignEditorAssets;

final class CB_Designer_External_Consumer_Contract_Test extends WP_UnitTestCase {

	public function test_external_fixture_does_not_depend_on_core_admin_wrapper(): void {
		$root    = dirname( __DIR__, 2 );
		$fixture = (string) file_get_contents( $root . '/tests/fixtures/designer-external-consumer.html' );

		self::assertStringContainsString( 'data-cb-design-launch-root', $fixture );
		self::assertStringContainsString( 'class="cb-core-design-shell"', $fixture );
		self::assertStringContainsString( 'cb-core-button--primary', $fixture );
		self::assertStringContainsString( 'data-cb-design-shell-sidebar-role="inspector"', $fixture );
		self::assertStringContainsString( 'data-cb-design-shell-sidebar-role="layers"', $fixture );
		self::assertStringContainsString( 'data-cb-design-shell-sidebar-role="settings"', $fixture );
		self::assertStringNotContainsString( 'cb-core-wrap', $fixture );
	}

	public function test_designer_mode_enqueues_the_narrow_button_presentation_it_owns(): void {
		DesignEditorAssets::enqueue_designer_mode( 'External Designer' );

		self::assertTrue( wp_style_is( DesignEditorAssets::SHELL_STYLE, 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-buttons', 'enqueued' ) );
		self::assertTrue( wp_style_is( DesignEditorAssets::DESIGNER_MODE_STYLE, 'enqueued' ) );

		$styles   = wp_styles();
		$designer = $styles->registered[ DesignEditorAssets::DESIGNER_MODE_STYLE ] ?? null;

		self::assertInstanceOf( _WP_Dependency::class, $designer );
		self::assertContains( DesignEditorAssets::SHELL_STYLE, $designer->deps );
		self::assertContains( 'cb-core-css-buttons', $designer->deps );
	}

	public function test_designer_button_scope_does_not_promote_arbitrary_wordpress_buttons(): void {
		$root    = dirname( __DIR__, 2 );
		$buttons = (string) file_get_contents( $root . '/assets/css/components/buttons.css' );

		self::assertStringContainsString(
			'[data-cb-design-launch-root] .cb-core-design-launch.cb-core-button--primary',
			$buttons
		);
		self::assertStringContainsString(
			'[data-cb-design-launch-root] .cb-core-design-shell .button.cb-core-button--primary',
			$buttons
		);
		self::assertStringContainsString(
			'[data-cb-design-launch-root] .cb-core-design-shell .button.cb-core-button:not(',
			$buttons
		);
		self::assertStringNotContainsString(
			'[data-cb-design-launch-root] .button.button-primary',
			$buttons
		);
	}

	public function test_collapsible_shell_keeps_the_canonical_three_two_one_geometry(): void {
		$root          = dirname( __DIR__, 2 );
		$designer_css  = (string) file_get_contents( $root . '/assets/css/design/designer-mode.css' );
		$shell_css     = (string) file_get_contents( $root . '/assets/css/design/editor-shell.css' );

		self::assertStringContainsString(
			"grid-template-columns:\n\t\tvar(--cb-design-left-track)\n\t\tminmax(0, 1fr)\n\t\tvar(--cb-design-right-track);",
			$designer_css
		);
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 1280px\).*?\.cb-core-design-shell__workspace--collapsible\s*\{.*?grid-template-columns:\s*minmax\(180px, 220px\) minmax\(0, 1fr\);/s',
			$designer_css
		);
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 900px\).*?\.cb-core-design-shell__workspace--collapsible\s*\{.*?grid-template-columns:\s*1fr;/s',
			$designer_css
		);
		self::assertStringContainsString( '@media (max-width: 900px)', $shell_css );
		self::assertStringContainsString( "\t\tgrid-template-columns: 1fr;", $shell_css );
	}

	public function test_public_designer_docs_distinguish_engine_from_canonical_mode(): void {
		$root = dirname( __DIR__, 2 );
		$docs = (string) file_get_contents( $root . '/docs/DESIGNER-MODE.md' );

		self::assertStringContainsString( 'Assets::enqueue();', $docs );
		self::assertStringContainsString( 'Assets::enqueue_designer_mode(', $docs );
		self::assertStringContainsString( 'presentation-self-contained', $docs );
		self::assertStringContainsString( 'does **not** need `.cb-core-wrap`', $docs );
		self::assertStringContainsString( '`>1280px`', $docs );
		self::assertStringContainsString( '`901–1280px`', $docs );
		self::assertStringContainsString( '`≤900px`', $docs );
	}
}
