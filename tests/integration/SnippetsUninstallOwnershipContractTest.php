<?php
declare(strict_types=1);

final class CB_Base_Snippets_Uninstall_Ownership_Contract_Test extends WP_UnitTestCase {

	public function test_uninstall_preserves_operator_source_and_neutralizes_generated_runtime_state(): void {
		$uninstall = (string) file_get_contents( CB_CORE_DIR . 'uninstall.php' );

		self::assertStringContainsString( "WP_CONTENT_DIR ) . 'cb-snippets'", $uninstall );
		self::assertStringContainsString( "'/runtime-index.php'", $uninstall );
		self::assertStringContainsString( "'/.lock'", $uninstall );
		self::assertStringContainsString( 'wp_delete_file( $runtime_file )', $uninstall );

		self::assertStringContainsString(
			'Preserved deliberately: registry.php, code/* and direct-access guard files.',
			$uninstall
		);
		self::assertStringNotContainsString( "apply_filters( 'core_blueprint_snippets_storage_dir'", $uninstall );
		self::assertStringNotContainsString( "'/registry.php'", $uninstall );
		self::assertStringNotContainsString( "'/code/'", $uninstall );
		self::assertStringNotContainsString( 'rmdir( $cb_snippets_default_dir', $uninstall );
	}

	public function test_preserved_php_snippet_files_remain_direct_access_guarded(): void {
		$code_file = (string) file_get_contents( CB_CORE_DIR . 'src/Snippets/CodeFile.php' );

		self::assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $code_file );
		self::assertStringContainsString( 'PHP_PREFIX', $code_file );
	}
}
