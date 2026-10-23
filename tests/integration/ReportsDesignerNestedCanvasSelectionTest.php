<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Document\Flow\Api\FlowRenderApi;
use CoreBlueprint\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter;
use CoreBlueprint\Core\Reports\Composer\NativeComposition;
use CoreBlueprint\Core\Reports\MaintenanceAggregator;
use CoreBlueprint\Core\Reports\MaintenanceFlowCompiler;

final class CB_Reports_Designer_Nested_Canvas_Selection_Test extends WP_UnitTestCase {
	/** @return array{project:array<string,mixed>,fields:array<string,mixed>} */
	private function document(): array {
		$project = PlatformAdapter::default_project();
		$section = [
			'type' => 'reports.section', 'provider' => 'core.blueprint',
			'properties' => [ 'title' => 'Editable Section', 'enabled' => 'on' ],
			'children' => [
				[ 'type' => 'reports.heading', 'provider' => 'core.blueprint', 'properties' => [ 'text' => 'Nested Heading' ], 'children' => [] ],
				[ 'type' => 'reports.paragraph', 'provider' => 'core.blueprint', 'properties' => [ 'text' => 'Nested Paragraph' ], 'children' => [] ],
				[ 'type' => 'reports.metric', 'provider' => 'core.blueprint', 'properties' => [ 'label' => 'Nested Metric', 'value' => '42' ], 'children' => [] ],
			],
		];
		array_splice( $project['root']['children'], -1, 0, [ $section ] );
		$native = [ 'project' => $project, 'fields' => PlatformAdapter::default_fields() ];
		NativeComposition::assert_document( $native );
		return $native;
	}

	/** @param array{project:array<string,mixed>,fields:array<string,mixed>} $native
	 * @return array<string,mixed>
	 */
	private function compile( array $native ): array {
		return ( new MaintenanceFlowCompiler() )->compile(
			[ 'generated_at' => '2026-10-23 10:00:00', 'period_start' => '2026-10-01', 'period_end' => '2026-10-23' ],
			[
				'snapshot_version' => MaintenanceAggregator::SNAPSHOT_VERSION,
				'site' => [ 'title' => 'Example', 'url' => 'https://example.test' ],
				'sections' => [],
			],
			'en_US',
			$native
		);
	}

	public function test_section_children_have_exact_nested_flow_preview_paths(): void {
		$native = $this->document();
		$root_index = count( $native['project']['root']['children'] ) - 2;
		$document = $this->compile( $native );
		$root_id = 'r' . (string) $root_index;
		self::assertArrayHasKey( $root_id, $document['preview_regions'] );
		$block_index = $document['preview_regions'][ $root_id ][0];
		self::assertIsInt( $block_index );
		foreach ( [ 0, 1, 2 ] as $child_index ) {
			self::assertSame(
				[ [ $block_index, 0, $child_index + 1 ] ],
				$document['preview_regions'][ $root_id . '.' . (string) $child_index ]
			);
		}
		$html = FlowRenderApi::preview_html(
			$document['layout'], $document['blocks'], $document['locale'],
			$document['presentation'], $document['preview_regions']
		);
		foreach ( [ 0, 1, 2 ] as $child_index ) {
			self::assertStringContainsString(
				'data-cb-flow-preview-region="' . $root_id . '.' . (string) $child_index . '"',
				$html
			);
		}
		$pdf_source_html = ( new HtmlRenderer() )->render(
			$document['layout'], $document['blocks'], $document['locale'], $document['presentation']
		);
		self::assertStringNotContainsString( 'data-cb-flow-preview-region', $pdf_source_html );
		// R-P4B editing cues remain in the isolated screen preview only.
		self::assertStringContainsString( 'cursor:pointer;outline-offset:3px;', $html );
		self::assertStringContainsString( '.cb-flow-text[data-cb-flow-preview-region]:empty', $html );
		self::assertStringContainsString( '.cb-flow-heading[data-cb-flow-preview-region]', $html );
		self::assertStringNotContainsString( 'cursor:pointer;outline-offset:3px;', $pdf_source_html );
		self::assertLessThanOrEqual( 100, count( $document['preview_regions'] ) );
	}

	public function test_disabled_section_exposes_no_nested_click_targets(): void {
		$native = $this->document();
		$index = count( $native['project']['root']['children'] ) - 2;
		$native['project']['root']['children'][ $index ]['properties']['enabled'] = 'off';
		$document = $this->compile( $native );
		self::assertArrayNotHasKey( 'r' . $index, $document['preview_regions'] );
		self::assertArrayNotHasKey( 'r' . $index . '.0', $document['preview_regions'] );
	}
}
