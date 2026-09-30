<?php
declare(strict_types=1);

final class CB_Base_Internal_History_Language_Contract_Test extends WP_UnitTestCase {

	public function test_stale_pre_v1_history_language_does_not_return(): void {
		$console    = file_get_contents( CB_CORE_DIR . 'src/Console/Rest/RunController.php' );
		$foundation = file_get_contents( CB_CORE_DIR . 'docs/foundation-v1-contract.md' );
		$changelog  = file_get_contents( CB_CORE_DIR . 'CHANGELOG.md' );

		self::assertIsString( $console );
		self::assertIsString( $foundation );
		self::assertIsString( $changelog );

		self::assertStringNotContainsString( 'Legacy commands not yet ported', $console );
		self::assertStringNotContainsString( 'Tile::quick', $foundation );
		self::assertStringNotContainsString( 'pre-normalization `1.0.0-rc2`', $changelog );
	}
}
