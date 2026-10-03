<?php
declare(strict_types=1);
/**
 * OverviewTab - built-in Overview tab for the Logs page.
 *
 * First tab on the Logs page; wayfinding + status snapshot for the full
 * logging stack. Renders via the shared {@see \CoreBlueprint\Core\Admin\Overview}
 * helper so the visual grammar stays consistent with Safeguards and
 * Preferences Overview tabs.
 *
 * Tab-cards are built dynamically from the {@see TabRegistry} after all
 * tabs have registered - so if Beacon adds its Connection tab (only when
 * paired), the card appears automatically; when unpaired it doesn't.
 * Self-exclusion: the Overview tab itself is filtered out of the card
 * grid so users aren't pointed back at the page they're already on.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin\Pages\Logs\Tabs;

use CoreBlueprint\Core\Governance\RetentionPolicy;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\Overview;
use CoreBlueprint\Core\Admin\Pages\Logs\TabRegistry;
use CoreBlueprint\Core\Admin\TabNav;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Log\Retention;

defined( 'ABSPATH' ) || exit;

final class OverviewTab {

	public const SLUG = 'overview';

	/**
	 * Short descriptive copy per built-in tab slug, keyed for the
	 * tab-cards grid. Kept here (rather than on each tab class) because
	 * the Overview wants consistent one-sentence UI copy and each tab
	 * class's renderer is concerned with rendering content, not with
	 * how a sibling tab describes it. Unknown slugs fall back to the
	 * tab's registered label alone.
	 */
	private const TAB_DESCRIPTIONS = [
		AuditTab::SLUG       => 'Security and configuration events - who did what, when, and from where. The primary forensic trail for the site.',
		SystemTab::SLUG      => 'Low-level system events from WordPress and the server - useful when something odd happens and the audit log alone does not explain it.',
		MaintenanceTab::SLUG => 'Maintenance activity for client reporting - updates, backups, and scheduled housekeeping. Shareable with clients who want to see what has been done.',
		AIActivityTab::SLUG  => 'Metadata-first activity for WordPress abilities, agents and machine integrations without capturing raw prompts or responses by default.',
		RetentionTab::SLUG   => 'How long events are kept and which severities trigger email alerts. The retention cron runs daily.',
		// Beacon's Connection tab registers itself as 'connection'.
		'connection'         => 'Beacon black-box recorder - request trail for Hub-paired sites showing what the Hub asked for and how this site answered.',
	];

	private const TAB_ICONS = [
		AuditTab::SLUG       => 'shield',
		SystemTab::SLUG      => 'code',
		MaintenanceTab::SLUG => 'file',
		AIActivityTab::SLUG  => 'eye',
		RetentionTab::SLUG   => 'clock',
		'mail'               => 'mail',
		'connection'         => 'arrow-right',
	];

	/**
	 * Tab renderer. Called by the Logs page dispatch after tab resolution.
	 *
	 * @param string $tab        Resolved active-tab slug ('overview' here).
	 * @param array  $tab_labels Map of tab-slug → translated label.
	 */
	public static function render( string $tab, array $tab_labels ): void {
		$logs_url = admin_url( 'admin.php?page=' . Admin::LOGS_SLUG );

		// Build tab cards from the TabRegistry so this stays in sync with
		// whatever is actually registered (Beacon's Connection tab only
		// appears when paired, etc.). Exclude the Overview tab itself.
		$tab_cards = [];
		foreach ( TabRegistry::visible() as $slug => $spec ) {
			if ( self::SLUG === $slug ) {
				continue;
			}
			$tab_cards[] = [
				'slug'  => $slug,
				'url'   => add_query_arg( 'tab', $slug, $logs_url ),
				'label' => $spec['label'],
				'desc'  => self::description_for( $slug ),
				'icon'  => self::TAB_ICONS[ $slug ] ?? 'admin-generic',
			];
		}

		// Status cards - high-signal snapshot of the logging stack.
		$status_cards = self::status_cards();

		ob_start();
		Overview::render( [
			'title' => __( 'Overview', 'core-blueprint' ),
			'intro' => __( 'A snapshot of what is being logged on this site, plus direct access to every log view. Use the tabs above to jump in, or scan the status cards below for anything that needs attention.', 'core-blueprint' ),

			'status_cards' => $status_cards,
			'tab_cards'    => $tab_cards,

			'quick_actions' => [
				[
					'url'     => add_query_arg( 'tab', AuditTab::SLUG, $logs_url ),
					'label'   => __( 'Open audit log', 'core-blueprint' ),
					'primary' => false,
				],
				[
					'url'     => admin_url( 'admin.php?page=' . \CoreBlueprint\Core\Admin\Pages\Preferences::SLUG . '&tab=privacy' ),
					'label'   => __( 'Edit retention rules', 'core-blueprint' ),
					'primary' => false,
				],
				[
					'url'     => admin_url( 'admin.php?page=' . \CoreBlueprint\Core\Admin\Pages\Preferences::SLUG . '&tab=notifications' ),
					'label'   => __( 'Email notifications', 'core-blueprint' ),
					'primary' => false,
				],
			],
		] );
		$html = ob_get_clean();

		echo TabNav::inject( $html, Admin::LOGS_SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Translate a tab description lookup into a localised string. Kept as
	 * a method (rather than storing __() results in the const array)
	 * because __() cannot be called at compile-time for class constants.
	 */
	private static function description_for( string $slug ): string {
		$map = [
			AuditTab::SLUG       => __( 'Security and configuration events - who did what, when, and from where. The primary forensic trail for the site.', 'core-blueprint' ),
			SystemTab::SLUG      => __( 'Low-level system events from WordPress and the server - useful when something odd happens and the audit log alone does not explain it.', 'core-blueprint' ),
			MaintenanceTab::SLUG => __( 'Maintenance activity for client reporting - updates, backups, and scheduled housekeeping. Shareable with clients who want to see what has been done.', 'core-blueprint' ),
			AIActivityTab::SLUG  => __( 'Metadata-first activity for WordPress abilities, agents and machine integrations without capturing raw prompts or responses by default.', 'core-blueprint' ),
			RetentionTab::SLUG   => __( 'Read-only view of how long each event category is kept. Retention rules are configured in Preferences › Privacy.', 'core-blueprint' ),
			'connection'         => __( 'Beacon black-box recorder - request trail for Hub-paired sites showing what the Hub asked for and how this site answered.', 'core-blueprint' ),
		];
		return $map[ $slug ] ?? '';
	}

	/**
	 * Build the four status cards: critical-24h, total, retention, next-prune.
	 * Each is self-contained so the Overview class doesn't need any
	 * Retention-specific knowledge.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function status_cards(): array {
		$per_category = RetentionPolicy::all();
		$next_prune   = Retention::next_run();
		$logs_url     = admin_url( 'admin.php?page=' . Admin::LOGS_SLUG );

		// Critical events in the last 24h - the signal that matters most.
		$since_24h = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$critical  = AuditLog::query( [
			'severity' => 'critical',
			'since'    => $since_24h,
			'per_page' => 1,
		] );
		$crit_count = (int) ( $critical['total'] ?? 0 );

		// Total retained events - storage footprint proxy.
		$total        = AuditLog::query( [ 'per_page' => 1 ] );
		$total_count  = (int) ( $total['total'] ?? 0 );

		// Retention card summary - one canonical five-category AuditLog policy.
		$configured_count = 0;
		foreach ( RetentionPolicy::CATEGORIES as $category ) {
			if ( (int) ( $per_category[ $category ] ?? 0 ) > 0 ) {
				$configured_count++;
			}
		}
		$retention_value = sprintf(
			/* translators: %d: number of categories with a retention window configured */
			_n( '%d category', '%d categories', $configured_count, 'core-blueprint' ),
			$configured_count
		);

		return [
			[
				'label'  => __( 'Critical (24h)', 'core-blueprint' ),
				'value'  => number_format_i18n( $crit_count ),
				'state'  => $crit_count > 0 ? 'critical' : 'ok',
				'detail' => $crit_count > 0
					? sprintf(
						'<a href="%s">%s</a>',
						esc_url( add_query_arg( [ 'tab' => AuditTab::SLUG, 'severity' => 'critical', 'period' => '24h' ], $logs_url ) ),
						esc_html__( 'Inspect →', 'core-blueprint' )
					)
					: __( 'No critical events today', 'core-blueprint' ),
			],
			[
				'label'  => __( 'Total events', 'core-blueprint' ),
				'value'  => number_format_i18n( $total_count ),
				'detail' => sprintf(
					'<a href="%s">%s</a>',
					esc_url( add_query_arg( 'tab', AuditTab::SLUG, $logs_url ) ),
					esc_html__( 'Open audit log →', 'core-blueprint' )
				),
			],
			[
				'label'  => __( 'Retention', 'core-blueprint' ),
				'value'  => $retention_value,
				'detail' => sprintf(
					'<a href="%s">%s</a>',
					esc_url( add_query_arg( 'tab', RetentionTab::SLUG, $logs_url ) ),
					esc_html__( 'View schedule →', 'core-blueprint' )
				),
			],
			[
				'label'  => __( 'Next prune', 'core-blueprint' ),
				'value'  => $next_prune ? human_time_diff( time(), $next_prune ) : __( 'Not scheduled', 'core-blueprint' ),
				'state'  => $next_prune ? '' : 'warning',
				'detail' => $next_prune
					? esc_html( wp_date( 'Y-m-d H:i', $next_prune ) )
					: __( 'Re-activate the plugin to re-register the cron', 'core-blueprint' ),
			],
		];
	}
}
