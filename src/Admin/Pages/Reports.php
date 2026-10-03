<?php
declare(strict_types=1);
/**
 * Reports - admin landing page for generated maintenance reports.
 *
 * Two-tab dispatcher in v1.1:
 *   - overview      Lists available report types + recent generations.
 *   - maintenance   Period-selector + generate buttons for one report type.
 *
 * Plan §7.1 reserves the inline tab approach (rather than the TabRegistry
 * indirection that Logs uses) because Reports' tab list is short and stable
 * - extension plugins that want to add report types use the
 * core_blueprint_reports_tabs filter instead of a separate registry class.
 *
 * Capability: cb_view_reports for the page itself; manage caps gate the
 * generate buttons inside each tab template.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin\Pages;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\Admin\Tabbed;
use CoreBlueprint\Core\PDF\Renderer;
use CoreBlueprint\Core\Reports\Storage;

defined( 'ABSPATH' ) || exit;

final class Reports extends PageBase {

	use Tabbed;

	const SLUG = 'core-blueprint-reports';

	const TAB_OVERVIEW    = 'overview';
	const TAB_MAINTENANCE = 'maintenance';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Reports', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Reports', 'core-blueprint' );
	}

	public function position(): ?int {
		return 25; // Between Logs (20) and Safeguards (30).
	}

	public function capability(): string {
		return 'cb_view_reports';
	}

	public function render(): void {
		$this->guard();

		$tab_labels = [
			self::TAB_OVERVIEW    => __( 'Overview', 'core-blueprint' ),
			self::TAB_MAINTENANCE => __( 'Maintenance Report', 'core-blueprint' ),
		];

		/**
		 * Filter: core_blueprint_reports_tabs
		 *
		 * Allows Reports addons to add their own tabs to the Reports page.
		 * Map of slug → translated label. Slugs must match the value
		 * passed to ?tab=… so the dispatcher can route correctly.
		 *
		 * @param array<string, string> $tab_labels
		 */
		$tab_labels = (array) apply_filters( 'core_blueprint_reports_tabs', $tab_labels );

		$active = $this->active_tab(
			array_keys( $tab_labels ),
			self::TAB_OVERVIEW
		);

		// Render the tab body, then splice the nav-tabs in via the trait so
		// pages stay consistent with Safeguards / Preferences.
		ob_start();
		switch ( $active ) {
			case self::TAB_MAINTENANCE:
				$this->render_maintenance_tab();
				break;
			case self::TAB_OVERVIEW:
				$this->render_overview_tab();
				break;
			default:
				/**
				 * Action: core_blueprint_reports_render_tab_{slug}
				 *
				 * Allows Reports addons to render their own tab body when the
				 * dispatcher hits an unknown slug. The trailing slug is the
				 * sanitised tab key - addons hook the specific name they
				 * registered via core_blueprint_reports_tabs.
				 */
				do_action( 'core_blueprint_reports_render_tab_' . $active );
				break;
		}
		$body = (string) ob_get_clean();

		echo $this->inject_tab_nav( $body, self::SLUG, $active, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// ─── Tab renderers ────────────────────────────────────────────────────────

	/**
	 * Render the Overview tab - lists available report types + recent
	 * generations. Templates live in templates/reports/ and receive
	 * pre-resolved data via locally-scoped variables.
	 */
	private function render_overview_tab(): void {
		// Pre-resolve everything the template needs so the template itself
		// stays presentation-only.
		$available_types = $this->available_report_types();
		$recent_reports  = Storage::find_recent( 10 );

		$template = CB_CORE_DIR . 'templates/reports/overview.php';
		if ( ! is_file( $template ) ) {
			$this->render_subsystem_missing( __( 'Reports overview template missing.', 'core-blueprint' ) );
			return;
		}

		$page_slug = self::SLUG;
		require $template;
	}

	/**
	 * Render the Maintenance Report tab - period selector + generate buttons.
	 */
	private function render_maintenance_tab(): void {
		$can_manage  = current_user_can( 'cb_manage_reports' );
		$pdf_ready   = Renderer::is_available();
		$page_slug   = self::SLUG;
		$months      = $this->period_presets();

		// Default period: previous full month. If today is 2026-04-26 then
		// default is 2026-03-01 → 2026-03-31. Matches the agency cadence
		// of "report on the month that just ended".
		$default = $this->previous_month_range();

		$template = CB_CORE_DIR . 'templates/reports/maintenance-report.php';
		if ( ! is_file( $template ) ) {
			$this->render_subsystem_missing( __( 'Maintenance Report template missing.', 'core-blueprint' ) );
			return;
		}

		require $template;
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Available report types for the Overview tab. v1.1 ships only one;
	 * Reports addons add their own via filter.
	 *
	 * @return array<string, array{label: string, description: string, tab: string}>
	 */
	private function available_report_types(): array {
		$types = [
			'maintenance' => [
				'label'       => __( 'Maintenance Report', 'core-blueprint' ),
				'description' => __( 'Period overview of updates, security events, and user actions on this site.', 'core-blueprint' ),
				'tab'         => self::TAB_MAINTENANCE,
			],
		];

		/**
		 * Filter: core_blueprint_reports_available_types
		 *
		 * Reports addons add report types here. Each entry is a tab-slug map
		 * with label, description, and the tab to navigate to when the user
		 * clicks "Naar rapport →".
		 *
		 * @param array<string, array> $types
		 */
		return (array) apply_filters( 'core_blueprint_reports_available_types', $types );
	}

	/**
	 * Build the period preset list for the dropdown. Returns the current
	 * month-to-date as the first entry, followed by the last 12 full
	 * calendar months (newest first).
	 *
	 * The current-month entry uses end = today, so re-running on a later
	 * day produces a different period (and a fresh row in
	 * cb_maintenance_reports). This is intentional: the agency cadence is
	 * "rapport over wat net afgelopen is" - the previous-full-month default
	 * stays in place - but ad-hoc "how is this month going" checks now
	 * cost one click instead of switching to Custom period.
	 *
	 * @return array<string, array{start: string, end: string, label: string}>
	 *         Keys: 'current' for the to-date entry, 'YYYY-MM' for the
	 *         calendar months. Stable round-trip via $_POST.
	 */
	private function period_presets(): array {
		$presets = [];
		$now     = current_datetime();

		// Current month, up to today. Edge case on the 1st of the month:
		// start == end == today (single-day report) - empty in most cases
		// but factually correct.
		$presets['current'] = [
			'start' => $now->format( 'Y-m-01' ),
			'end'   => $now->format( 'Y-m-d' ),
			'label' => sprintf(
				/* translators: %s is a localised month name + year, e.g. "April 2026". */
				__( '%s - to date', 'core-blueprint' ),
				date_i18n( 'F Y', $now->getTimestamp() )
			),
		];

		for ( $i = 1; $i <= 12; $i++ ) {
			// 'first day of -N months' avoids PHP's day-overflow quirk:
			// from 2026-03-31, plain '-1 month' gives 2026-03-03 (Feb has
			// 28 days, days carry over). 'first day of' anchors to day 1
			// of the target month, sidestepping the overflow entirely.
			$month = $now->modify( sprintf( 'first day of -%d months', $i ) );
			if ( false === $month ) {
				continue;
			}
			$start = $month->format( 'Y-m-01' );
			$end   = $month->format( 'Y-m-t' );
			$key   = $month->format( 'Y-m' );

			$presets[ $key ] = [
				'start' => $start,
				'end'   => $end,
				'label' => date_i18n( 'F Y', $month->getTimestamp() ),
			];
		}

		return $presets;
	}

	/**
	 * Default selection - the calendar month immediately before today.
	 *
	 * Uses the 'first day of -1 month' idiom rather than plain '-1 month'
	 * to avoid PHP's day-overflow: on the 29th-31st of a month, plain
	 * '-1 month' carries days into the wrong month (e.g. 2026-03-31 →
	 * 2026-03-03 because Feb has 28 days). 'first day of' anchors to
	 * day 1 of the target month, sidestepping the overflow entirely.
	 *
	 * @return array{start: string, end: string}
	 */
	private function previous_month_range(): array {
		$now      = current_datetime();
		$previous = $now->modify( 'first day of -1 month' );
		if ( false === $previous ) {
			return [ 'start' => $now->format( 'Y-m-01' ), 'end' => $now->format( 'Y-m-t' ) ];
		}
		return [
			'start' => $previous->format( 'Y-m-01' ),
			'end'   => $previous->format( 'Y-m-t' ),
		];
	}
}
