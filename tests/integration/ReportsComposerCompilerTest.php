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
			[ 'columns', 'rule', 'container', 'container', 'page_footer' ],
			$types
		);

		self::assertSame(
			[
				BlockCatalog::HEADER => [ 0, 1 ],
				BlockCatalog::NOTES  => [ 2 ],
				BlockCatalog::STATUS => [ 3 ],
				BlockCatalog::FOOTER => [ 4 ],
			],
			$document['preview_regions']
		);

		/** @var array{columns:list<list<RenderBlock>>,weights:list<float>} $header */
		$header = $document['blocks'][0]->payload();
		self::assertSame( 'Brand marker', $header['columns'][0][0]->payload() );
		self::assertSame( 'heading', $header['columns'][0][1]->type() );
		self::assertSame( 'Maintenance Report', $header['columns'][0][1]->payload()['text'] );

		/** @var list<RenderBlock> $notes */
		$notes = $document['blocks'][2]->payload();
		/** @var list<RenderBlock> $status */
		$status = $document['blocks'][3]->payload();
		self::assertSame( 'Notes / Observations', $notes[0]->payload()['text'] );
		self::assertSame( 'Note marker', $notes[1]->payload()['title'] );
		self::assertSame( 'Status marker', $status[0]->payload()['title'] );
		self::assertSame( 0.0, $document['blocks'][2]->hints()['space_before'] );
		self::assertSame( 5.0, $document['blocks'][3]->hints()['space_before'] );
		self::assertSame( 0.0, $notes[0]->hints()['space_before'] );
		self::assertSame( 0.0, $notes[1]->hints()['space_after'] );
		self::assertSame( 0.0, $status[0]->hints()['space_before'] );
		self::assertSame( 0.0, $status[0]->hints()['space_after'] );

		$reordered = $template;
		$reordered['blocks'] = [
			[ 'type' => BlockCatalog::HEADER, 'enabled' => true ],
			[ 'type' => BlockCatalog::STATUS, 'enabled' => true ],
			[ 'type' => BlockCatalog::NOTES, 'enabled' => true ],
			[ 'type' => BlockCatalog::KPIS, 'enabled' => false ],
			[ 'type' => BlockCatalog::CURRENT_STATE, 'enabled' => false ],
			[ 'type' => BlockCatalog::ACTIVITY, 'enabled' => false ],
			[ 'type' => BlockCatalog::SUMMARY, 'enabled' => false ],
			[ 'type' => BlockCatalog::FOOTER, 'enabled' => true ],
		];
		$reordered_document = ( new MaintenanceFlowCompiler() )->compile( $report, $snapshot, $branding, 'en_US', $reordered );
		self::assertSame( [ 2 ], $reordered_document['preview_regions'][ BlockCatalog::STATUS ] );
		self::assertSame( [ 3 ], $reordered_document['preview_regions'][ BlockCatalog::NOTES ] );
		self::assertSame( 0.0, $reordered_document['blocks'][2]->hints()['space_before'] );
		self::assertSame( 5.0, $reordered_document['blocks'][3]->hints()['space_before'] );
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
