<?php
declare(strict_types=1);
/** Core Scanner admin view module: ScannerShellView.
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

trait ScannerShellView {

    private static function resolve_scanner_view(): string {
        $view = isset( $_GET['cb_integrity_view'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection.
            ? sanitize_key( wp_unslash( $_GET['cb_integrity_view'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 'overview';
        return in_array( $view, [ 'overview', 'findings', 'quarantine', 'history', 'settings' ], true ) ? $view : 'overview';
    }

    private static function scanner_view_url( string $view, array $extra = [] ): string {
        return add_query_arg(
            array_merge(
                [
                    'page'              => 'core-blueprint-safeguards',
                    'tab'               => 'core-scanner',
                    'cb_integrity_view' => $view,
                ],
                $extra
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function render_scanner_nav( string $view, int $finding_total, int $quarantine_open ): void {
        $items = [
            'overview'   => [ __( 'Overview', 'core-blueprint' ), null ],
            'findings'   => [ __( 'Findings', 'core-blueprint' ), $finding_total ],
            'quarantine' => [ __( 'Quarantine', 'core-blueprint' ), $quarantine_open ],
            'history'    => [ __( 'History', 'core-blueprint' ), null ],
            'settings'   => [ __( 'Settings', 'core-blueprint' ), null ],
        ];
        ?>
        <nav class="cb-core-integrity-local-nav" aria-label="<?php echo esc_attr__( 'Core Scanner workspace', 'core-blueprint' ); ?>">
            <?php foreach ( $items as $key => [ $label, $count ] ) : ?>
                <a class="cb-core-integrity-local-nav__item<?php echo $view === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::scanner_view_url( $key ) ); ?>" <?php echo $view === $key ? 'aria-current="page"' : ''; ?>>
                    <span><?php echo esc_html( $label ); ?></span>
                    <?php if ( null !== $count ) : ?><strong><?php echo esc_html( (string) $count ); ?></strong><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

	private static function resolve_page_state(): string {
		$active = \CoreBlueprint\Core\Integrity\Scanner\ScanJobStatus::active_job();
		if ( null !== $active ) {
			return 'scanning';
		}

		$latest = ResultRepository::getLatest();
		if ( is_array( $latest ) ) {
			return 'result';
		}

		return 'idle';
	}

	/**
	 * Render the idle-state UI: focus on the run-CTA.
	 *
	 * Hidden in this state: findings, components, verified-checks,
	 * diff panel, summary tiles. Operator should not be confronted
	 * with empty placeholder blocks ("No previous scan available
	 * for comparison yet") just to be told something is missing.
	 */

	private static function format_finding_timestamp( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		$format = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
		return wp_date( '' !== $format ? $format : 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Render the Distribution Locale panel.
	 *
	 * Always visible (per CB transparency principle) and intentionally
	 * compact: a status line, a UI-locale comparison line, and an
	 * inline Re-detect button. Operator-controls (mode + override) are
	 * collapsed under a details/summary so the typical scanner page
	 * doesn't surface knobs the operator only touches in edge cases.
	 *
	 * The "Tried locales" entry is a separate inline collapsible: most
	 * operators don't need to see five locale strings, but when
	 * something looks off (detection picked the wrong distribution,
	 * cross-check failed) it's an essential diagnostic.
	 */

	private static function can_manage_policy(): bool {
		return current_user_can( 'cb_manage_integrity_policy' );
	}

}
