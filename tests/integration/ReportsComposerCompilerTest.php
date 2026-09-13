<?php
declare(strict_types=1);

use CB\Core\Design\Profile\Document\Flow\RenderBlock;
use CB\Core\Reports\Composer\BlockCatalog;
use CB\Core\Reports\Composer\MaintenanceTemplate;
use CB\Core\Reports\MaintenanceAggregator;
use CB\Core\Reports\MaintenanceFlowCompiler;
use CB\Core\Reports\ReportBranding;

final class CB_Reports_Composer_Compiler_Test extends WP_UnitTestCase {

	public function test_compiler_follows_normalized_composer_order_and_disabled_state(): void {
		$template = [
			'schema_version' => MaintenanceTemplate::SCHEMA_VERSION,
			'blocks' => [
				[ 'type' => BlockCatalog::HEADER, 'enabled' => true ],
				[ 'type' => BlockCatalog::NOTES, 'enabled' => true ],
				[ 'type' => BlockCatalog::STATUS, 'enabled' => true ],
				[ 'type' => BlockCatalog::KPIS, 'enabled' => false ],
				[ 'type' => BlockCatalog::CURRENT_STATE, 'enabled' => false ],
				[ 'type' => BlockCatalog::ACTIVITY, 'enabled' => false ],
				[ 'type' => BlockCatalog::SUMMARY, 'enabled' => false ],
				[ 'type' => BlockCatalog::FOOTER, 'enabled' => true ],
			],
		];

		$report = [
			'period_start' => '2026-09-01',
			'period_end'   => '2026-09-13',
			'generated_at' => '2026-09-13 20:00:00',
		];
		$snapshot = [
			'snapshot_version' => MaintenanceAggregator::SNAPSHOT_VERSION,
			'site' => [
				'title' => 'Composer Test',
				'url'   => 'https://example.test',
			],
			'status' => [
				'banner'          => 'ok',
				'headline'        => 'Status marker',
				'subline'         => '',
				'detail_headline' => '',
				'detail_subline'  => '',
			],
			'notes' => [
				[ 'type' => 'info', 'title' => 'Note marker', 'body' => 'Composer note body.' ],
			],
			'kpis'       => [],
			'site_state' => [],
			'sections'   => [],
			'security'   => null,
			'backups'    => [],
		];
		$branding = [
			'logo_url'         => '',
			'fallback_text'    => 'Brand marker',
			'provider_name'    => '',
			'provider_contact' => '',
			'accent_color'     => ReportBranding::DEFAULT_ACCENT,
			'is_default'       => true,
		];

		$document = ( new MaintenanceFlowCompiler() )->compile( $report, $snapshot, $branding, 'en_US', $template );
		$types = array_map(
			static fn ( RenderBlock $block ): string => $block->type(),
			$document['blocks']
		);

		self::assertSame(
			[ 'text', 'text', 'text', 'container', 'text', 'page_footer' ],
			$types
		);
		self::assertSame( 'Brand marker', $document['blocks'][0]->payload() );
		self::assertStringContainsString( 'Maintenance Report', (string) $document['blocks'][1]->payload() );
		self::assertSame( 'container', $document['blocks'][3]->type() );
		self::assertStringContainsString( 'Status marker', (string) $document['blocks'][4]->payload() );
	}

	public function test_compiler_normalizes_untrusted_template_structure_before_dispatch(): void {
		$template = [
			'blocks' => [
				[ 'type' => BlockCatalog::FOOTER, 'enabled' => false ],
				[ 'type' => 'arbitrary_html', 'enabled' => true ],
				[ 'type' => BlockCatalog::HEADER, 'enabled' => false ],
				[ 'type' => BlockCatalog::STATUS, 'enabled' => false ],
			],
		];
		$normalized = MaintenanceTemplate::normalize( $template );

		self::assertSame( BlockCatalog::HEADER, $normalized['blocks'][0]['type'] );
		self::assertTrue( $normalized['blocks'][0]['enabled'] );
		self::assertSame( BlockCatalog::FOOTER, $normalized['blocks'][ count( $normalized['blocks'] ) - 1 ]['type'] );
		self::assertTrue( $normalized['blocks'][ count( $normalized['blocks'] ) - 1 ]['enabled'] );
		self::assertNotContains( 'arbitrary_html', array_column( $normalized['blocks'], 'type' ) );
	}
}
