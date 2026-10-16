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

	public function test_both_session_factories_delegate_to_single_command_history(): void {
		$editor = $this->source( 'assets/js/design/editor.js' );
		self::assertSame( 2, substr_count( $editor, 'new CommandHistory(' ) );
		self::assertStringContainsString( 'history.captureSave()', $editor );
		self::assertStringContainsString( 'history.rebase(options)', $editor );
		self::assertStringContainsString( 'history.subscribeHistory(listener)', $editor );
		self::assertStringContainsString( 'history.subscribeDiagnostics(listener)', $editor );
	}
	/**
	 * Source parity is a necessary, not sufficient, condition for the v2 ABI.
	 * The PHP version stays at v1 until a separately approved canonical ZIP
	 * proves PHP/ESM method parity from that same exact artifact.
	 */
	public function test_g1c_php_capability_is_not_advertised_before_artifact_parity(): void {
		self::assertSame( '@cb-core/design-editor', \CoreBlueprint\Core\Design\Editor\Assets::MODULE_ID );
		self::assertSame( 1, \CoreBlueprint\Core\Design\Editor\Assets::DOMAIN_SESSION_API );

		$facade = $this->source( 'assets/js/design/editor.js' );
		foreach ( [ 'createSession', 'createDomainSession', 'subscribeHistory', 'subscribeDiagnostics', 'captureSave', 'baselineSnapshot', 'rebase', 'clearHistory' ] as $symbol ) {
			self::assertStringContainsString( $symbol, $facade, $symbol . ' must remain available from the public source facade.' );
		}
		self::assertStringContainsString( 'new CommandHistory(', $facade );

		$doc = $this->source( 'docs/DESIGNER-FOUNDATION-API.md' );
		self::assertStringContainsString( 'G1c source candidate', $doc );
		self::assertStringContainsString( 'not yet an advertised v2 capability', $doc );
	}

}
