<?php
declare(strict_types=1);

namespace CB\Core\Reports;

use CB\Core\Design\Profile\Document\Flow\Presentation;
use CB\Core\Design\Profile\Document\Flow\RenderBlock;
use CB\Core\Reports\Composer\BlockCatalog;
use CB\Core\Reports\Composer\MaintenanceTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Reports-owned presentation compiler for Maintenance PDFs.
 *
 * The compiler understands Maintenance semantics, but emits only generic typed
 * Flow blocks. It owns no collection, persistence, lifecycle or PDF backend.
 */
final class MaintenanceFlowCompiler {
	private const SECTION_ORDER = [
		'theme_updates'        => 'theme',
		'plugin_updates'       => 'plugin',
		'plugin_installations' => 'plugin',
		'plugin_removals'      => 'plugin',
		'core_updates'         => 'core',
	];

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $snapshot
	 * @param array<string,mixed> $branding
	 * @param array<string,mixed>|null $template Unsaved Designer template; null resolves persisted state.
	 * @return array{layout:array<string,mixed>,blocks:list<RenderBlock>,locale:string,presentation:Presentation}
	 */
	public function compile( array $report, array $snapshot, array $branding, string $locale, ?array $template = null ): array {
		$this->assert_input( $report, $snapshot, $locale );

		$template = null === $template
			? MaintenanceTemplate::current()
			: MaintenanceTemplate::normalize( $template );
		$blocks = [];

		foreach ( $template['blocks'] as $definition ) {
			if ( empty( $definition['enabled'] ) ) {
				continue;
			}
			$type = (string) ( $definition['type'] ?? '' );
			foreach ( $this->compile_block( $type, $report, $snapshot, $branding ) as $block ) {
				$blocks[] = $block;
			}
		}

		return [
			'layout'       => self::layout(),
			'blocks'       => $blocks,
			'locale'       => $locale,
			'presentation' => Presentation::from_accent( (string) ( $branding['accent_color'] ?? ReportBranding::DEFAULT_ACCENT ) ),
		];
	}

	/**
	 * Dispatch one bounded Reports Composer block into typed Document Flow blocks.
	 *
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $snapshot
	 * @param array<string,mixed> $branding
	 * @return list<RenderBlock>
	 */
	private function compile_block( string $type, array $report, array $snapshot, array $branding ): array {
		return match ( $type ) {
			BlockCatalog::HEADER        => $this->header_blocks( $report, $snapshot, $branding ),
			BlockCatalog::STATUS        => $this->status_blocks( $snapshot ),
			BlockCatalog::KPIS          => $this->kpi_blocks( $snapshot ),
			BlockCatalog::CURRENT_STATE => $this->current_state_blocks( $snapshot ),
			BlockCatalog::ACTIVITY      => $this->maintenance_activity_blocks( $snapshot ),
			BlockCatalog::SUMMARY       => $this->summary_blocks( $snapshot ),
			BlockCatalog::NOTES         => $this->notes_blocks( $snapshot ),
			BlockCatalog::FOOTER        => $this->footer_blocks( $snapshot ),
			default                     => [],
		};
	}

	/** @param array<string,mixed> $report @param array<string,mixed> $snapshot @param array<string,mixed> $branding @return list<RenderBlock> */
	private function header_blocks( array $report, array $snapshot, array $branding ): array {
		$site   = is_array( $snapshot['site'] ?? null ) ? $snapshot['site'] : [];
		$blocks = [];
		$logo   = (string) ( $branding['logo_url'] ?? '' );

		if ( '' !== $logo ) {
			$blocks[] = RenderBlock::image( $logo, [ 'space_after' => 4.0, 'keep_together' => true ] );
		} elseif ( '' !== (string) ( $branding['fallback_text'] ?? '' ) ) {
			$blocks[] = RenderBlock::text( (string) $branding['fallback_text'], [ 'space_after' => 2.0 ] );
		}

		$blocks[] = RenderBlock::text(
			__( 'Maintenance Report', 'core-blueprint' ) . "\n"
			. (string) ( $site['title'] ?? '' ) . "\n"
			. (string) ( $site['url'] ?? '' ),
			[ 'space_after' => 4.0, 'keep_together' => true ]
		);
		$blocks[] = RenderBlock::text( $this->metadata_text( $report, $branding ), [ 'space_after' => 5.0, 'keep_together' => true ] );
		return $blocks;
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function status_blocks( array $snapshot ): array {
		return [ RenderBlock::text( $this->status_text( $snapshot ), [ 'space_after' => 5.0, 'keep_together' => true ] ) ];
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function kpi_blocks( array $snapshot ): array {
		$kpi = $this->kpi_table( $snapshot );
		if ( null === $kpi ) {
			return [];
		}
		return [
			RenderBlock::text( __( 'Maintenance summary', 'core-blueprint' ), [ 'space_after' => 1.0 ] ),
			$kpi,
		];
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function current_state_blocks( array $snapshot ): array {
		$state = $this->site_state_table( $snapshot );
		if ( null === $state ) {
			return [];
		}
		return [
			RenderBlock::text( __( 'Current State', 'core-blueprint' ), [ 'space_before' => 5.0, 'space_after' => 1.0 ] ),
			$state,
		];
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function maintenance_activity_blocks( array $snapshot ): array {
		return array_merge(
			[
				RenderBlock::text(
					__( 'Maintenance Details', 'core-blueprint' ) . "\n" . __( 'Overview of all maintenance actions performed in this period.', 'core-blueprint' ),
					[ 'break_before' => true, 'space_after' => 3.0 ]
				),
			],
			$this->activity_blocks( $snapshot )
		);
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function notes_blocks( array $snapshot ): array {
		$notes = $snapshot['notes'] ?? [];
		if ( ! is_array( $notes ) || [] === $notes ) {
			return [];
		}

		$note_blocks = [ RenderBlock::text( __( 'Notes / Observations', 'core-blueprint' ), [ 'space_after' => 1.0 ] ) ];
		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) ) {
				continue;
			}
			$note_blocks[] = RenderBlock::text(
				$this->status_glyph( (string) ( $note['type'] ?? 'ok' ) ) . ' ' . (string) ( $note['title'] ?? '' ) . "\n" . (string) ( $note['body'] ?? '' ),
				[ 'space_after' => 2.0, 'keep_together' => true ]
			);
		}
		return [ RenderBlock::container( $note_blocks, [ 'space_before' => 5.0 ] ) ];
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function footer_blocks( array $snapshot ): array {
		$site      = is_array( $snapshot['site'] ?? null ) ? $snapshot['site'] : [];
		$site_url  = (string) ( $site['url'] ?? '' );
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		if ( ! is_string( $site_host ) || '' === $site_host ) {
			$site_host = (string) preg_replace( '#^https?://#i', '', $site_url );
		}
		return [
			RenderBlock::page_footer(
				sprintf(
					/* translators: %s: site host (e.g., example.nl). */
					__( 'Report generated for %s', 'core-blueprint' ),
					$site_host
				),
				__( 'Page', 'core-blueprint' )
			),
		];
	}

	/** @param array<string,mixed> $report @param array<string,mixed> $snapshot */
	private function assert_input( array $report, array $snapshot, string $locale ): void {
		if ( MaintenanceAggregator::SNAPSHOT_VERSION !== (int) ( $snapshot['snapshot_version'] ?? 0 ) ) {
			throw new \RuntimeException( 'Maintenance report snapshot version is unsupported.' );
		}
		$site = is_array( $snapshot['site'] ?? null ) ? $snapshot['site'] : [];
		if ( '' === trim( (string) ( $site['url'] ?? '' ) ) || '' === trim( (string) ( $report['generated_at'] ?? '' ) ) ) {
			throw new \RuntimeException( 'Maintenance report snapshot metadata is incomplete.' );
		}
		if ( '' === trim( $locale ) ) {
			throw new \RuntimeException( 'Maintenance report render locale is missing.' );
		}
	}

	/** @param array<string,mixed> $report @param array<string,mixed> $branding */
	private function metadata_text( array $report, array $branding ): string {
		$lines = [
			__( 'Period', 'core-blueprint' ) . ': ' . (string) ( $report['period_start'] ?? '' ) . ' – ' . (string) ( $report['period_end'] ?? '' ),
			__( 'Generated', 'core-blueprint' ) . ': ' . get_date_from_gmt( (string) $report['generated_at'], 'd-m-Y H:i' ),
		];
		if ( '' !== (string) ( $branding['provider_name'] ?? '' ) ) {
			$lines[] = __( 'Prepared by', 'core-blueprint' ) . ': ' . (string) $branding['provider_name'];
		}
		if ( '' !== (string) ( $branding['provider_contact'] ?? '' ) ) {
			$lines[] = __( 'Contact', 'core-blueprint' ) . ': ' . (string) $branding['provider_contact'];
		}
		return implode( "\n", $lines );
	}

	/** @param array<string,mixed> $snapshot */
	private function status_text( array $snapshot ): string {
		$status = is_array( $snapshot['status'] ?? null ) ? $snapshot['status'] : [];
		$level = (string) ( $status['banner'] ?? 'ok' );
		return strtoupper( $level ) . "\n"
			. (string) ( $status['headline'] ?? '' ) . "\n"
			. (string) ( $status['subline'] ?? '' ) . "\n"
			. (string) ( $status['detail_headline'] ?? '' ) . "\n"
			. (string) ( $status['detail_subline'] ?? '' );
	}

	/** @param array<string,mixed> $snapshot */
	private function kpi_table( array $snapshot ): ?RenderBlock {
		$kpis = is_array( $snapshot['kpis'] ?? null ) ? $snapshot['kpis'] : [];
		$security_available = is_array( $snapshot['security'] ?? null );
		$order = [
			'updates_performed' => __( 'Updates Performed', 'core-blueprint' ),
			'updates_pending'   => __( 'Updates Pending', 'core-blueprint' ),
			'security_issues'   => __( 'Security Issues', 'core-blueprint' ),
			'backups_created'   => __( 'Backups Created', 'core-blueprint' ),
			'active_users'      => __( 'Active Users', 'core-blueprint' ),
		];
		$headers = [];
		$values = [];
		foreach ( $order as $key => $label ) {
			if ( 'security_issues' === $key && ! $security_available ) { continue; }
			if ( ! isset( $kpis[ $key ] ) || ! is_array( $kpis[ $key ] ) ) { continue; }
			$headers[] = $label;
			$breakdown = array_values( array_filter( array_map( 'strval', (array) ( $kpis[ $key ]['breakdown'] ?? [] ) ), static fn ( string $line ): bool => '' !== $line ) );
			$value = (string) (int) ( $kpis[ $key ]['count'] ?? 0 );
			if ( [] !== $breakdown ) { $value .= ' — ' . implode( '; ', $breakdown ); }
			$values[] = $value;
		}
		return [] === $headers ? null : RenderBlock::table( $headers, [ $values ], [ 'keep_together' => true ] );
	}

	/** @param array<string,mixed> $snapshot */
	private function site_state_table( array $snapshot ): ?RenderBlock {
		$state = is_array( $snapshot['site_state'] ?? null ) ? $snapshot['site_state'] : [];
		$rows = [];
		foreach ( [ 'wp_core', 'theme', 'plugins', 'php', 'database', 'website' ] as $key ) {
			$item = $state[ $key ] ?? null;
			if ( ! is_array( $item ) ) { continue; }
			$rows[] = [
				(string) ( $item['label'] ?? '' ),
				$this->status_glyph( (string) ( $item['status'] ?? 'ok' ) ) . ' ' . (string) ( $item['state'] ?? '' ),
				(string) ( $item['detail'] ?? '' ),
			];
		}
		return [] === $rows ? null : RenderBlock::table(
			[ __( 'Component', 'core-blueprint' ), __( 'Status', 'core-blueprint' ), __( 'Notes', 'core-blueprint' ) ],
			$rows
		);
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function activity_blocks( array $snapshot ): array {
		$sections = is_array( $snapshot['sections'] ?? null ) ? $snapshot['sections'] : [];
		$blocks = [];
		$any = false;
		foreach ( self::SECTION_ORDER as $key => $kind ) {
			$section = $sections[ $key ] ?? null;
			if ( ! is_array( $section ) || (int) ( $section['count'] ?? 0 ) <= 0 ) { continue; }
			$any = true;
			$columns = array_values( array_filter( (array) ( $section['columns'] ?? [] ), 'is_string' ) );
			if ( [] === $columns ) { $columns = [ 'target_name', 'version_to', 'date', 'actor' ]; }
			$headers = array_map( fn ( string $column ): string => $this->activity_label( $column, $columns, $kind ), $columns );
			$rows = [];
			foreach ( (array) ( $section['rows'] ?? [] ) as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$rendered = [];
				foreach ( $columns as $column ) {
					$value = (string) ( $row[ $column ] ?? '' );
					if ( 'date' === $column && '' !== $value ) {
						$timestamp = strtotime( $value . ' UTC' );
						if ( false !== $timestamp ) { $value = wp_date( 'd-m-Y H:i', $timestamp, wp_timezone() ); }
					}
					if ( in_array( $column, [ 'version_from', 'version_to' ], true ) && '' === $value ) { $value = '-'; }
					$rendered[] = $value;
				}
				$rows[] = $rendered;
			}
			$blocks[] = RenderBlock::text( (string) ( $section['title'] ?? '' ) . ' (' . (int) $section['count'] . ')', [ 'space_before' => 4.0, 'space_after' => 1.0 ] );
			$blocks[] = RenderBlock::table( $headers, $rows );
			if ( ! empty( $section['truncated'] ) ) {
				$blocks[] = RenderBlock::text( sprintf(
					/* translators: %1$d: rows shown, %2$d: total matching actions. */
					__( 'Showing the newest %1$d of %2$d recorded actions.', 'core-blueprint' ),
					count( $rows ),
					(int) $section['count']
				), [ 'space_after' => 2.0 ] );
			}
		}
		if ( ! $any ) {
			$blocks[] = RenderBlock::text( __( 'No maintenance activity recorded in this period.', 'core-blueprint' ) );
		}
		return $blocks;
	}

	/** @param list<string> $columns */
	private function activity_label( string $column, array $columns, string $kind ): string {
		$target = 'core' === $kind ? __( 'Component', 'core-blueprint' ) : ( 'theme' === $kind ? __( 'Theme', 'core-blueprint' ) : __( 'Plugin', 'core-blueprint' ) );
		$labels = [
			'target_name'  => $target,
			'version_from' => __( 'From', 'core-blueprint' ),
			'version_to'   => in_array( 'version_from', $columns, true ) ? __( 'To', 'core-blueprint' ) : __( 'Version', 'core-blueprint' ),
			'date'         => __( 'Date', 'core-blueprint' ),
			'actor'        => __( 'Performed By', 'core-blueprint' ),
			'notes'        => __( 'Notes', 'core-blueprint' ),
		];
		return $labels[ $column ] ?? $column;
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function summary_blocks( array $snapshot ): array {
		$blocks = [];
		$security = $snapshot['security'] ?? null;
		if ( is_array( $security ) ) {
			$lines = [ __( 'Security Activity', 'core-blueprint' ) ];
			if ( 0 === (int) ( $security['detected'] ?? 0 ) ) {
				$lines[] = (string) ( $security['summary'] ?? '' );
			} else {
				$lines[] = sprintf(
					/* translators: %d: number of security issues. */
					_n( '%d security issue detected.', '%d security issues detected.', (int) $security['detected'], 'core-blueprint' ),
					(int) $security['detected']
				);
			}
			if ( null !== ( $security['blocked_attempts'] ?? null ) ) {
				$lines[] = sprintf(
					/* translators: %d: number of blocked login attempts. */
					_n( '%d login attempt was blocked by the firewall.', '%d login attempts were blocked by the firewall.', (int) $security['blocked_attempts'], 'core-blueprint' ),
					(int) $security['blocked_attempts']
				);
			}
			if ( 0 === (int) ( $security['brute_force'] ?? 0 ) ) { $lines[] = __( 'No successful brute force attacks.', 'core-blueprint' ); }
			$blocks[] = RenderBlock::text( implode( "\n", $lines ), [ 'space_before' => 5.0, 'keep_together' => true ] );
		}

		$backups = is_array( $snapshot['backups'] ?? null ) ? $snapshot['backups'] : [];
		$backup_lines = [ __( 'Backups', 'core-blueprint' ) ];
		$count = (int) ( $backups['count'] ?? 0 );
		$backup_lines[] = sprintf(
			/* translators: %d: number of backups created. */
			_n( '%d backup was created in this period.', '%d backups were created in this period.', $count, 'core-blueprint' ),
			$count
		);
		$last = (string) ( $backups['last_at'] ?? '' );
		if ( '' === $last ) { $last = (string) ( $backups['last_at_overall'] ?? '' ); }
		if ( '' !== $last ) {
			$timestamp = strtotime( $last . ' UTC' );
			$display = false === $timestamp ? $last : wp_date( 'd-m-Y H:i', $timestamp, wp_timezone() );
			$backup_lines[] = sprintf( __( 'Last backup: %s', 'core-blueprint' ), $display );
		}
		$providers = array_values( array_filter( array_map( 'strval', (array) ( $backups['providers'] ?? [] ) ) ) );
		if ( [] !== $providers ) { $backup_lines[] = implode( ', ', $providers ); }
		if ( '' !== (string) ( $backups['summary'] ?? '' ) ) { $backup_lines[] = (string) $backups['summary']; }
		$blocks[] = RenderBlock::text( implode( "\n", $backup_lines ), [ 'space_before' => 4.0, 'keep_together' => true ] );
		return $blocks;
	}

	private function status_glyph( string $level ): string {
		return match ( $level ) {
			'critical' => '×',
			'warn'     => '!',
			'info'     => 'i',
			default    => '✓',
		};
	}

	/** @return array<string,mixed> */
	public static function layout(): array {
		return [
			'mode'    => 'flow',
			'units'   => 'mm',
			'page'    => [ 'width' => 210.0, 'height' => 297.0 ],
			'margins' => [ 'top' => 12.0, 'right' => 12.0, 'bottom' => 15.0, 'left' => 12.0 ],
		];
	}
}
