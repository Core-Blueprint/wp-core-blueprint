<?php
/**
 * Template: Core Blueprint Dashboard (landing).
 *
 * Variables provided by \CB\Core\Admin\Pages\Dashboard::render():
 *   $safeguards          array - canonical Base safeguard status entries (ordered)
 *   $extensions          array - sibling CB plugins detected, enriched with status menus
 *   $operations_cards    array - Operations nav cards (Logs, Notes, Reports, Core Profiles)
 *   $cms_tools_cards     array - first-party CMS baseline module cards visible to the current user
 *   $preferences_cards   array - five or six preference deeplinks
 *   $about_card          array - full-width footer card
 *
 * Dashboard cards reuse the shared tile-grid component:
 *   .cb-core-tile--navigation - ordinary navigation destination
 *   .cb-core-tile--status-nav - navigation destination with live state
 *   .cb-core-tile__title / __meta       - text slots
 *   .cb-core-status-menu - top-right status + actions for managed modules/extensions
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

use CB\Core\Modules\Status;
?>
<div class="wrap cb-core-wrap cb-core-dashboard">

	<h1 class="cb-core-title">
		<?php esc_html_e( 'Dashboard', 'core-blueprint' ); ?>
		<span class="cb-core-version">v<?php echo esc_html( CB_CORE_VERSION ); ?></span>
	</h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Core Blueprint provides the base layer for security hardening, activity logging, and site governance.', 'core-blueprint' ); ?>
	</p>

	<?php /* ─── Safeguards ─────────────────────────────────────────────── */ ?>
	<section class="cb-core-section">
		<h2 class="cb-core-section-title"><?php esc_html_e( 'Safeguards', 'core-blueprint' ); ?></h2>
		<div class="cb-core-tiles">
			<?php foreach ( $safeguards as $module => $card ) : ?>
				<?php if ( ! empty( $card['status_menu'] ) ) : ?>
					<?php include CB_CORE_DIR . 'templates/partials/dashboard-managed-card.php'; ?>
				<?php else : ?>
					<a class="cb-core-tile cb-core-tile--status-nav"
					   href="<?php echo esc_url( $card['url'] ); ?>"
					   data-state="<?php echo esc_attr( $card['state'] ); ?>">
						<span class="cb-core-tile__dot cb-core-tile__dot--<?php echo esc_attr( Status::dot_class( $card['state'] ) ); ?>" aria-hidden="true"></span>
						<span class="cb-core-tile__title"><?php echo esc_html( $card['label'] ); ?></span>
						<?php if ( '' !== $card['detail'] ) : ?>
							<span class="cb-core-tile__meta"><?php echo esc_html( $card['detail'] ); ?></span>
						<?php endif; ?>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</section>

	<?php /* ─── Operations ─────────────────────────────────────────────── */ ?>
	<section class="cb-core-section">
		<h2 class="cb-core-section-title"><?php esc_html_e( 'Operations', 'core-blueprint' ); ?></h2>
		<div class="cb-core-tiles">
			<?php foreach ( $operations_cards as $card ) : ?>
				<?php if ( ! empty( $card['status_menu'] ) ) : ?>
					<?php include CB_CORE_DIR . 'templates/partials/dashboard-managed-card.php'; ?>
				<?php else : ?>
					<a class="cb-core-tile cb-core-tile--navigation" href="<?php echo esc_url( $card['url'] ); ?>">
						<span class="cb-core-tile__title"><?php echo esc_html( $card['title'] ); ?></span>
						<span class="cb-core-tile__meta"><?php echo esc_html( $card['meta'] ); ?></span>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</section>

	<?php /* ─── CMS Tools ──────────────────────────────────────────────── */ ?>
	<?php if ( ! empty( $cms_tools_cards ) ) : ?>
		<section class="cb-core-section">
			<h2 class="cb-core-section-title"><?php esc_html_e( 'CMS Tools', 'core-blueprint' ); ?></h2>
			<div class="cb-core-tiles">
				<?php foreach ( $cms_tools_cards as $card ) : ?>
					<?php include CB_CORE_DIR . 'templates/partials/dashboard-managed-card.php'; ?>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php /* ─── Preferences ────────────────────────────────────────────── */ ?>
	<section class="cb-core-section">
		<h2 class="cb-core-section-title"><?php esc_html_e( 'Preferences', 'core-blueprint' ); ?></h2>
		<div class="cb-core-tiles">
			<?php foreach ( $preferences_cards as $card ) : ?>
				<a class="cb-core-tile cb-core-tile--navigation" href="<?php echo esc_url( $card['url'] ); ?>">
					<span class="cb-core-tile__title"><?php echo esc_html( $card['title'] ); ?></span>
					<span class="cb-core-tile__meta"><?php echo esc_html( $card['meta'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>

	<?php /* ─── Extensions ─────────────────────────────────────────────── */ ?>
	<section class="cb-core-section">
		<h2 class="cb-core-section-title"><?php esc_html_e( 'Extensions', 'core-blueprint' ); ?></h2>
		<?php if ( empty( $extensions ) ) : ?>
			<p class="cb-core-empty">
				<?php esc_html_e( 'No Core Blueprint extensions detected.', 'core-blueprint' ); ?>
			</p>
		<?php else : ?>
			<div class="cb-core-tiles">
				<?php foreach ( $extensions as $ext ) :
					$allowed_states = [ 'active', 'inactive', 'warning', 'idle', 'error' ];
					$state = isset( $ext['state'] ) && in_array( $ext['state'], $allowed_states, true )
						? $ext['state']
						: ( ! empty( $ext['active'] ) ? 'active' : 'inactive' );
					$status_line = isset( $ext['status_line'] ) ? (string) $ext['status_line'] : '';
					if ( '' === $status_line ) {
						$status_line = ! empty( $ext['active'] ) ? __( 'active', 'core-blueprint' ) : __( 'inactive', 'core-blueprint' );
					}
					$card = [
						'title'       => (string) ( $ext['name'] ?? '' ),
						'meta'        => sprintf( 'v%s · %s', (string) ( $ext['version'] ?? '' ), $status_line ),
						'url'         => ! empty( $ext['active'] ) ? (string) ( $ext['menu_url'] ?? '' ) : admin_url( 'plugins.php' ),
						'state'       => $state,
						'status_menu' => (string) ( $ext['status_menu'] ?? '' ),
					];
					include CB_CORE_DIR . 'templates/partials/dashboard-managed-card.php';
				endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php /* ─── About (footer card) ────────────────────────────────────── */ ?>
	<section class="cb-core-section cb-core-section--about">
		<a class="cb-core-tile cb-core-tile--navigation cb-core-tile--about" href="<?php echo esc_url( $about_card['url'] ); ?>">
			<span class="cb-core-tile__title"><?php echo esc_html( $about_card['title'] ); ?></span>
			<span class="cb-core-tile__meta"><?php echo esc_html( $about_card['meta'] ); ?></span>
		</a>
	</section>

</div>
