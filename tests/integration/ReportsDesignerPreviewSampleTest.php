<?php
declare(strict_types=1);

use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CB\Core\Reports\Composer\MaintenanceTemplate;
use CB\Core\Reports\DesignerPreview;
use CB\Core\Reports\MaintenanceFlowCompiler;
use CB\Core\Reports\ReportBranding;

final class CB_Reports_Designer_Preview_Sample_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function sample_report(): array {
		$preview = new DesignerPreview();
		$method  = new ReflectionMethod( DesignerPreview::class, 'sample_report' );
		$method->setAccessible( true );
		$report = $method->invoke( $preview );
		self::assertIsArray( $report );
		return $report;
	}

	public function test_fallback_sample_exercises_every_optional_default_composer_section(): void {
		$report = $this->sample_report();
		$data   = $report['report_data'] ?? null;
		self::assertIsArray( $data );
		self::assertNotEmpty( $data['kpis'] ?? [] );
		self::assertNotEmpty( $data['site_state'] ?? [] );
		self::assertNotEmpty( $data['sections'] ?? [] );
		self::assertIsArray( $data['security'] ?? null );
		self::assertGreaterThan( 0, (int) ( $data['backups']['count'] ?? 0 ) );
		self::assertNotEmpty( $data['notes'] ?? [] );
	}

	public function test_fallback_sample_uses_canonical_modern_flow_composition(): void {
		$report = $this->sample_report();
		$data   = $report['report_data'] ?? null;
		self::assertIsArray( $data );

		$document = ( new MaintenanceFlowCompiler() )->compile(
			$report,
			$data,
			[
				'logo_url'         => '',
				'fallback_text'    => 'Core Blueprint',
				'provider_name'    => '',
				'provider_contact' => '',
				'accent_color'     => ReportBranding::DEFAULT_ACCENT,
				'is_default'       => true,
			],
			'en_US',
			MaintenanceTemplate::defaults()
		);

		$html = ( new HtmlRenderer() )->render(
			$document['layout'],
			$document['blocks'],
			$document['locale'],
			$document['presentation']
		);

		self::assertStringContainsString( 'cb-flow-metrics-table', $html );
		self::assertStringContainsString( 'cb-flow-callout--success', $html );
		self::assertStringContainsString( 'cb-flow-callout--info', $html );
		self::assertStringContainsString( 'cb-flow-heading--section', $html );
		self::assertStringContainsString( 'cb-flow-heading--subsection', $html );
		self::assertStringContainsString( 'cb-flow-table', $html );
		self::assertStringContainsString( 'Security Activity', $html );
		self::assertStringContainsString( 'Backups', $html );
		self::assertStringContainsString( 'Notes / Observations', $html );
	}
}
