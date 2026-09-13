<?php
declare(strict_types=1);

use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CB\Core\Design\Profile\Document\Flow\PdfRenderer;
use CB\Core\Reports\Composer\MaintenanceTemplate;
use CB\Core\Reports\MaintenanceAggregator;
use CB\Core\Reports\MaintenanceFlowCompiler;
use CB\Core\Reports\ReportBranding;

final class CB_Reports_Modern_Flow_Composition_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function report(): array {
		return [
			'period_start' => '2026-09-01',
			'period_end'   => '2026-09-13',
			'generated_at' => '2026-09-13 20:00:00',
		];
	}

	/** @return array<string,mixed> */
	private function snapshot(): array {
		return [
			'snapshot_version' => MaintenanceAggregator::SNAPSHOT_VERSION,
			'site' => [
				'title' => 'Modern Reports Test',
				'url'   => 'https://example.test',
			],
			'status' => [
				'banner'          => 'warn',
				'headline'        => 'Attention required',
				'subline'         => 'Two maintenance items need review.',
				'detail_headline' => 'Updates pending',
				'detail_subline'  => 'Review before the next maintenance window.',
			],
			'kpis' => [
				'updates_performed' => [ 'count' => 12, 'breakdown' => [ 'Plugins: 10', 'Theme: 2' ] ],
				'updates_pending'   => [ 'count' => 2, 'breakdown' => [ 'Plugins: 2' ] ],
				'security_issues'   => [ 'count' => 1, 'breakdown' => [] ],
				'backups_created'   => [ 'count' => 4, 'breakdown' => [] ],
				'active_users'      => [ 'count' => 18, 'breakdown' => [] ],
			],
			'site_state' => [
				'wp_core' => [ 'label' => 'WordPress', 'status' => 'ok', 'state' => 'Current', 'detail' => '7.1' ],
				'php'     => [ 'label' => 'PHP', 'status' => 'ok', 'state' => 'Current', 'detail' => '8.4' ],
			],
			'sections' => [
				'theme_updates' => [
					'title'   => 'Theme updates',
					'count'   => 1,
					'columns' => [ 'target_name', 'version_to', 'date', 'actor' ],
					'rows'    => [
						[ 'target_name' => 'Example Theme', 'version_to' => '2.0.0', 'date' => '2026-09-10 10:00:00', 'actor' => 'Operator' ],
					],
					'truncated' => false,
				],
			],
			'security' => [
				'detected'         => 1,
				'blocked_attempts' => 3,
				'brute_force'      => 0,
				'summary'          => '',
			],
			'backups' => [
				'count'           => 2,
				'last_at'         => '2026-09-13 18:00:00',
				'last_at_overall' => '',
				'providers'       => [ 'Local' ],
				'summary'         => 'Backup schedule healthy.',
			],
			'notes' => [
				[ 'type' => 'info', 'title' => 'Maintenance note', 'body' => 'Review the pending plugin updates.' ],
			],
		];
	}

	/** @return array<string,mixed> */
	private function branding(): array {
		return [
			'logo_url'         => '',
			'fallback_text'    => 'Core Blueprint',
			'provider_name'    => 'Example Provider',
			'provider_contact' => 'support@example.test',
			'accent_color'     => '#3455db',
			'is_default'       => false,
		];
	}

	public function test_maintenance_compiler_uses_semantic_foundation_blocks_for_modern_report_presentation(): void {
		$document = ( new MaintenanceFlowCompiler() )->compile(
			$this->report(),
			$this->snapshot(),
			$this->branding(),
			'en_US',
			MaintenanceTemplate::defaults()
		);

		$types = array_map( static fn ( $block ): string => $block->type(), $document['blocks'] );
		self::assertContains( 'columns', $types );
		self::assertContains( 'rule', $types );
		self::assertContains( 'heading', $types );
		self::assertContains( 'callout', $types );
		self::assertContains( 'metrics', $types );
		self::assertContains( 'table', $types );
		self::assertSame( 'page_footer', $types[ count( $types ) - 1 ] );

		$html = ( new HtmlRenderer() )->render(
			$document['layout'],
			$document['blocks'],
			$document['locale'],
			$document['presentation']
		);

		self::assertStringContainsString( 'cb-flow-heading--title">Maintenance Report', $html );
		self::assertStringContainsString( 'cb-flow-rule__line', $html );
		self::assertStringContainsString( 'cb-flow-callout cb-flow-callout--warning', $html );
		self::assertStringContainsString( 'Attention required', $html );
		self::assertStringContainsString( 'cb-flow-metrics-table', $html );
		self::assertStringContainsString( 'Updates Performed', $html );
		self::assertStringContainsString( 'cb-flow-heading--section">Current State', $html );
		self::assertStringContainsString( 'cb-flow-heading--subsection">Theme updates (1)', $html );
		self::assertStringContainsString( 'cb-flow-callout cb-flow-callout--critical', $html );
		self::assertStringContainsString( 'Security Activity', $html );
		self::assertStringContainsString( 'cb-flow-callout cb-flow-callout--neutral', $html );
		self::assertStringContainsString( 'Backups', $html );
		self::assertStringContainsString( 'Maintenance note', $html );
	}

	public function test_modern_maintenance_document_still_renders_real_pdf(): void {
		$document = ( new MaintenanceFlowCompiler() )->compile(
			$this->report(),
			$this->snapshot(),
			$this->branding(),
			'en_US',
			MaintenanceTemplate::defaults()
		);
		$pdf = ( new PdfRenderer() )->render(
			$document['layout'],
			$document['blocks'],
			$document['locale'],
			$document['presentation']
		);

		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThan( 1000, strlen( $pdf ) );
	}

	public function test_reports_compiler_stays_on_typed_flow_boundary_without_embedded_presentation_markup(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reports/MaintenanceFlowCompiler.php' );
		self::assertStringContainsString( 'RenderBlock::callout', $source );
		self::assertStringContainsString( 'RenderBlock::metrics', $source );
		self::assertStringContainsString( 'RenderBlock::rule', $source );
		self::assertStringNotContainsString( '<div', $source );
		self::assertStringNotContainsString( '<style', $source );
		self::assertStringNotContainsString( 'cb-flow-', $source );
	}
}
