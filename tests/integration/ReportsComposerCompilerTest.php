<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Flow\RenderBlock;
use CoreBlueprint\Core\Reports\MaintenanceAggregator;
use CoreBlueprint\Core\Reports\MaintenanceFlowCompiler;
use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter;
use CoreBlueprint\Core\Reports\Composer\NativeComposition;

final class CB_Reports_Composer_Compiler_Test extends WP_UnitTestCase {

	public function test_compiler_follows_native_node_order_and_disabled_state(): void {
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
		$project = PlatformAdapter::default_project();
		$project['root']['children'] = array_values( array_filter(
			$project['root']['children'],
			static fn ( array $node ): bool => in_array(
				$node['type'], [ 'reports.header', 'reports.notes', 'reports.status', 'reports.footer' ], true
			)
		) );
		$native = [ 'project' => $project, 'fields' => PlatformAdapter::default_fields() ];
		NativeComposition::assert_document( $native );
		$document = ( new MaintenanceFlowCompiler() )->compile( $report, $snapshot, 'en_US', $native );
		$types = array_map( static fn ( RenderBlock $block ): string => $block->type(), $document['blocks'] );

		self::assertSame( [ 'columns', 'rule', 'container', 'container', 'page_footer' ], $types );
		self::assertSame( 'en_US', $document['locale'] );
		self::assertSame( [ 0, 1 ], $document['preview_regions']['r0'] );
		self::assertSame( [ 2 ], $document['preview_regions']['r1'] );
		self::assertSame( [ 3 ], $document['preview_regions']['r2'] );
		self::assertSame( [ 4 ], $document['preview_regions']['r3'] );
		self::assertSame( 'heading', $document['blocks'][0]->payload()['columns'][0][1]->type() );
		self::assertSame( 'Maintenance Report', $document['blocks'][0]->payload()['columns'][0][1]->payload()['text'] );

		$reordered = $project;
		$reordered['root']['children'] = [
			$project['root']['children'][0],
			$project['root']['children'][2],
			$project['root']['children'][1],
			$project['root']['children'][3],
		];
		$reordered_document = ( new MaintenanceFlowCompiler() )->compile(
			$report, $snapshot, 'en_US', [ 'project' => $reordered, 'fields' => PlatformAdapter::default_fields() ]
		);
		self::assertSame( 'reports.status', $reordered['root']['children'][1]['type'] );
		self::assertSame( [ 2 ], $reordered_document['preview_regions']['r1'] );
		self::assertSame( [ 3 ], $reordered_document['preview_regions']['r2'] );
		self::assertNotSame( $document['blocks'][2]->payload(), $reordered_document['blocks'][2]->payload() );

		$disabled = $project;
		$disabled['root']['children'][1]['properties']['enabled'] = 'off';
		$without_notes = ( new MaintenanceFlowCompiler() )->compile(
			$report, $snapshot, 'en_US', [ 'project' => $disabled, 'fields' => PlatformAdapter::default_fields() ]
		);
		self::assertArrayNotHasKey( 'r1', $without_notes['preview_regions'] );
		self::assertSame( [ 'columns', 'rule', 'container', 'page_footer' ],
			array_map( static fn ( RenderBlock $block ): string => $block->type(), $without_notes['blocks'] )
		);
	}

	public function test_compiler_rejects_unknown_native_nodes_instead_of_normalizing_them(): void {
		$project = PlatformAdapter::default_project();
		$invalid = $project;
		$invalid['root']['children'][1]['type'] = 'arbitrary_html';
		self::assertFalse( NativeComposition::valid_document( [
			'project' => $invalid, 'fields' => PlatformAdapter::default_fields(),
		] ) );
		self::assertTrue( NativeComposition::valid_document( [
			'project' => $project, 'fields' => PlatformAdapter::default_fields(),
		] ) );
	}
}
