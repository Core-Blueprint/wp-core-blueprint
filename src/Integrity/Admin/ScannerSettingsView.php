<?php
declare(strict_types=1);
/** Core Scanner admin view module: ScannerSettingsView.
 * @package Core_Blueprint
 * @since 1.0.0
 */
namespace CB\Core\Integrity\Admin;

use CB\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CB\Core\Integrity\Quarantine\Service as QuarantineService;
use CB\Core\Integrity\State;
use CB\Core\Integrity\Support\ResultFormatter;
use CB\Core\Integrity\Storage\BaselineReviewRepository;
use CB\Core\Integrity\Storage\ResultRepository;
use CB\Core\UI\Icon;
use CB\Core\UI\FormStatus;
use CB\Core\UI\Notice;
use CB\Core\UI\StateBadge;
use CB\Core\UI\Status;

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

trait ScannerSettingsView {

    private static function render_settings_panel( array $settings, bool $can_manage_policy ): void {
        ?>
        <section class="cb-core-integrity-settings-section cb-core-integrity-settings-panel">
            <div class="cb-core-integrity-panel-head">
                <div>
                    <h2 class="cb-core-section-title"><?php echo esc_html__( 'Scanner settings', 'core-blueprint' ); ?></h2>
                    <p><?php echo esc_html( $can_manage_policy ? __( 'These settings are shared by manual, scheduled, and future Hub-triggered scans.', 'core-blueprint' ) : __( 'These settings define scanner scope and are read-only here because only a CB Operator may change scanner policy.', 'core-blueprint' ) ); ?></p>
                </div>
            </div>
            <table class="form-table cb-core-integrity-settings-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="cb-core-integrity-schedule"><?php echo esc_html__( 'Schedule', 'core-blueprint' ); ?></label></th>
                        <td><select id="cb-core-integrity-schedule" <?php disabled( ! $can_manage_policy ); ?>><option value="disabled" <?php selected( $settings['schedule'], 'disabled' ); ?>><?php echo esc_html__( 'Disabled', 'core-blueprint' ); ?></option><option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php echo esc_html__( 'Daily', 'core-blueprint' ); ?></option><option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php echo esc_html__( 'Weekly', 'core-blueprint' ); ?></option></select></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Plugins', 'core-blueprint' ); ?></th>
                        <td><label><input type="checkbox" id="cb-core-integrity-plugin-checksums" <?php checked( $settings['plugin_checksums'] ); ?> <?php disabled( ! $can_manage_policy ); ?> /><span><?php echo esc_html__( 'Verify WordPress.org plugin checksums where available', 'core-blueprint' ); ?></span></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Themes', 'core-blueprint' ); ?></th>
                        <td><label><input type="checkbox" id="cb-core-integrity-theme-checksums" <?php checked( $settings['theme_checksums'] ); ?> <?php disabled( ! $can_manage_policy ); ?> /><span><?php echo esc_html__( 'Verify WordPress.org theme checksums where available', 'core-blueprint' ); ?></span></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Uploads', 'core-blueprint' ); ?></th>
                        <td><label><input type="checkbox" id="cb-core-integrity-uploads-scan" <?php checked( $settings['uploads_scan'] ); ?> <?php disabled( ! $can_manage_policy ); ?> /><span><?php echo esc_html__( 'Scan uploads for executable files', 'core-blueprint' ); ?></span></label></td>
                    </tr>
                </tbody>
            </table>
            <?php if ( $can_manage_policy ) : ?>
                <div class="cb-core-integrity-settings-actions">
                    <button type="button" class="button cb-core-button cb-core-button--primary" id="cb-core-integrity-save-settings" data-cb-integrity-action="save-settings"><?php echo esc_html__( 'Save Settings', 'core-blueprint' ); ?></button>
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FormStatus::render() returns escape-clean HTML.
                    echo FormStatus::render( [ 'id' => 'cb-core-integrity-settings-status', 'tight' => true ] );
                    ?>
                </div>
            <?php else : ?>
                <?php
                // Keep the canonical live region present for JS/runtime symmetry, even on read-only views.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FormStatus::render() returns escape-clean HTML.
                echo FormStatus::render( [ 'id' => 'cb-core-integrity-settings-status', 'block' => true ] );
                ?>
            <?php endif; ?>
        </section>
        <?php
    }

	/**
	 * Determine which page-state to render.
	 *
	 *   - 'scanning' if a persisted resumable scan job owns the scan lock
	 *   - 'result'   if a latest scan result exists
	 *   - 'idle'     otherwise (no active scan, no stored result)
	 *
	 * Server-side resolution per CP-9: avoids JS state-switching
	 * over hidden DOM, gives screen readers a clean tree, and keeps
	 * sticky positioning predictable.
	 */

	private static function render_distribution_locale_panel( array $settings, bool $can_manage_policy ): void {
		$mode      = (string) ( $settings['distribution_locale_mode']     ?? 'fallback' );
		$detected  = (string) ( $settings['distribution_locale_detected'] ?? '' );
		$override  = (string) ( $settings['distribution_locale_override'] ?? '' );
		$meta      = is_array( $settings['distribution_locale_meta'] ?? null ) ? $settings['distribution_locale_meta'] : [];
		$tried     = is_array( $meta['tried'] ?? null ) ? $meta['tried'] : [];
		$last      = (string) ( $meta['last_detected_at'] ?? '' );
		$matched   = (string) ( $meta['matched_file']     ?? '' );
		$cross     = (string) ( $meta['cross_check']      ?? '' );
		$ui_locale = (string) get_locale();

		$distribution_used = ( 'override' === $mode && '' !== $override )
			? $override
			: ( ( 'auto' === $mode && '' !== $detected ) ? $detected : $ui_locale );

		$distribution_suffix = '';
		switch ( $mode ) {
			case 'override':
				$distribution_suffix = '' !== $override ? sprintf( ' (%s)', __( 'manual override', 'core-blueprint' ) ) : '';
				break;
			case 'auto':
				$distribution_suffix = '' !== $detected ? sprintf( ' (%s)', __( 'auto-detected', 'core-blueprint' ) ) : '';
				break;
			default:
				$distribution_suffix = sprintf( ' (%s)', __( 'fallback to UI locale', 'core-blueprint' ) );
		}

		switch ( $mode ) {
			case 'auto':
				$status_label = '' !== $detected
					? __( 'Auto', 'core-blueprint' )
					: __( 'Auto (not yet detected)', 'core-blueprint' );
				break;
			case 'override':
				$status_label = '' !== $override
					? __( 'Manual override', 'core-blueprint' )
					: __( 'Manual override (not set)', 'core-blueprint' );
				break;
			default:
				$status_label = __( 'Not detected yet - runs automatically on first checksum mismatch', 'core-blueprint' );
		}

		?>
		<section class="cb-core-integrity-settings-section cb-core-integrity-locale-panel" aria-labelledby="cb-core-integrity-locale-heading">
			<div class="cb-core-integrity-panel-head">
				<h2 id="cb-core-integrity-locale-heading"><?php echo esc_html__( 'Distribution locale', 'core-blueprint' ); ?></h2>
				<?php if ( $can_manage_policy ) : ?>
					<button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-integrity-action="redetect-locale">
						<?php echo esc_html__( 'Re-detect', 'core-blueprint' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<p class="cb-core-integrity-muted">
				<?php echo esc_html__( 'Which official WordPress distribution is on disk. Used for checksum verification. Normally auto-detected - override only when you know what you are doing (e.g. after manual core re-install).', 'core-blueprint' ); ?>
			</p>

			<dl class="cb-core-integrity-locale-status">
				<dt><?php echo esc_html__( 'UI locale', 'core-blueprint' ); ?></dt>
				<dd><code><?php echo esc_html( $ui_locale ); ?></code></dd>

				<dt><?php echo esc_html__( 'Distribution', 'core-blueprint' ); ?></dt>
				<dd>
					<code><?php echo esc_html( $distribution_used ); ?></code>
					<?php if ( '' !== $distribution_suffix ) : ?>
						<span class="cb-core-integrity-muted"><?php echo esc_html( $distribution_suffix ); ?></span>
					<?php endif; ?>
				</dd>

				<dt><?php echo esc_html__( 'Detection status', 'core-blueprint' ); ?></dt>
				<dd><?php echo esc_html( $status_label ); ?></dd>

				<?php if ( '' !== $last ) : ?>
				<dt><?php echo esc_html__( 'Last detection', 'core-blueprint' ); ?></dt>
				<dd><?php echo esc_html( $last ); ?></dd>
				<?php endif; ?>

				<?php if ( '' !== $matched ) : ?>
				<dt><?php echo esc_html__( 'Matched file', 'core-blueprint' ); ?></dt>
				<dd><code><?php echo esc_html( $matched ); ?></code></dd>
				<?php endif; ?>

				<?php if ( '' !== $cross ) : ?>
				<dt><?php echo esc_html__( 'Cross-check', 'core-blueprint' ); ?></dt>
				<dd>
					<?php
					switch ( $cross ) {
						case 'ok':       echo esc_html__( 'Passed (locale-agnostic core files match)', 'core-blueprint' ); break;
						case 'failed':   echo esc_html__( 'Failed - possible tampering, detection not pinned', 'core-blueprint' ); break;
						case 'skipped':  echo esc_html__( 'Skipped (cross-check files unavailable)', 'core-blueprint' ); break;
						default:         echo esc_html( $cross );
					}
					?>
				</dd>
				<?php endif; ?>
			</dl>

			<?php if ( ! empty( $tried ) ) : ?>
			<details class="cb-core-integrity-locale-tried">
				<summary><?php echo esc_html__( 'Tried locales', 'core-blueprint' ); ?> (<?php echo count( $tried ); ?>)</summary>
				<ul>
					<?php foreach ( $tried as $locale_string ) : ?>
						<li><code><?php echo esc_html( (string) $locale_string ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php endif; ?>
		</section>
		<?php
	}

}
