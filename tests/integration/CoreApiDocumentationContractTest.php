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
		self::assertStringContainsString( 'native-screen semantic UI requirements must declare at least `1.2`', $public_api );
		self::assertStringContainsString( 'CoreBlueprint\\\\Core\\\\UI\\\\PrimaryNav::render()', $public_api );
		self::assertStringContainsString( 'CoreBlueprint\\\\Core\\\\UI\\\\SectionNav::render()', $public_api );
		self::assertStringContainsString( 'CoreBlueprint\\\\Core\\\\UI\\\\Tile::render()', $public_api );
		self::assertStringContainsString( 'Introduced in Core API `1.2`.', $secrets );
		self::assertStringContainsString( 'Introduced in Core API `1.1`.', $reorder );
		self::assertStringContainsString( 'public Core API `1.1` contract', $changelog );
		self::assertStringContainsString( 'Core API 1.2 — native admin screen UI requirements', $changelog );
		self::assertStringContainsString( 'Core API 1.2 - shared admin navigation and tiles', $changelog );
		self::assertStringContainsString( 'public Core API `1.2` service', $changelog );
		self::assertStringNotContainsString( 'Core API `1.0` unchanged', $changelog );
		self::assertStringNotContainsString( 'Core API and database schema versions unchanged at `1.0`', $changelog );
		self::assertStringContainsString( 'Source-only historical record.', $history );
		self::assertStringContainsString( 'are not the current Base contract', $history );
		self::assertStringContainsString( "defined( 'CB_CORE_API_VERSION' )", $extensions );
	}

	public function test_public_admin_ui_requirement_vocabulary_matches_documented_boundary(): void {
		$public_api = (string) file_get_contents( CB_CORE_DIR . 'docs/PUBLIC-API.md' );
		$registry   = new ReflectionClass( \CoreBlueprint\Core\Admin\PageRegistry::class );

		$foundations = $registry->getConstant( 'FOUNDATION_REQUIREMENTS' );
		$components  = $registry->getConstant( 'COMPONENT_REQUIREMENTS' );
		$base_only   = $registry->getConstant( 'BASE_COMPONENT_REQUIREMENTS' );

		self::assertIsArray( $foundations );
		self::assertIsArray( $components );
		self::assertIsArray( $base_only );

		foreach ( [ 'icon-control', 'reorder', 'segmented-control' ] as $foundation ) {
			self::assertContains( $foundation, $foundations );
			self::assertStringContainsString( "`{$foundation}`", $public_api );
		}

		foreach ( [ 'actions', 'overview', 'policy-table' ] as $private_component ) {
			self::assertNotContains( $private_component, $components );
		}

		self::assertSame( [ 'actions', 'overview' ], $base_only );
		self::assertStringContainsString(
			'Base-only composition identifiers such as `actions` and `overview`',
			$public_api
		);
		self::assertStringContainsString(
			'private page-specific styles such as `policy-table`',
			$public_api
		);

		$source = (string) file_get_contents( CB_CORE_DIR . 'src/Admin/PageRegistry.php' );
		self::assertStringContainsString(
			'return self::normalize_requirements( $requirements, $consumer, false );',
			$source
		);
		self::assertStringContainsString(
			'self::normalize_requirements( $requirements, $slug, $base_owned );',
			$source
		);
	}

}
