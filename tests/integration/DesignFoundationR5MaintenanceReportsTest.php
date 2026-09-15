<?php
declare(strict_types=1);

use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CB\Core\Design\Profile\Document\Flow\PdfRenderer;
use CB\Core\Design\Profile\Document\Flow\Presentation;
use CB\Core\Design\Profile\Document\Flow\RenderBlock;
use CB\Core\Reports\MaintenanceAggregator;
use CB\Core\Reports\MaintenanceFlowBranding;
use CB\Core\Reports\MaintenanceFlowCompiler;
use CB\Core\Reports\MaintenancePdf;
use CB\Core\Settings;

final class CB_Design_Foundation_R5_Maintenance_Reports_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function snapshot( bool $with_security = true ): array {
		$kpis = [
			'updates_performed' => [ 'count' => 4, 'breakdown' => [ '2 plugins', '1 theme', '1 core' ] ],
			'updates_pending'   => [ 'count' => 2, 'breakdown' => [] ],
			'backups_created'   => [ 'count' => 3, 'breakdown' => [ 'Local: 2', 'Remote: 1' ] ],
			'active_users'      => [ 'count' => 7, 'breakdown' => [] ],
		];
		if ( $with_security ) {
			$kpis = array_slice( $kpis, 0, 2, true )
				+ [ 'security_issues' => [ 'count' => 1, 'breakdown' => [ '1 warning' ] ] ]
				+ array_slice( $kpis, 2, null, true );
		}

		return [
			'snapshot_version' => MaintenanceAggregator::SNAPSHOT_VERSION,
			'period'           => [ 'start' => '2026-08-01', 'end' => '2026-08-31' ],
			'site'             => [ 'title' => 'Example <Site>', 'url' => 'https://example.test/?a=<b>' ],
			'status'           => [
				'banner'          => 'warn',
				'headline'        => 'Maintenance requires attention',
				'subline'         => 'Two updates remain',
				'detail_headline' => 'Review pending work',
				'detail_subline'  => 'No critical outage detected',
			],
			'kpis'             => $kpis,
			'site_state'       => [
				'wp_core'  => [ 'label' => 'WordPress core', 'status' => 'ok', 'state' => 'Current', 'detail' => '7.0' ],
				'theme'    => [ 'label' => 'Theme', 'status' => 'warn', 'state' => 'Update available', 'detail' => 'Child theme active' ],
				'plugins'  => [ 'label' => 'Plugins', 'status' => 'ok', 'state' => 'Healthy', 'detail' => '12 active' ],
				'php'      => [ 'label' => 'PHP', 'status' => 'ok', 'state' => 'Supported', 'detail' => '8.4' ],
				'database' => [ 'label' => 'Database', 'status' => 'ok', 'state' => 'Healthy', 'detail' => 'MariaDB' ],
				'website'  => [ 'label' => 'Website', 'status' => 'ok', 'state' => 'Online', 'detail' => 'HTTPS' ],
			],
			'notes'            => [
				[ 'type' => 'ok', 'title' => 'Backup verified', 'body' => 'Restore point available.' ],
				[ 'type' => 'info', 'title' => 'Information', 'body' => 'Routine maintenance.' ],
				[ 'type' => 'warn', 'title' => 'Pending updates', 'body' => 'Review before installing.' ],
				[ 'type' => 'critical', 'title' => '<Critical>', 'body' => '<script>alert(1)</script>' ],
			],
			'sections'         => [
				'theme_updates' => [
					'title' => 'Theme updates', 'count' => 1, 'columns' => [ 'target_name', 'version_from', 'version_to', 'date', 'actor' ],
					'rows' => [ [ 'target_name' => 'Example Theme', 'version_from' => '1.0', 'version_to' => '1.1', 'date' => '2026-08-02 10:30:00', 'actor' => 'Operator' ] ],
					'truncated' => false,
				],
				'plugin_updates' => [
					'title' => 'Plugin updates', 'count' => 5, 'columns' => [ 'target_name', 'version_from', 'version_to', 'date', 'actor', 'notes' ],
					'rows' => [
						[ 'target_name' => 'Plugin A', 'version_from' => '2.0', 'version_to' => '2.1', 'date' => '2026-08-03 11:00:00', 'actor' => 'Operator', 'notes' => 'Routine' ],
						[ 'target_name' => 'Plugin B', 'version_from' => '', 'version_to' => '4.0', 'date' => '2026-08-04 12:00:00', 'actor' => 'Cron', 'notes' => '<unsafe>' ],
					],
					'truncated' => true,
				],
				'plugin_installations' => [ 'title' => 'Plugin installations', 'count' => 0, 'columns' => [], 'rows' => [], 'truncated' => false ],
				'plugin_removals'      => [ 'title' => 'Plugin removals', 'count' => 0, 'columns' => [], 'rows' => [], 'truncated' => false ],
				'core_updates' => [
					'title' => 'Core updates', 'count' => 1, 'columns' => [ 'target_name', 'version_from', 'version_to', 'date', 'actor' ],
					'rows' => [ [ 'target_name' => 'WordPress', 'version_from' => '6.9', 'version_to' => '7.0', 'date' => '2026-08-05 09:00:00', 'actor' => 'Operator' ] ],
					'truncated' => false,
				],
			],
			'security'         => $with_security ? [ 'detected' => 1, 'summary' => 'One warning', 'blocked_attempts' => 12, 'brute_force' => 0 ] : null,
			'backups'          => [ 'count' => 3, 'last_at' => '2026-08-31 23:00:00', 'providers' => [ 'Local', 'Remote' ], 'summary' => 'Backup coverage healthy.' ],
		];
	}

	/** @return array<string,mixed> */
	private function report( array $snapshot ): array {
		return [
			'id'           => 42,
			'period_start' => '2026-08-01',
			'period_end'   => '2026-08-31',
			'generated_at' => '2026-09-01 08:30:00',
			'report_data'  => $snapshot,
		];
	}

	/** @return array<string,mixed> */
	private function branding(): array {
		return [
			'logo_url'         => '',
			'fallback_text'    => 'Core Blueprint',
			'provider_name'    => 'Infused <Agency>',
			'provider_contact' => 'support@example.test',
			'accent_color'     => '#0064c8',
			'is_default'       => false,
		];
	}

	private function html( bool $with_security = true ): string {
		$snapshot = $this->snapshot( $with_security );
		return $this->render_snapshot( $snapshot );
	}

	/** @param array<string,mixed> $snapshot */
	private function render_snapshot( array $snapshot ): string {
		$document = ( new MaintenanceFlowCompiler() )->compile( $this->report( $snapshot ), $snapshot, $this->branding(), 'en_GB' );
		return ( new HtmlRenderer() )->render( $document['layout'], $document['blocks'], $document['locale'], $document['presentation'] );
	}

	public function test_compiler_preserves_maintenance_semantics_and_explicit_locale(): void {
		$html = $this->html();
		self::assertStringContainsString( '<html lang="en-GB">', $html );
		self::assertStringContainsString( 'Maintenance Report', $html );
		self::assertStringContainsString( 'Maintenance requires attention', $html );
		self::assertStringContainsString( 'Updates Performed', $html );
		self::assertStringContainsString( 'Security Issues', $html );
		self::assertStringContainsString( 'Current State', $html );
		self::assertStringContainsString( 'Notes / Observations', $html );
		self::assertStringContainsString( 'Maintenance Details', $html );
		self::assertStringContainsString( 'Theme updates (1)', $html );
		self::assertStringContainsString( 'Plugin updates (5)', $html );
		self::assertStringContainsString( '>From<', $html );
		self::assertStringContainsString( '>To<', $html );
		self::assertStringContainsString( 'Performed By', $html );
		self::assertStringContainsString( 'Showing the newest 2 of 5 recorded actions.', $html );
		self::assertStringContainsString( 'Security Activity', $html );
		self::assertStringContainsString( 'Backups', $html );
		self::assertStringContainsString( 'Local, Remote', $html );
		self::assertStringContainsString( 'Infused &lt;Agency&gt;', $html );
		self::assertStringContainsString( 'Report generated for example.test', $html );
		self::assertStringContainsString( 'Page <span class="cb-flow-page-number"></span>', $html );
	}

	public function test_security_is_fully_omitted_when_snapshot_security_is_null(): void {
		$html = $this->html( false );
		self::assertStringNotContainsString( 'Security Issues', $html );
		self::assertStringNotContainsString( 'Security Activity', $html );
	}

	public function test_security_null_is_authoritative_over_stale_security_kpi(): void {
		$snapshot = $this->snapshot( false );
		$snapshot['kpis']['security_issues'] = [ 'count' => 99, 'breakdown' => [ 'stale snapshot value' ] ];

		$html = $this->render_snapshot( $snapshot );
		self::assertStringNotContainsString( 'Security Issues', $html );
		self::assertStringNotContainsString( 'stale snapshot value', $html );
		self::assertStringNotContainsString( 'Security Activity', $html );
	}

	public function test_flow_presentation_accent_is_bounded_hex_only(): void {
		self::assertSame( '#0064c8', Presentation::from_accent( '#0064c8' )->accent() );
		self::assertSame( '#aabbcc', Presentation::from_accent( '#abc' )->accent() );

		$this->expectException( InvalidArgumentException::class );
		Presentation::from_accent( '#0064c8;body{display:none}' );
	}

	public function test_page_footer_is_typed_escaped_and_renderer_owns_page_counter(): void {
		$html = ( new HtmlRenderer() )->render(
			MaintenanceFlowCompiler::layout(),
			[
				RenderBlock::text( 'Body' ),
				RenderBlock::page_footer( '<unsafe footer>', '<Page>' ),
			],
			'en_GB'
		);

		self::assertStringContainsString( '&lt;unsafe footer&gt;', $html );
		self::assertStringContainsString( '&lt;Page&gt;', $html );
		self::assertStringContainsString( 'counter(page)', $html );
		self::assertStringNotContainsString( '<unsafe footer>', $html );
		self::assertStringNotContainsString( '<Page>', $html );
	}

	public function test_flow_branding_resolves_local_png_and_safe_fallback_without_remote_fetch(): void {
		$settings = Settings::get();
		$previous_reports = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=', true );
		self::assertIsString( $png );

		$upload = wp_upload_bits( 'cb-r5-maintenance-branding.png', null, $png );
		self::assertSame( '', (string) ( $upload['error'] ?? '' ) );
		self::assertNotEmpty( $upload['file'] ?? '' );

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/png',
				'post_title'     => 'R5 Maintenance branding',
				'post_status'    => 'inherit',
			],
			(string) $upload['file']
		);
		self::assertFalse( is_wp_error( $attachment_id ) );
		$attachment_id = (int) $attachment_id;
		self::assertGreaterThan( 0, $attachment_id );
		update_attached_file( $attachment_id, (string) $upload['file'] );

		try {
			Settings::set_key(
				'reports',
				[
					'branding' => [
						'logo_attachment_id' => $attachment_id,
						'provider_name'       => 'Infused Provider',
						'provider_contact'    => 'hello@example.test',
						'accent_color'        => '#123abc',
					],
				],
				'r5-test'
			);

			$resolved = MaintenanceFlowBranding::resolve();
			self::assertStringStartsWith( 'data:image/png;base64,', $resolved['logo_url'] );
			self::assertSame( '', $resolved['fallback_text'] );
			self::assertSame( 'Infused Provider', $resolved['provider_name'] );
			self::assertSame( 'hello@example.test', $resolved['provider_contact'] );
			self::assertSame( '#123abc', $resolved['accent_color'] );
			$serialized = wp_json_encode( $resolved );
			self::assertIsString( $serialized );
			self::assertStringNotContainsString( 'http://', $serialized );
			self::assertStringNotContainsString( 'https://', $serialized );
			self::assertStringNotContainsString( 'file://', $serialized );

			Settings::set_key(
				'reports',
				[
					'branding' => [
						'logo_attachment_id' => 0,
						'provider_name'       => '',
						'provider_contact'    => '',
						'accent_color'        => '#0064c8',
					],
				],
				'r5-test-fallback'
			);

			$fallback = MaintenanceFlowBranding::resolve();
			if ( '' !== $fallback['logo_url'] ) {
				self::assertStringStartsWith( 'data:image/png;base64,', $fallback['logo_url'] );
				self::assertSame( '', $fallback['fallback_text'] );
			} else {
				self::assertSame( 'Core Blueprint', $fallback['fallback_text'] );
			}
			$serialized = wp_json_encode( $fallback );
			self::assertIsString( $serialized );
			self::assertStringNotContainsString( 'http://', $serialized );
			self::assertStringNotContainsString( 'https://', $serialized );
			self::assertStringNotContainsString( 'file://', $serialized );
		} finally {
			Settings::set_key( 'reports', $previous_reports, 'r5-test-restore' );
			wp_delete_attachment( $attachment_id, true );
		}
	}

	public function test_html_like_snapshot_values_are_escaped_without_execution(): void {
		$html = $this->html();
		self::assertStringContainsString( 'Example &lt;Site&gt;', $html );
		self::assertStringContainsString( '&lt;Critical&gt;', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringContainsString( '&lt;unsafe&gt;', $html );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_snapshot_and_required_metadata_fail_closed(): void {
		$compiler = new MaintenanceFlowCompiler();
		$snapshot = $this->snapshot();

		$invalid = $snapshot;
		$invalid['snapshot_version'] = 999;
		try {
			$compiler->compile( $this->report( $invalid ), $invalid, $this->branding(), 'en_GB' );
			self::fail( 'Unsupported snapshot version was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'version', $error->getMessage() );
		}

		$invalid = $snapshot;
		$invalid['site']['url'] = '';
		try {
			$compiler->compile( $this->report( $invalid ), $invalid, $this->branding(), 'en_GB' );
			self::fail( 'Missing site URL was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'metadata', $error->getMessage() );
		}

		$report = $this->report( $snapshot );
		$report['generated_at'] = '';
		try {
			$compiler->compile( $report, $snapshot, $this->branding(), 'en_GB' );
			self::fail( 'Missing generated_at was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'metadata', $error->getMessage() );
		}

		$this->expectException( RuntimeException::class );
		( new MaintenancePdf() )->render( [ 'report_data' => null ] );
	}

	public function test_flow_pdf_is_a4_portrait_and_server_paginated(): void {
		$snapshot = $this->snapshot();
		$rows = [];
		for ( $i = 1; $i <= 90; $i++ ) {
			$rows[] = [
				'target_name' => 'Plugin ' . $i,
				'version_from' => '1.' . $i,
				'version_to' => '2.' . $i,
				'date' => '2026-08-10 12:00:00',
				'actor' => 'Operator',
				'notes' => 'Maintenance row ' . $i,
			];
		}
		$snapshot['sections']['plugin_updates']['count'] = count( $rows );
		$snapshot['sections']['plugin_updates']['rows'] = $rows;
		$snapshot['sections']['plugin_updates']['truncated'] = false;

		$document = ( new MaintenanceFlowCompiler() )->compile( $this->report( $snapshot ), $snapshot, $this->branding(), 'en_GB' );
		self::assertSame( [ 'width' => 210.0, 'height' => 297.0 ], $document['layout']['page'] );
		$pdf = ( new PdfRenderer() )->render( $document['layout'], $document['blocks'], $document['locale'], $document['presentation'] );
		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThanOrEqual( 2, preg_match_all( '/\/Type\s*\/Page\b/', $pdf ) );
	}

	public function test_r5_dependency_direction_and_renderer_boundary_are_mechanical(): void {
		$root = dirname( __DIR__, 2 );
		$maintenance_pdf = (string) file_get_contents( $root . '/src/Reports/MaintenancePdf.php' );
		$ajax_reports = (string) file_get_contents( $root . '/src/Ajax/Handlers/Reports.php' );
		$compiler = (string) file_get_contents( $root . '/src/Reports/MaintenanceFlowCompiler.php' );
		$branding = (string) file_get_contents( $root . '/src/Reports/MaintenanceFlowBranding.php' );

		self::assertStringNotContainsString( 'CB\\Core\\PDF\\Renderer;', $maintenance_pdf );
		self::assertStringNotContainsString( 'templates/pdf/maintenance-report.php', $maintenance_pdf );
		self::assertFileDoesNotExist( $root . '/templates/pdf/maintenance-report.php' );
		self::assertStringContainsString( 'Document\\Flow\\PdfRenderer', $maintenance_pdf );
		self::assertStringNotContainsString( 'new Renderer()', $ajax_reports );
		self::assertStringContainsString( 'PDF\\Api\\PdfApi', $ajax_reports );
		self::assertStringNotContainsString( 'Dompdf', $compiler );
		self::assertStringNotContainsString( 'PDF\\Renderer', $compiler );
		self::assertStringNotContainsString( 'http://', $branding );
		self::assertStringNotContainsString( 'https://', $branding );

		$flow = $root . '/src/Design/Profile/Document/Flow';
		foreach ( glob( $flow . '/*.php' ) ?: [] as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertStringNotContainsString( 'CB\\Core\\Reports', $source, basename( $file ) );
			self::assertStringNotContainsString( 'MaintenanceAggregator', $source, basename( $file ) );
		}
	}
}
