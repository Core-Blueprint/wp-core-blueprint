<?php
declare(strict_types=1);
/** Core Scanner admin view module: ScannerHistoryView.
 * @package Core_Blueprint
 * @since 1.0.0
 */
namespace CoreBlueprint\Core\Integrity\Admin;

use CoreBlueprint\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CoreBlueprint\Core\Integrity\Quarantine\Service as QuarantineService;
use CoreBlueprint\Core\Integrity\State;
use CoreBlueprint\Core\Integrity\Support\ResultFormatter;
use CoreBlueprint\Core\Integrity\Storage\BaselineReviewRepository;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\UI\Icon;
use CoreBlueprint\Core\UI\FormStatus;
use CoreBlueprint\Core\UI\Notice;
use CoreBlueprint\Core\UI\StateBadge;
use CoreBlueprint\Core\UI\Status;

use function checked;
use function count;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function get_locale;
use function is_array;
use function number_format_i18n;
use function selected;
use function sprintf;
use function strtoupper;
use function ucfirst;

defined( 'ABSPATH' ) || exit;

trait ScannerHistoryView {

    private static function render_history_panel( array $items, string $current_scan ): void {
        /* translators: %d: number of stored scan history entries currently shown. */
        $history_meta_label = sprintf( __( 'Showing the latest %d stored scans.', 'core-blueprint' ), count( $items ) );
        ?>
        <section class="cb-core-integrity-history-section">
            <div class="cb-core-integrity-panel-head">
                <div>
                    <h2 class="cb-core-section-title"><?php echo esc_html__( 'Scan History', 'core-blueprint' ); ?></h2>
                    <p id="cb-core-integrity-history-meta"><?php echo esc_html( $history_meta_label ); ?></p>
                </div>
            </div>
            <div class="cb-core-integrity-history" id="cb-core-integrity-history"><?php self::render_history( $items, $current_scan ); ?></div>
        </section>
        <?php
    }

	private static function render_history( array $items, string $current_scan = '' ): void {
		if ( empty( $items ) ) {
			echo '<p class="cb-core-integrity-muted">' . esc_html__( 'No previous scans stored yet.', 'core-blueprint' ) . '</p>';
			return;
		}

		echo '<div class="cb-core-integrity-history-table-wrap">';
		echo '<table class="widefat striped cb-core-integrity-history-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Scan History', 'core-blueprint' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Status', 'core-blueprint' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Date', 'core-blueprint' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Result', 'core-blueprint' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Source', 'core-blueprint' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $items as $item ) {
			$status      = (string) ( $item['status'] ?? 'idle' );
			$summary     = is_array( $item['summary'] ?? null ) ? $item['summary'] : [];
			$time        = (string) ( $item['timestamp'] ?? '' );
			$source      = (string) ( $item['source'] ?? '' );
			$is_current  = '' !== $current_scan && $time === $current_scan;
			$is_baseline = 'baseline' === $source || 'component_baseline' === $source;
			/* translators: 1: verified count, 2: warning count, 3: critical count. */
			$summary_label = sprintf( __( '%1$d OK, %2$d warnings, %3$d critical', 'core-blueprint' ), (int) ( $summary['ok'] ?? 0 ), (int) ( $summary['warning'] ?? 0 ), (int) ( $summary['critical'] ?? 0 ) );

			echo '<tr class="' . esc_attr( ( $is_current ? 'is-current ' : '' ) . ( $is_baseline ? 'is-baseline' : '' ) ) . '">';
			echo '<td>' . StateBadge::render( strtoupper( $status ), [ 'variant' => self::state_badge_variant( $status ) ] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Proven-safe Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
			echo '<td><strong>' . esc_html( $time ) . '</strong></td>';
			echo '<td>' . esc_html( $summary_label ) . '</td>';
			echo '<td><div class="cb-core-integrity-history-tags">';
			if ( $is_current ) {
				echo '<span class="cb-core-badge">' . esc_html__( 'Current', 'core-blueprint' ) . '</span>';
			}
			if ( $is_baseline ) {
				echo '<span class="cb-core-badge">' . esc_html__( 'Baseline', 'core-blueprint' ) . '</span>';
			} elseif ( '' !== $source ) {
				echo '<span class="cb-core-badge">' . esc_html( $source ) . '</span>';
			}
			echo '</div></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

}
