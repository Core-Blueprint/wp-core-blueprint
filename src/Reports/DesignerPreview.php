<?php
declare(strict_types=1);

namespace CB\Core\Reports;

use CB\Core\Design\Profile\Document\Flow\Api\FlowRenderApi;
defined( 'ABSPATH' ) || exit;

/**
 * Browser preview for the Reports Designer.
 *
 * The preview compiles the same typed Maintenance Flow used by PDF rendering.
 * When no stored report exists, only the data snapshot is synthetic; the
 * compiler and public Flow preview target remain the canonical production path.
 */
final class DesignerPreview {

	public function __construct(
		private readonly MaintenanceFlowCompiler $compiler = new MaintenanceFlowCompiler()
	) {}

	/**
	 * @param array{logo_attachment_id:int,provider_name:string,provider_contact:string,accent_color:string} $branding
	 * @param array<string,mixed>|null $template Unsaved Composer state; null resolves persisted state.
	 */
	public function render( array $branding, ?array $template = null ): string {
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
			MaintenanceFlowBranding::resolve_values( $branding ),
			$locale,
			$template
		);

		return FlowRenderApi::preview_html(
			$document['layout'],
			$document['blocks'],
			$document['locale'],
			$document['presentation']
		);
	}

	/**
	 * Prefer a real immutable report snapshot. A fresh site receives a bounded,
	 * representative sample snapshot so every default Composer block can be
	 * evaluated before the first maintenance report is generated.
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

	/** @return array<string,mixed> */
	private function sample_report(): array {
		$end           = time();
		$start         = $end - ( 29 * DAY_IN_SECONDS );
		$activity_date = gmdate( 'Y-m-d H:i:s', $end - DAY_IN_SECONDS );
		$backup_date   = gmdate( 'Y-m-d H:i:s', $end - ( 2 * HOUR_IN_SECONDS ) );

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
				'kpis'             => [
					'updates_performed' => [ 'count' => 12, 'breakdown' => [ 'Plugins: 10', 'Themes: 2' ] ],
					'updates_pending'   => [ 'count' => 0, 'breakdown' => [] ],
					'security_issues'   => [ 'count' => 0, 'breakdown' => [] ],
					'backups_created'   => [ 'count' => 4, 'breakdown' => [] ],
					'active_users'      => [ 'count' => 6, 'breakdown' => [] ],
				],
				'site_state'       => [
					'wp_core'  => [ 'label' => 'WordPress', 'status' => 'ok', 'state' => (string) get_bloginfo( 'version' ), 'detail' => '' ],
					'theme'    => [ 'label' => 'Theme', 'status' => 'ok', 'state' => 'OK', 'detail' => '' ],
					'plugins'  => [ 'label' => 'Plugins', 'status' => 'ok', 'state' => 'OK', 'detail' => '' ],
					'php'      => [ 'label' => 'PHP', 'status' => 'ok', 'state' => PHP_VERSION, 'detail' => '' ],
					'database' => [ 'label' => 'Database', 'status' => 'ok', 'state' => 'OK', 'detail' => '' ],
					'website'  => [ 'label' => 'Website', 'status' => 'ok', 'state' => 'OK', 'detail' => '' ],
				],
				'sections'         => [
					'plugin_updates' => [
						'title'     => 'Plugin updates',
						'count'     => 2,
						'columns'   => [ 'target_name', 'version_from', 'version_to', 'date', 'actor' ],
						'rows'      => [
							[
								'target_name'  => 'Example Plugin',
								'version_from' => '1.2.0',
								'version_to'   => '1.3.0',
								'date'         => $activity_date,
								'actor'        => 'Core Blueprint',
							],
							[
								'target_name'  => 'Example Security Plugin',
								'version_from' => '2.4.1',
								'version_to'   => '2.5.0',
								'date'         => $activity_date,
								'actor'        => 'Core Blueprint',
							],
						],
						'truncated' => false,
					],
				],
				'security'         => [
					'detected'         => 0,
					'summary'          => '',
					'blocked_attempts' => 3,
					'brute_force'      => 0,
				],
				'backups'          => [
					'count'     => 4,
					'last_at'   => $backup_date,
					'providers' => [ 'Core Blueprint' ],
					'summary'   => '',
				],
				'status'           => [
					'banner'          => 'ok',
					'headline'        => '',
					'subline'         => '',
					'detail_headline' => '',
					'detail_subline'  => '',
				],
				'notes'            => [
					[
						'type'  => 'info',
						'title' => 'Preview note',
						'body'  => 'Report notes and observations appear here.',
					],
				],
			],
		];
	}
}
