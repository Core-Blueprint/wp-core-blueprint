<?php
declare(strict_types=1);
/**
 * RetentionTab - read-only operational view of canonical retention policy.
 *
 * AuditLog policy is configured in Preferences > Privacy. Dedicated stores
 * contribute their own retention windows separately through the controlled
 * RetentionStoreRegistry; they are not extra AuditLog categories.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\Admin\Pages\Logs\Tabs;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\Admin\TabNav;
use CoreBlueprint\Core\Governance\RetentionPolicy;
use CoreBlueprint\Core\Governance\RetentionStoreRegistry;
use CoreBlueprint\Core\Log\Retention;

defined( 'ABSPATH' ) || exit;

final class RetentionTab {
	public const SLUG = 'retention';

	public static function render( string $tab, array $tab_labels ): void {
		$policy      = RetentionPolicy::all();
		$next_prune  = Retention::next_run();
		$privacy_url = admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=privacy' );
		$stores      = RetentionStoreRegistry::snapshot();
		$categories  = [
			'security'    => __( 'Security events', 'core-blueprint' ),
			'maintenance' => __( 'Maintenance events', 'core-blueprint' ),
			'logins'      => __( 'Login events', 'core-blueprint' ),
			'settings'    => __( 'Settings changes', 'core-blueprint' ),
			'general'     => __( 'General events', 'core-blueprint' ),
		];

		ob_start();
		?>
		<div class="wrap cb-core-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Retention', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Operational view of the canonical AuditLog policy and dedicated datastore retention. Audit categories are configured in Preferences › Privacy.', 'core-blueprint' ); ?></p>

			<section class="cb-core-section cb-core-logs-retention-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Audit log retention', 'core-blueprint' ); ?></h2>
				<table class="widefat striped cb-core-policy-table">
					<thead><tr><th style="width:240px;"><?php esc_html_e( 'Category', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Retention window', 'core-blueprint' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $categories as $slug => $label ) : $days = (int) $policy[ $slug ]; ?>
						<tr><th><?php echo esc_html( $label ); ?></th><td>
							<?php if ( $days > 0 ) : ?>
								<?php echo esc_html( self::format_retention( $days ) ); ?>
								<?php /* translators: %d: retention window in days. */ ?>
								<span class="cb-core-muted" style="margin-left:8px;"><?php echo esc_html( sprintf( _n( '(%d day)', '(%d days)', $days, 'core-blueprint' ), $days ) ); ?></span>
							<?php else : ?>
								<?php echo \CoreBlueprint\Core\UI\StateBadge::render( __( 'Keep forever', 'core-blueprint' ), [ 'variant' => \CoreBlueprint\Core\UI\StateBadge::NEUTRAL ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
						</td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top:16px;"><a href="<?php echo esc_url( $privacy_url ); ?>" class="button button-primary"><?php esc_html_e( 'Edit retention in Preferences › Privacy', 'core-blueprint' ); ?></a></p>
			</section>

			<?php if ( ! empty( $stores ) ) : ?>
				<section class="cb-core-section cb-core-logs-retention-section">
					<h2 class="cb-core-section-title"><?php esc_html_e( 'Dedicated data stores', 'core-blueprint' ); ?></h2>
					<p><?php esc_html_e( 'These stores have their own retention windows and storage lifecycle. They are not AuditLog categories.', 'core-blueprint' ); ?></p>
					<table class="widefat striped cb-core-policy-table"><thead><tr><th style="width:240px;"><?php esc_html_e( 'Data store', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Retention window', 'core-blueprint' ); ?></th></tr></thead><tbody>
					<?php foreach ( $stores as $store ) :
						$days = (int) $store['days'];
						$url  = (string) $store['settings_url'];
					?>
						<tr><th><?php echo esc_html( $store['label'] ); ?></th><td><?php echo esc_html( $days > 0 ? self::format_retention( $days ) : __( 'Keep forever', 'core-blueprint' ) ); ?><?php if ( '' !== $url ) : ?> <a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Edit', 'core-blueprint' ); ?></a><?php endif; ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</section>
			<?php endif; ?>

			<section class="cb-core-section cb-core-logs-retention-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Prune schedule', 'core-blueprint' ); ?></h2>
				<table class="widefat striped cb-core-policy-table"><tbody><tr><th style="width:240px;"><?php esc_html_e( 'Next scheduled prune', 'core-blueprint' ); ?></th><td>
				<?php if ( $next_prune ) : ?>
					<?php echo esc_html( wp_date( 'Y-m-d H:i:s T', $next_prune ) ); ?> <span class="cb-core-muted">(<?php echo esc_html( human_time_diff( time(), $next_prune ) ); ?> <?php esc_html_e( 'from now', 'core-blueprint' ); ?>)</span>
				<?php else : ?>
					<?php echo \CoreBlueprint\Core\UI\StateBadge::render( __( 'Not scheduled', 'core-blueprint' ), [ 'variant' => \CoreBlueprint\Core\UI\StateBadge::WARNING ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
				</td></tr></tbody></table>
				<p class="cb-core-muted" style="margin-top:10px;"><?php esc_html_e( 'Prune runs once per day. Every AuditLog row is classified by the same canonical event-to-category policy used by the UI and CLI.', 'core-blueprint' ); ?></p>
			</section>
		</div>
		<?php
		$html = ob_get_clean();
		echo TabNav::inject( $html, Admin::LOGS_SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function format_retention( int $days ): string {
		$labels = [
			30 => __( '30 days', 'core-blueprint' ), 60 => __( '60 days', 'core-blueprint' ),
			90 => __( '90 days', 'core-blueprint' ), 180 => __( '6 months', 'core-blueprint' ),
			365 => __( '1 year', 'core-blueprint' ), 730 => __( '2 years', 'core-blueprint' ),
			1095 => __( '3 years', 'core-blueprint' ),
		];
		if ( isset( $labels[ $days ] ) ) {
			return $labels[ $days ];
		}
		/* translators: %d: retention window in days. */
		return sprintf( _n( '%d day', '%d days', $days, 'core-blueprint' ), $days );
	}
}
