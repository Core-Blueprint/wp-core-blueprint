<?php
declare(strict_types=1);

final class CB_Base_Core_Api_Documentation_Contract_Test extends WP_UnitTestCase {

	public function test_core_api_1_2_is_canonical_across_runtime_and_documentation(): void {
		$public_api = file_get_contents( CB_CORE_DIR . 'docs/PUBLIC-API.md' );
		$reorder    = file_get_contents( CB_CORE_DIR . 'docs/REORDER-FOUNDATION.md' );
		$secrets    = file_get_contents( CB_CORE_DIR . 'docs/SECRET-PROTECTION-FOUNDATION.md' );
		$changelog  = file_get_contents( CB_CORE_DIR . 'CHANGELOG.md' );
		$history    = file_get_contents( CB_CORE_DIR . 'CHANGELOG-HISTORY.md' );
		$extensions = file_get_contents( CB_CORE_DIR . 'src/Extensions.php' );

		self::assertSame( '1.2', CB_CORE_API_VERSION );
		self::assertIsString( $public_api );
		self::assertIsString( $reorder );
		self::assertIsString( $secrets );
		self::assertIsString( $changelog );
		self::assertIsString( $history );
		self::assertIsString( $extensions );

		self::assertStringContainsString( '`CB_CORE_API_VERSION` is `1.2`', $public_api );
		self::assertStringContainsString( 'minimum Core API contract', $public_api );
		self::assertStringContainsString( 'Reorder Foundation must declare at least `1.1`', $public_api );
		self::assertStringContainsString( 'Secret Protection Foundation must declare at least `1.2`', $public_api );
		self::assertStringContainsString( 'Introduced in Core API `1.2`.', $secrets );
		self::assertStringContainsString( 'Introduced in Core API `1.1`.', $reorder );
		self::assertStringContainsString( 'public Core API `1.1` contract', $changelog );
		self::assertStringContainsString( 'public Core API `1.2` service', $changelog );
		self::assertStringNotContainsString( 'Core API `1.0` unchanged', $changelog );
		self::assertStringNotContainsString( 'Core API and database schema versions unchanged at `1.0`', $changelog );
		self::assertStringContainsString( 'Source-only historical record.', $history );
		self::assertStringContainsString( 'are not the current Base contract', $history );
		self::assertStringContainsString( "defined( 'CB_CORE_API_VERSION' )", $extensions );
	}
}
