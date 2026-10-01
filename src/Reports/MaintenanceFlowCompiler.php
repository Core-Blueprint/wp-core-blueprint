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
	private const COMPOSER_CONTENT_GAP_MM = 5.0;
	private const COMPOSER_CONTENT_TYPES  = [
		BlockCatalog::STATUS,
		BlockCatalog::KPIS,
		BlockCatalog::CURRENT_STATE,
		BlockCatalog::ACTIVITY,
		BlockCatalog::SUMMARY,
		BlockCatalog::NOTES,
	];

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
	 * @return array{layout:array<string,mixed>,blocks:list<RenderBlock>,locale:string,presentation:Presentation,preview_regions:array<string,list<int>>}
	 */
	public function compile( array $report, array $snapshot, array $branding, string $locale, ?array $template = null ): array {
		$this->assert_input( $report, $snapshot, $locale );

		$template = null === $template
			? MaintenanceTemplate::current()
			: MaintenanceTemplate::normalize( $template );
		$blocks          = [];
		$preview_regions = [];
		$first_content   = true;

		foreach ( $template['blocks'] as $definition ) {
			if ( empty( $definition['enabled'] ) ) {
				continue;
			}
			$type     = (string) ( $definition['type'] ?? '' );
			$settings = is_array( $definition['settings'] ?? null ) ? $definition['settings'] : [];
			$compiled = $this->compile_block( $type, $report, $snapshot, $branding, $settings );
			if ( [] !== $compiled && self::is_composer_content_type( $type ) ) {
				$compiled      = [ $this->composer_content_block( $compiled, $first_content ) ];
				$first_content = false;
			}
			$indexes = [];
			foreach ( $compiled as $block ) {
				$indexes[] = count( $blocks );
				$blocks[]  = $block;
			}
			if ( [] !== $indexes ) {
				$preview_regions[ $type ] = $indexes;
			}
		}

		return [
			'layout'          => self::layout(),
			'blocks'          => $blocks,
			'locale'          => $locale,
			'presentation'    => Presentation::from_values(
				(string) ( $branding['accent_color'] ?? ReportBranding::DEFAULT_ACCENT ),
				(string) ( $branding['surface_style'] ?? 'cards' ),
				(string) ( $branding['density'] ?? 'comfortable' ),
				(string) ( $branding['corner_style'] ?? 'soft' ),
				(string) ( $branding['text_scale'] ?? 'standard' )
			),
			'preview_regions' => $preview_regions,
		];
	}

	private static function is_composer_content_type( string $type ): bool {
		return in_array( $type, self::COMPOSER_CONTENT_TYPES, true );
	}

	/**
	 * Wrap one visible Composer element in a layout-neutral Flow boundary.
	 *
	 * The Composer owns spacing between reorderable elements. Individual report
	 * blocks keep only their internal rhythm, so order and visibility cannot
	 * accidentally create larger or smaller gaps.
	 *
	 * @param list<RenderBlock> $children
	 */
	private function composer_content_block( array $children, bool $first_content ): RenderBlock {
		$last_index   = count( $children ) - 1;
		$first_hints  = $children[0]->hints();
		$break_before = $first_hints['break_before'];
		$first_hints['space_before'] = 0.0;
		$first_hints['break_before'] = false;
		$children[0] = $children[0]->with_hints( $first_hints );

		$last_hints  = $children[ $last_index ]->hints();
		$break_after = $last_hints['break_after'];
		$last_hints['space_after'] = 0.0;
		$last_hints['break_after'] = false;
		$children[ $last_index ] = $children[ $last_index ]->with_hints( $last_hints );

		return RenderBlock::container(
			$children,
			[
				'space_before' => $first_content ? 0.0 : self::COMPOSER_CONTENT_GAP_MM,
				'break_before' => $break_before,
				'break_after'  => $break_after,
			]
		);
	}

	/**
	 * Dispatch one bounded Reports Composer block into typed Document Flow blocks.
	 *
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $snapshot
	 * @param array<string,mixed> $branding
	 * @return list<RenderBlock>
	 */
	private function compile_block( string $type, array $report, array $snapshot, array $branding, array $settings ): array {
		return match ( $type ) {
			BlockCatalog::HEADER        => $this->header_blocks( $report, $snapshot, $branding, $settings ),
			BlockCatalog::STATUS        => $this->status_blocks( $snapshot, $settings ),
			BlockCatalog::KPIS          => $this->kpi_blocks( $snapshot, $settings ),
			BlockCatalog::CURRENT_STATE => $this->current_state_blocks( $snapshot, $settings ),
			BlockCatalog::ACTIVITY      => $this->maintenance_activity_blocks( $snapshot, $settings ),
			BlockCatalog::SUMMARY       => $this->summary_blocks( $snapshot, $settings ),
			BlockCatalog::NOTES         => $this->notes_blocks( $snapshot, $settings ),
			BlockCatalog::FOOTER        => $this->footer_blocks( $snapshot, $settings ),
			default                     => [],
		};
	}

	/** @param array<string,mixed> $report @param array<string,mixed> $snapshot @param array<string,mixed> $branding @return list<RenderBlock> */
	private function header_blocks( array $report, array $snapshot, array $branding, array $settings ): array {
		$site          = is_array( $snapshot['site'] ?? null ) ? $snapshot['site'] : [];
		$identity      = [];
		$logo          = (string) ( $branding['logo_url'] ?? '' );
		$show_site_url = self::setting( $settings, 'show_site_url', true );
		$show_metadata = self::setting( $settings, 'show_metadata', true );

		if ( '' !== $logo ) {
			$identity[] = RenderBlock::image( $logo, [ 'space_after' => 3.0, 'keep_together' => true ] );
		} elseif ( '' !== (string) ( $branding['fallback_text'] ?? '' ) ) {
			$identity[] = RenderBlock::text( (string) $branding['fallback_text'], [ 'space_after' => 2.0 ] );
		}

		$identity[] = RenderBlock::heading( __( 'Maintenance Report', 'core-blueprint' ), 'title', [ 'space_after' => 1.5 ] );
		$site_lines = [ (string) ( $site['title'] ?? '' ) ];
		if ( $show_site_url ) {
			$site_lines[] = (string) ( $site['url'] ?? '' );
		}
		$site_lines = array_values( array_filter(
			$site_lines,
			static fn ( string $line ): bool => '' !== trim( $line )
		) );
		if ( [] !== $site_lines ) {
			$identity[] = RenderBlock::text( implode( "\n", $site_lines ) );
		}

		$blocks = [];
		if ( $show_metadata ) {
			$blocks[] = RenderBlock::columns(
				[
					$identity,
					[ RenderBlock::text( $this->metadata_text( $report, $branding ) ) ],
				],
				[ 1.6, 1.0 ],
				[ 'space_after' => 3.0, 'keep_together' => true ]
			);
		} else {
			$blocks = $identity;
		}
		$blocks[] = RenderBlock::rule( [ 'space_after' => 5.0 ] );
		return $blocks;
	}
	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function status_blocks( array $snapshot, array $settings ): array {
		$status = is_array( $snapshot['status'] ?? null ) ? $snapshot['status'] : [];
		$level  = (string) ( $status['banner'] ?? 'ok' );
		$title  = trim( (string) ( $status['headline'] ?? '' ) );
		if ( '' === $title ) {
			$title = __( 'Status', 'core-blueprint' );
		}

		$body = [ strtoupper( $level ) ];
		if ( self::setting( $settings, 'show_details', true ) ) {
			$body[] = (string) ( $status['subline'] ?? '' );
			$body[] = (string) ( $status['detail_headline'] ?? '' );
			$body[] = (string) ( $status['detail_subline'] ?? '' );
		}
		$body = array_values( array_filter(
			$body,
			static fn ( string $line ): bool => '' !== trim( $line )
		) );

		return [
			RenderBlock::callout(
				$title,
				implode( "\n", $body ),
				$this->tone_for_status( $level ),
				[ 'space_after' => 5.0, 'keep_together' => true ]
			),
		];
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function kpi_blocks( array $snapshot, array $settings ): array {
		$metrics = $this->kpi_metrics( $snapshot, self::setting( $settings, 'show_details', true ) );
		if ( null === $metrics ) {
			return [];
		}
		return [
			RenderBlock::heading( __( 'Maintenance summary', 'core-blueprint' ), 'section', [ 'space_after' => 2.0 ] ),
			$metrics,
		];
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function current_state_blocks( array $snapshot, array $settings ): array {
		$state = $this->site_state_table( $snapshot, self::setting( $settings, 'show_notes', true ) );
		if ( null === $state ) {
			return [];
		}
		return [
			RenderBlock::heading( __( 'Current State', 'core-blueprint' ), 'section', [ 'space_before' => 5.0, 'space_after' => 2.0 ] ),
			$state,
		];
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function maintenance_activity_blocks( array $snapshot, array $settings ): array {
		$blocks = [
			RenderBlock::heading(
				__( 'Maintenance Details', 'core-blueprint' ),
				'section',
				[ 'break_before' => true, 'space_after' => 1.5 ]
			),
		];
		if ( self::setting( $settings, 'show_intro', true ) ) {
			$blocks[] = RenderBlock::text(
				__( 'Overview of all maintenance actions performed in this period.', 'core-blueprint' ),
				[ 'space_after' => 3.0 ]
			);
		}
		return array_merge( $blocks, $this->activity_blocks( $snapshot ) );
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function notes_blocks( array $snapshot, array $settings ): array {
		$notes = $snapshot['notes'] ?? [];
		if ( ! is_array( $notes ) || [] === $notes ) {
			return [];
		}

		$blocks = [];
		if ( self::setting( $settings, 'show_heading', true ) ) {
			$blocks[] = RenderBlock::heading( __( 'Notes / Observations', 'core-blueprint' ), 'section', [ 'space_before' => 5.0, 'space_after' => 2.0 ] );
		}
		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) ) {
				continue;
			}
			$title = trim( (string) ( $note['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = __( 'Notes', 'core-blueprint' );
			}
			$blocks[] = RenderBlock::callout(
				$title,
				(string) ( $note['body'] ?? '' ),
				$this->tone_for_status( (string) ( $note['type'] ?? 'ok' ) ),
				[ 'space_after' => 2.0, 'keep_together' => true ]
			);
		}
		return $blocks;
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function footer_blocks( array $snapshot, array $settings ): array {
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
				__( 'Page', 'core-blueprint' ),
				self::setting( $settings, 'show_page_number', true )
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
	private function kpi_metrics( array $snapshot, bool $show_details = true ): ?RenderBlock {
		$kpis               = is_array( $snapshot['kpis'] ?? null ) ? $snapshot['kpis'] : [];
		$security_available = is_array( $snapshot['security'] ?? null );
		$order = [
			'updates_performed' => __( 'Updates Performed', 'core-blueprint' ),
			'updates_pending'   => __( 'Updates Pending', 'core-blueprint' ),
			'security_issues'   => __( 'Security Issues', 'core-blueprint' ),
			'backups_created'   => __( 'Backups Created', 'core-blueprint' ),
			'active_users'      => __( 'Active Users', 'core-blueprint' ),
		];
		$items = [];
		foreach ( $order as $key => $label ) {
			if ( 'security_issues' === $key && ! $security_available ) {
				continue;
			}
			if ( ! isset( $kpis[ $key ] ) || ! is_array( $kpis[ $key ] ) ) {
				continue;
			}
			$breakdown = array_values( array_filter(
				array_map( 'strval', (array) ( $kpis[ $key ]['breakdown'] ?? [] ) ),
				static fn ( string $line ): bool => '' !== trim( $line )
			) );
			$items[] = [
				'label'  => $label,
				'value'  => (string) (int) ( $kpis[ $key ]['count'] ?? 0 ),
				'detail' => $show_details ? implode( '; ', $breakdown ) : '',
			];
		}
		return [] === $items ? null : RenderBlock::metrics( $items, [ 'space_after' => 2.0, 'keep_together' => true ] );
	}

	/** @param array<string,mixed> $snapshot */
	private function site_state_table( array $snapshot, bool $show_notes = true ): ?RenderBlock {
		$state = is_array( $snapshot['site_state'] ?? null ) ? $snapshot['site_state'] : [];
		$rows  = [];
		foreach ( [ 'wp_core', 'theme', 'plugins', 'php', 'database', 'website' ] as $key ) {
			$item = $state[ $key ] ?? null;
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row = [
				(string) ( $item['label'] ?? '' ),
				$this->status_glyph( (string) ( $item['status'] ?? 'ok' ) ) . ' ' . (string) ( $item['state'] ?? '' ),
			];
			if ( $show_notes ) {
				$row[] = (string) ( $item['detail'] ?? '' );
			}
			$rows[] = $row;
		}
		$headers = [ __( 'Component', 'core-blueprint' ), __( 'Status', 'core-blueprint' ) ];
		if ( $show_notes ) {
			$headers[] = __( 'Notes', 'core-blueprint' );
		}
		return [] === $rows ? null : RenderBlock::table( $headers, $rows );
	}

	/** @param array<string,mixed> $snapshot @return list<RenderBlock> */
	private function activity_blocks( array $snapshot ): array {
		$sections = is_array( $snapshot['sections'] ?? null ) ? $snapshot['sections'] : [];
		$blocks   = [];
		$any      = false;
		foreach ( self::SECTION_ORDER as $key => $kind ) {
			$section = $sections[ $key ] ?? null;
			if ( ! is_array( $section ) || (int) ( $section['count'] ?? 0 ) <= 0 ) {
				continue;
			}
			$any     = true;
			$columns = array_values( array_filter( (array) ( $section['columns'] ?? [] ), 'is_string' ) );
			if ( [] === $columns ) {
				$columns = [ 'target_name', 'version_to', 'date', 'actor' ];
			}
			$headers = array_map( fn ( string $column ): string => $this->activity_label( $column, $columns, $kind ), $columns );
			$rows    = [];
			foreach ( (array) ( $section['rows'] ?? [] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rendered = [];
				foreach ( $columns as $column ) {
					$value = (string) ( $row[ $column ] ?? '' );
					if ( 'date' === $column && '' !== $value ) {
						$timestamp = strtotime( $value . ' UTC' );
						if ( false !== $timestamp ) {
							$value = wp_date( 'd-m-Y H:i', $timestamp, wp_timezone() );
						}
					}
					if ( in_array( $column, [ 'version_from', 'version_to' ], true ) && '' === $value ) {
						$value = '-';
					}
					$rendered[] = $value;
				}
				$rows[] = $rendered;
			}
			$blocks[] = RenderBlock::heading(
				(string) ( $section['title'] ?? '' ) . ' (' . (int) $section['count'] . ')',
				'subsection',
				[ 'space_before' => 4.0, 'space_after' => 1.0 ]
			);
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
	private function summary_blocks( array $snapshot, array $settings ): array {
		$blocks        = [];
		$show_security = self::setting( $settings, 'show_security', true );
		$show_backups  = self::setting( $settings, 'show_backups', true );
		$security      = $snapshot['security'] ?? null;
		if ( $show_security && is_array( $security ) ) {
			$security_lines = [];
			$detected       = (int) ( $security['detected'] ?? 0 );
			if ( 0 === $detected ) {
				if ( '' !== trim( (string) ( $security['summary'] ?? '' ) ) ) {
					$security_lines[] = (string) $security['summary'];
				}
			} else {
				$security_lines[] = sprintf(
					/* translators: %d: number of security issues. */
					_n( '%d security issue detected.', '%d security issues detected.', $detected, 'core-blueprint' ),
					$detected
				);
			}
			if ( null !== ( $security['blocked_attempts'] ?? null ) ) {
				$security_lines[] = sprintf(
					/* translators: %d: number of blocked login attempts. */
					_n( '%d login attempt was blocked by the firewall.', '%d login attempts were blocked by the firewall.', (int) $security['blocked_attempts'], 'core-blueprint' ),
					(int) $security['blocked_attempts']
				);
			}
			if ( 0 === (int) ( $security['brute_force'] ?? 0 ) ) {
				$security_lines[] = __( 'No successful brute force attacks.', 'core-blueprint' );
			}
			$blocks[] = RenderBlock::callout(
				__( 'Security Activity', 'core-blueprint' ),
				implode( "\n", $security_lines ),
				$detected > 0 ? 'critical' : 'success',
				[ 'space_before' => 5.0, 'space_after' => 2.0, 'keep_together' => true ]
			);
		}

		if ( ! $show_backups ) {
			return $blocks;
		}

		$backups      = is_array( $snapshot['backups'] ?? null ) ? $snapshot['backups'] : [];
		$backup_lines = [];
		$count        = (int) ( $backups['count'] ?? 0 );
		$backup_lines[] = sprintf(
			/* translators: %d: number of backups created. */
			_n( '%d backup was created in this period.', '%d backups were created in this period.', $count, 'core-blueprint' ),
			$count
		);
		$last = (string) ( $backups['last_at'] ?? '' );
		if ( '' === $last ) {
			$last = (string) ( $backups['last_at_overall'] ?? '' );
		}
		if ( '' !== $last ) {
			$timestamp      = strtotime( $last . ' UTC' );
			$display        = false === $timestamp ? $last : wp_date( 'd-m-Y H:i', $timestamp, wp_timezone() );
			$backup_lines[] = sprintf( __( 'Last backup: %s', 'core-blueprint' ), $display );
		}
		$providers = array_values( array_filter( array_map( 'strval', (array) ( $backups['providers'] ?? [] ) ) ) );
		if ( [] !== $providers ) {
			$backup_lines[] = implode( ', ', $providers );
		}
		if ( '' !== (string) ( $backups['summary'] ?? '' ) ) {
			$backup_lines[] = (string) $backups['summary'];
		}
		$blocks[] = RenderBlock::callout(
			__( 'Backups', 'core-blueprint' ),
			implode( "\n", $backup_lines ),
			'neutral',
			[ 'space_after' => 2.0, 'keep_together' => true ]
		);
		return $blocks;
	}

	/** @param array<string,mixed> $settings */
	private static function setting( array $settings, string $key, bool $default ): bool {
		return array_key_exists( $key, $settings ) ? (bool) $settings[ $key ] : $default;
	}

	private function tone_for_status( string $level ): string {
		return match ( strtolower( trim( $level ) ) ) {
			'critical', 'error' => 'critical',
			'warn', 'warning'   => 'warning',
			'info'              => 'info',
			default             => 'success',
		};
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
