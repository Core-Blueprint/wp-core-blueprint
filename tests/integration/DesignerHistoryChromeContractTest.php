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
		self::assertStringContainsString( 'session?.projectState?.subscribe?.(scheduleHistorySync);', $shell );
		self::assertStringContainsString( 'if (!session?.history) return;', $shell );
	}
}
