<?php
declare(strict_types=1);

use CB\Core\Reports\Composer\BlockCatalog;
use CB\Core\Reports\Composer\MaintenanceTemplate;
use CB\Core\Settings;

final class CB_Reports_Composer_Contract_Test extends WP_UnitTestCase {

	public function test_default_maintenance_template_is_bounded_and_structural(): void {
		$template = MaintenanceTemplate::defaults();
		$types    = array_column( $template['blocks'], 'type' );

		self::assertSame( MaintenanceTemplate::SCHEMA_VERSION, $template['schema_version'] );
		self::assertSame(
			[
				BlockCatalog::HEADER,
				BlockCatalog::STATUS,
				BlockCatalog::KPIS,
				BlockCatalog::CURRENT_STATE,
				BlockCatalog::ACTIVITY,
				BlockCatalog::SUMMARY,
				BlockCatalog::NOTES,
				BlockCatalog::FOOTER,
			],
			$types
		);
		self::assertSame( $types, array_values( array_unique( $types ) ) );
		self::assertTrue( $template['blocks'][0]['enabled'] );
		self::assertTrue( $template['blocks'][ count( $template['blocks'] ) - 1 ]['enabled'] );
	}

	public function test_normalizer_preserves_optional_order_and_heals_structure(): void {
		$template = MaintenanceTemplate::normalize( [
			'schema_version' => 999,
			'blocks' => [
				[ 'type' => BlockCatalog::NOTES, 'enabled' => false ],
				[ 'type' => BlockCatalog::FOOTER, 'enabled' => false ],
				[ 'type' => 'unknown_block', 'enabled' => true ],
				[ 'type' => BlockCatalog::KPIS, 'enabled' => true ],
				[ 'type' => BlockCatalog::KPIS, 'enabled' => false ],
				[ 'type' => BlockCatalog::HEADER, 'enabled' => false ],
			],
		] );

		$types = array_column( $template['blocks'], 'type' );
		self::assertSame( BlockCatalog::HEADER, $types[0] );
		self::assertSame( BlockCatalog::FOOTER, $types[ count( $types ) - 1 ] );
		self::assertSame( BlockCatalog::NOTES, $types[1] );
		self::assertSame( BlockCatalog::KPIS, $types[2] );
		self::assertCount( count( BlockCatalog::types() ), $types );
		self::assertSame( $types, array_values( array_unique( $types ) ) );
		self::assertTrue( $template['blocks'][0]['enabled'] );
		self::assertTrue( $template['blocks'][ count( $template['blocks'] ) - 1 ]['enabled'] );
		self::assertFalse( $template['blocks'][1]['enabled'] );
	}

	public function test_base_settings_publish_the_canonical_maintenance_template(): void {
		$defaults = Settings::defaults();
		$template = $defaults['reports']['composer']['maintenance'] ?? null;

		self::assertIsArray( $template );
		self::assertSame( MaintenanceTemplate::defaults(), $template );
	}

	public function test_catalog_keeps_reports_semantics_out_of_designer_foundation(): void {
		$definitions = BlockCatalog::definitions();

		self::assertSame( 'first', $definitions[ BlockCatalog::HEADER ]['locked_position'] );
		self::assertSame( 'last', $definitions[ BlockCatalog::FOOTER ]['locked_position'] );
		self::assertSame( 'maintenance.kpis', $definitions[ BlockCatalog::KPIS ]['source'] );
		self::assertSame( 'maintenance.sections', $definitions[ BlockCatalog::ACTIVITY ]['source'] );
		self::assertSame( 'maintenance.security+maintenance.backups', $definitions[ BlockCatalog::SUMMARY ]['source'] );
	}
}
