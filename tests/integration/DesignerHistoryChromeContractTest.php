<?php
declare(strict_types=1);

final class CB_Designer_History_Chrome_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_shell_uses_only_postcommit_public_history_contract(): void {
		$shell = $this->source( 'assets/js/design/shell/index.js' );

		self::assertStringContainsString( 'session.subscribeHistory(syncHistory)', $shell );
		self::assertStringContainsString( 'session.subscribeDiagnostics(onDiagnostic)', $shell );
		self::assertStringContainsString( 'session.historyStatus()', $shell );
		self::assertStringContainsString( 'unsubscribeHistory?.();', $shell );
		self::assertStringContainsString( 'unsubscribeDiagnostics?.();', $shell );
		self::assertStringNotContainsString( 'session?.history', $shell );
		self::assertStringNotContainsString( 'session?.projectState', $shell );
		self::assertStringNotContainsString( 'scheduleHistorySync', $shell );
		self::assertStringNotContainsString( 'queueMicrotask(syncHistory)', $shell );
	}

	public function test_both_session_factories_and_native_host_use_single_command_history_implementation(): void {
		$editor = $this->source( 'assets/js/design/editor.js' );
		// Profile, Domain and native Host each construct the SAME Base-owned engine.
		self::assertSame( 3, substr_count( $editor, 'new CommandHistory(' ) );
		self::assertStringContainsString( 'history.captureSave()', $editor );
		self::assertStringContainsString( 'history.rebase(options)', $editor );
		self::assertStringContainsString( 'history.subscribeHistory(listener)', $editor );
		self::assertStringContainsString( 'history.subscribeDiagnostics(listener)', $editor );
	}
	/**
	 * Source contract parity is required for the v2 candidate; the installed
	 * canonical ZIP still needs its own exact-artifact ABI/consumer acceptance.
	 */
	public function test_g1d_v2_php_capability_matches_the_public_editor_module(): void {
		self::assertSame( '@cb-core/design-editor', \CoreBlueprint\Core\Design\Editor\Assets::MODULE_ID );
		self::assertSame( 3, \CoreBlueprint\Core\Design\Editor\Assets::DOMAIN_SESSION_API );

		$facade = $this->source( 'assets/js/design/editor.js' );
		foreach ( [ 'createSession', 'createDomainSession', 'subscribeHistory', 'subscribeDiagnostics', 'captureSave', 'baselineSnapshot', 'rebase', 'clearHistory' ] as $symbol ) {
			self::assertStringContainsString( $symbol, $facade, $symbol . ' must remain available from the public source facade.' );
		}
		self::assertStringContainsString( 'new CommandHistory(', $facade );

		$doc = $this->source( 'docs/DESIGNER-FOUNDATION-API.md' );
		self::assertStringContainsString( 'v2 SOURCE candidate', $doc );
		self::assertStringContainsString( 'installed ZIP acceptance pending', $doc );
	}

	/**
	 * WordPress 7.0 resolves the consumer-facing identifier from its registered
	 * script module, not from a copied editor.js file or direct private import.
	 */
	public function test_g1d_v2_wordpress_registry_and_designer_mode_share_public_module(): void {
		$assets = \CoreBlueprint\Core\Design\Editor\Assets::class;
		$assets::enqueue_designer_mode();
		$module = wp_script_modules()->get_registered( $assets::MODULE_ID );
		self::assertIsArray( $module );
		self::assertStringContainsString( 'assets/js/design/editor.js', $module['src'] );
		self::assertNotNull( wp_script_modules()->get_registered( '@cb-core/design-motion' ) );
		self::assertTrue( wp_style_is( $assets::SHELL_STYLE, 'enqueued' ) );
		self::assertTrue( wp_style_is( $assets::DESIGNER_MODE_STYLE, 'enqueued' ) );
		self::assertTrue( wp_style_is( $assets::DESIGNER_COMPOSITION_STYLE, 'enqueued' ) );
		self::assertTrue( wp_style_is( $assets::DESIGNER_CONTEXT_STYLE, 'enqueued' ) );
		self::assertTrue( wp_style_is( $assets::DESIGNER_TOOLBAR_STYLE, 'enqueued' ) );
	}

}
