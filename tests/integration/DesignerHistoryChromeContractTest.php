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
}
