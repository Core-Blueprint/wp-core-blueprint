<?php
declare(strict_types=1);

final class CB_Base_Gate2_Security_Scope_Contract_Test extends WP_UnitTestCase {
	public function test_gate2_changes_do_not_reintroduce_server_side_secret_recovery_state(): void {
		$files = [
			CB_CORE_DIR . 'src/Ajax/Handlers/Failsafe.php',
			CB_CORE_DIR . 'src/Admin/Pages/Safeguards.php',
			CB_CORE_DIR . 'templates/failsafe.php',
		];

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertStringNotContainsString( 'set_transient( self::DRAFT_PREFIX', $source );
			self::assertStringNotContainsString( 'get_transient( $flash_key', $source );
		}
	}
}
