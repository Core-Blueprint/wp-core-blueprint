<?php
declare(strict_types=1);
/**
 * Maintenance report PDF presenter.
 *
 * Turns a persisted immutable report snapshot into a PDF binary on demand.
 * No report PDF is written to permanent storage.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Design\Profile\Document\Flow\PdfRenderer;

defined( 'ABSPATH' ) || exit;

final class MaintenancePdf {

	public function __construct(
		private readonly PdfRenderer $renderer = new PdfRenderer(),
		private readonly MaintenanceFlowCompiler $compiler = new MaintenanceFlowCompiler()
	) {}

	/**
	 * Render one stored report row to a PDF binary.
	 *
	 * Snapshot truth and render-time branding remain Reports-owned. The Design
	 * Foundation receives only resolved typed presentation blocks and locale.
	 *
	 * @param array<string,mixed> $report Stored row from Storage::find().
	 */
	public function render( array $report ): string {
		$data = $report['report_data'] ?? null;
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Maintenance report snapshot is missing or invalid.' );
		}

		$snapshot_version = (int) ( $data['snapshot_version'] ?? 0 );
		if ( MaintenanceAggregator::SNAPSHOT_VERSION !== $snapshot_version ) {
			throw new \RuntimeException( 'Maintenance report snapshot version is unsupported.' );
		}

		$locale = get_locale();
		if ( '' === trim( $locale ) ) {
			throw new \RuntimeException( 'Maintenance report render locale is missing.' );
		}

		$document = $this->compiler->compile(
			$report,
			$data,
			MaintenanceFlowBranding::resolve(),
			$locale
		);

		return $this->renderer->render(
			$document['layout'],
			$document['blocks'],
			$document['locale'],
			$document['presentation']
		);
	}
}
