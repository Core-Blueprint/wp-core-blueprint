<?php
declare(strict_types=1);

final class CB_Designer_History_Chrome_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_shell_defers_history_sync_until_command_history_has_committed_the_stack(): void {
		$shell = $this->source( 'assets/js/design/shell/index.js' );

		self::assertStringContainsString( 'const scheduleHistorySync = () => {', $shell );
		self::assertStringContainsString( "if (typeof queueMicrotask === 'function') queueMicrotask(syncHistory);", $shell );
		// P02 consumes the public domain-session facade, while document/mail sessions
		// retain their established projectState fallback. Both defer the UI sync.
		self::assertStringContainsString( 'const historyStatus = () => {', $shell );
		self::assertStringContainsString( "if (typeof session?.historyStatus === 'function') return session.historyStatus();", $shell );
		self::assertStringContainsString( 'if (session?.history) return { canUndo: session.history.canUndo, canRedo: session.history.canRedo };', $shell );
		self::assertStringContainsString( 'if (!status) return;', $shell );
		self::assertStringContainsString( "const unsubscribeProject = typeof session?.subscribe === 'function'", $shell );
		self::assertStringContainsString( '? session.subscribe(scheduleHistorySync)', $shell );
		self::assertStringContainsString( ': session?.projectState?.subscribe?.(scheduleHistorySync) ?? null;', $shell );
		self::assertStringContainsString( 'unsubscribeProject?.();', $shell );
	}
}
