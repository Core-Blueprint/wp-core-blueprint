<?php
declare(strict_types=1);

namespace CB\Core\Reports;

use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Browser preview for the Reports Designer.
 *
 * The preview compiles the same typed Maintenance Flow used by PDF rendering.
 * When no stored report exists, only the data snapshot is synthetic; the
 * compiler and HTML renderer remain the canonical production path.
 */
final class DesignerPreview {

	public function __construct(
		private readonly HtmlRenderer $renderer = new HtmlRenderer(),
		private readonly MaintenanceFlowCompiler $compiler = new MaintenanceFlowCompiler()
	) {}

	/**
	 * @param array{logo_attachment_id:int,provider_name:string,provider_contact:string,accent_color:string} $branding
	 */
	public function render( array $branding ): string {
		$report = $this->preview_report();
		$data   = $report['report_data'] ?? null;
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Reports Designer preview snapshot is missing.' );
		}

		$locale = get_locale();
		if ( '' === trim( $locale ) ) {
			$locale = 'en_US';
		}

		$document = $this->compiler->compile(
			$report,
			$data,
			$this->resolve_branding( $branding ),
			$locale
		);

		return $this->renderer->render(
			$document['layout'],
			$document['blocks'],
			$document['locale'],
			$document['presentation']
		);
	}

	/**
	 * Prefer a real immutable report snapshot. A fresh site receives a small
	 * typed sample snapshot so appearance can still be designed before the
	 * first maintenance report is generated.
	 *
	 * @return array<string,mixed>
	 */
	private function preview_report(): array {
		try {
			$recent = Storage::find_recent( 1 );
			$row    = $recent[0] ?? null;
			if (
				is_array( $row )
				&& is_array( $row['report_data'] ?? null )
				&& MaintenanceAggregator::SNAPSHOT_VERSION === (int) ( $row['report_data']['snapshot_version'] ?? 0 )
			) {
				return $row;
			}
		} catch ( \Throwable $error ) {
			// A preview must remain available during recovery or first-install
			// states where the report table is not readable yet.
		}

		return $this->sample_report();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sample_report(): array {
		$end   = time();
		$start = $end - ( 29 * DAY_IN_SECONDS );

		return [
			'id'           => 0,
			'period_start' => wp_date( 'Y-m-d', $start ),
			'period_end'   => wp_date( 'Y-m-d', $end ),
			'generated_at' => current_time( 'mysql', true ),
			'generated_by' => get_current_user_id(),
			'status'       => 'generated',
			'report_data'  => [
				'snapshot_version' => MaintenanceAggregator::SNAPSHOT_VERSION,
				'period'           => [
					'start_ts' => $start,
					'end_ts'   => $end,
					'days'     => 30,
				],
				'site'             => [
					'title' => (string) get_bloginfo( 'name' ),
					'url'   => (string) home_url(),
				],
				'kpis'             => [],
				'site_state'       => [],
				'sections'         => [],
				'security'         => null,
				'backups'          => [],
				'status'           => [
					'banner'          => '',
					'headline'        => __( 'Maintenance Report', 'core-blueprint' ),
					'subline'         => '',
					'detail_headline' => '',
					'detail_subline'  => '',
				],
				'notes'            => [],
			],
		];
	}

	/**
	 * @param array{logo_attachment_id:int,provider_name:string,provider_contact:string,accent_color:string} $branding
	 * @return array{logo_url:string,fallback_text:string,provider_name:string,provider_contact:string,accent_color:string,is_default:bool}
	 */
	private function resolve_branding( array $branding ): array {
		$logo_id  = (int) $branding['logo_attachment_id'];
		$logo_url = $logo_id > 0 && ReportBranding::is_supported_logo_attachment( $logo_id )
			? ReportBranding::attachment_url( $logo_id )
			: (string) ReportBranding::fallback()['logo_url'];

		return [
			'logo_url'         => $logo_url,
			'fallback_text'    => '' === $logo_url ? 'Core Blueprint' : '',
			'provider_name'    => (string) $branding['provider_name'],
			'provider_contact' => (string) $branding['provider_contact'],
			'accent_color'     => (string) $branding['accent_color'],
			'is_default'       => 0 === $logo_id
				&& '' === (string) $branding['provider_name']
				&& '' === (string) $branding['provider_contact']
				&& ReportBranding::DEFAULT_ACCENT === strtolower( (string) $branding['accent_color'] ),
		];
	}
}
