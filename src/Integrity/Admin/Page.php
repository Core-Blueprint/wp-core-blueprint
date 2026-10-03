<?php
declare(strict_types=1);
/**
 * Core Scanner workspace router for the Safeguards tab.
 *
 * Focused view modules own rendering; this class keeps the public panel
 * entrypoint and top-level workspace/state routing only.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Integrity\Admin;

use CoreBlueprint\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CoreBlueprint\Core\Integrity\State;
use CoreBlueprint\Core\Integrity\Support\ResultFormatter;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\UI\Notice;

defined( 'ABSPATH' ) || exit;

final class Page {
	use ScannerShellView;
	use ScannerOverviewView;
	use ScannerFindingsView;
	use ScannerGroupedFindingsView;
	use ScannerQuarantineView;
	use ScannerSettingsView;
	use ScannerHistoryView;

	public static function render_panel(): void {
		$state             = self::resolve_page_state();
		$view              = self::resolve_scanner_view();
		$latest            = ResultRepository::getLatest();
		$settings          = ResultRepository::settings();
		$history           = ResultFormatter::history_summary();
		$current_scan      = is_array( $latest ) ? (string) ( $latest['timestamp'] ?? '' ) : '';
		$baseline_meta     = ResultFormatter::summary( $latest );
		$baseline          = is_array( $baseline_meta['baseline'] ?? null ) ? $baseline_meta['baseline'] : [ 'exists' => false ];
		$is_enabled        = State::is_enabled();
		$can_manage_policy = self::can_manage_policy();
		$finding_total     = is_array( $latest ) ? count( ResultFormatter::review_findings( $latest ) ) : 0;
		$quarantine_open   = QuarantineRepository::open_count();
		?>
		<div class="cb-core-integrity-wrap cb-core-integrity-state-<?php echo esc_attr( $state ); ?>" data-cb-integrity-state="<?php echo esc_attr( $state ); ?>" data-cb-integrity-view="<?php echo esc_attr( $view ); ?>" data-cb-integrity-enabled="<?php echo $is_enabled ? 'true' : 'false'; ?>" data-cb-integrity-policy-access="<?php echo $can_manage_policy ? 'true' : 'false'; ?>">
			<?php self::render_scanner_nav( $view, $finding_total, $quarantine_open ); ?>

			<?php if ( ! $is_enabled ) : ?>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() returns escape-clean HTML.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
				echo Notice::render( [
					'variant' => Notice::INFO,
					'title'   => __( 'Core Scanner is disabled.', 'core-blueprint' ),
					'message' => $can_manage_policy
						? __( 'Scheduled scans will not run; manual scan is unavailable. Existing scan history, findings, quarantine records, and approved baselines remain available.', 'core-blueprint' )
						: __( 'Scheduled and manual scans are unavailable while the scanner is disabled. Existing evidence remains available for review; a CB Operator can re-enable the scanner.', 'core-blueprint' ),
				] );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>

			<?php
			switch ( $view ) {
				case 'findings':
					if ( 'scanning' === $state ) {
						self::render_scanning_state( $settings );
					} elseif ( is_array( $latest ) ) {
						self::render_findings_state( $latest, $settings );
					} else {
						self::render_findings_empty_state();
					}
					break;
				case 'quarantine':
					self::render_quarantine_workspace( $can_manage_policy );
					break;
				case 'history':
					self::render_history_panel( (array) ( $history['items'] ?? [] ), $current_scan );
					break;
				case 'settings':
					self::render_distribution_locale_panel( $settings, $can_manage_policy );
					self::render_settings_panel( $settings, $can_manage_policy );
					break;
				default:
					switch ( $state ) {
						case 'scanning':
							self::render_scanning_state( $settings );
							break;
						case 'result':
							self::render_result_state( $latest, $settings, $baseline, $is_enabled );
							break;
						default:
							self::render_idle_state( $baseline, $is_enabled );
					}
			}
			?>
		</div>
		<?php
	}
}
