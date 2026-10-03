<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Access Mode.
 *
 * Four-state access policy editor. A tile click stages a choice; the effective
 * site policy changes only after the explicit Apply button succeeds.
 *
 * Variables (set by \CoreBlueprint\Core\Admin\Pages\Safeguards):
 *   $current string
 *   $config  array<string,mixed>
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$admin_nonce             = wp_create_nonce( 'cb_core_admin' );
$coming_soon_page_id     = (int) ( $config['coming_soon_page_id'] ?? 0 );
$coming_soon_indexable   = ! empty( $config['coming_soon_indexable'] );
$maintenance_page_id     = (int) ( $config['maintenance_page_id'] ?? 0 );
$maintenance_until_date  = (string) ( $config['maintenance_until_date'] ?? '' );
$maintenance_until_time  = (string) ( $config['maintenance_until_time'] ?? '' );
$is_public               = \CoreBlueprint\Core\Security\AccessMode::MODE_PUBLIC === $current;

$mode_options = [
	[
		'mode'  => \CoreBlueprint\Core\Security\AccessMode::MODE_PUBLIC,
		'icon'  => 'public-site',
		'title' => __( 'Public Mode', 'core-blueprint' ),
		'desc'  => __( 'The normal website is live. Visitors and search engines receive the regular WordPress responses.', 'core-blueprint' ),
	],
	[
		'mode'  => \CoreBlueprint\Core\Security\AccessMode::MODE_COMING_SOON,
		'icon'  => 'clock',
		'title' => __( 'Coming Soon', 'core-blueprint' ),
		'desc'  => __( 'Use one published page as a pre-launch landing page. Other public URLs redirect to it temporarily.', 'core-blueprint' ),
	],
	[
		'mode'  => \CoreBlueprint\Core\Security\AccessMode::MODE_MAINTENANCE,
		'icon'  => 'settings',
		'title' => __( 'Maintenance', 'core-blueprint' ),
		'desc'  => __( 'Temporarily take the site offline with HTTP 503 while rendering a selected maintenance page.', 'core-blueprint' ),
	],
	[
		'mode'  => \CoreBlueprint\Core\Security\AccessMode::MODE_ADMIN_ONLY,
		'icon'  => 'locked-site',
		'title' => __( 'Admin-Only Mode', 'core-blueprint' ),
		'desc'  => __( 'Lock the public front-end with HTTP 403. Logged-in users and recovery paths remain available.', 'core-blueprint' ),
	],
];
?>
<div class="wrap cb-core-wrap cb-core-access-mode">

	<h1 class="cb-core-title"><?php esc_html_e( 'Access Mode', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Choose how the public front-end behaves. Public is the normal live website; Coming Soon is for pre-launch; Maintenance is for temporary downtime; Admin-Only keeps the installation private. Changes are applied only after you confirm them below.', 'core-blueprint' ); ?>
	</p>

	<form class="cb-core-access-form" data-cb-core-access-form>
		<div class="cb-core-access-panel" role="radiogroup" aria-label="<?php esc_attr_e( 'Access Mode', 'core-blueprint' ); ?>">
			<?php foreach ( $mode_options as $option ) :
				$active = $current === $option['mode'];
				?>
				<label class="cb-core-access-option cb-core-interactive-surface<?php echo $active ? ' is-active' : ''; ?>"
					data-cb-core-access-option
					data-cb-core-access-mode="<?php echo esc_attr( $option['mode'] ); ?>">
					<input class="screen-reader-text"
						type="radio"
						name="cb_core_access_mode"
						value="<?php echo esc_attr( $option['mode'] ); ?>"
						data-cb-core-access-mode-input
						<?php checked( $active ); ?> />
					<span class="cb-core-access-option__head">
						<span class="cb-core-access-option__icon" aria-hidden="true">
							<?php echo \CoreBlueprint\Core\UI\Icon::render( $option['icon'], [ 'size' => \CoreBlueprint\Core\UI\Icon::SIZE_LARGE ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
						<span class="cb-core-access-option__copy">
							<span class="cb-core-access-option__title"><?php echo esc_html( $option['title'] ); ?></span>
							<span class="cb-core-access-option__desc"><?php echo esc_html( $option['desc'] ); ?></span>
						</span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="cb-core-access-config" data-cb-core-access-config="<?php echo esc_attr( \CoreBlueprint\Core\Security\AccessMode::MODE_COMING_SOON ); ?>"<?php echo \CoreBlueprint\Core\Security\AccessMode::MODE_COMING_SOON === $current ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Coming Soon settings', 'core-blueprint' ); ?></h2>
			<div class="cb-core-access-config__grid">
				<div class="cb-core-field cb-core-stack">
					<label class="cb-core-field__label" for="cb-core-coming-soon-page"><?php esc_html_e( 'Coming Soon page', 'core-blueprint' ); ?></label>
					<?php
					echo \CoreBlueprint\Core\UI\ObjectPicker::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output.
						'name'          => 'coming_soon_page_id',
						'id'            => 'cb-core-coming-soon-page',
						'action'        => 'cb_core_access_mode_search_pages',
						'nonce'         => $admin_nonce,
						'selected'      => \CoreBlueprint\Core\Security\AccessMode::picker_selected_page( $coming_soon_page_id ),
						'placeholder'   => __( 'Search published pages…', 'core-blueprint' ),
						'empty_message' => __( 'No published pages found.', 'core-blueprint' ),
					] );
					?>
					<p class="description"><?php esc_html_e( 'This page remains available with HTTP 200. Other anonymous front-end URLs redirect here with HTTP 302.', 'core-blueprint' ); ?></p>
				</div>

				<div class="cb-core-field cb-core-stack">
					<span class="cb-core-field__label"><?php esc_html_e( 'Search visibility', 'core-blueprint' ); ?></span>
					<?php
					echo \CoreBlueprint\Core\UI\ChoiceGroup::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output.
						'type'       => \CoreBlueprint\Core\UI\ChoiceGroup::TYPE_RADIO,
						'aria_label' => __( 'Coming Soon search visibility', 'core-blueprint' ),
						'options'    => [
							[
								'name'    => 'coming_soon_indexable',
								'value'   => '1',
								'label'   => __( 'Allow the Coming Soon page in search results', 'core-blueprint' ),
								'checked' => $coming_soon_indexable,
							],
							[
								'name'    => 'coming_soon_indexable',
								'value'   => '0',
								'label'   => __( 'Keep the Coming Soon page out of search results', 'core-blueprint' ),
								'checked' => ! $coming_soon_indexable,
							],
						],
					] );
					?>
					<p class="description"><?php esc_html_e( 'When hidden from search, Core Blueprint sends X-Robots-Tag: noindex, follow on the landing page. It does not block crawlers in robots.txt.', 'core-blueprint' ); ?></p>
				</div>
			</div>
		</div>

		<div class="cb-core-access-config" data-cb-core-access-config="<?php echo esc_attr( \CoreBlueprint\Core\Security\AccessMode::MODE_MAINTENANCE ); ?>"<?php echo \CoreBlueprint\Core\Security\AccessMode::MODE_MAINTENANCE === $current ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Maintenance settings', 'core-blueprint' ); ?></h2>
			<div class="cb-core-access-config__grid">
				<div class="cb-core-field cb-core-stack">
					<label class="cb-core-field__label" for="cb-core-maintenance-page"><?php esc_html_e( 'Maintenance page', 'core-blueprint' ); ?></label>
					<?php
					echo \CoreBlueprint\Core\UI\ObjectPicker::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output.
						'name'          => 'maintenance_page_id',
						'id'            => 'cb-core-maintenance-page',
						'action'        => 'cb_core_access_mode_search_pages',
						'nonce'         => $admin_nonce,
						'selected'      => \CoreBlueprint\Core\Security\AccessMode::picker_selected_page( $maintenance_page_id ),
						'placeholder'   => __( 'Search published pages…', 'core-blueprint' ),
						'empty_message' => __( 'No published pages found.', 'core-blueprint' ),
					] );
					?>
					<p class="description"><?php esc_html_e( 'The selected page is rendered through WordPress at every public URL while the original URL returns HTTP 503 Service Unavailable.', 'core-blueprint' ); ?></p>
				</div>

				<div class="cb-core-field cb-core-stack">
					<span class="cb-core-field__label"><?php esc_html_e( 'Expected back online', 'core-blueprint' ); ?> <span class="cb-core-field__optional"><?php esc_html_e( '(optional)', 'core-blueprint' ); ?></span></span>
					<div class="cb-core-access-datetime">
						<label>
							<span class="screen-reader-text"><?php esc_html_e( 'Expected return date', 'core-blueprint' ); ?></span>
							<input type="date" name="maintenance_until_date" value="<?php echo esc_attr( $maintenance_until_date ); ?>" data-cb-core-maintenance-date />
						</label>
						<div data-cb-time-picker>
							<label class="screen-reader-text" for="cb-core-maintenance-time"><?php esc_html_e( 'Expected return time', 'core-blueprint' ); ?></label>
							<input id="cb-core-maintenance-time" type="text" name="maintenance_until_time" value="<?php echo esc_attr( $maintenance_until_time ); ?>" placeholder="HH:MM" data-cb-core-maintenance-time />
							<button type="button" data-cb-time-picker-toggle aria-label="<?php esc_attr_e( 'Choose expected return time', 'core-blueprint' ); ?>"></button>
						</div>
					</div>
					<p class="description"><?php esc_html_e( 'When both fields are set, Core Blueprint sends a standards-based Retry-After header. Maintenance intentionally does not add noindex.', 'core-blueprint' ); ?></p>
				</div>
			</div>
		</div>

		<div class="cb-core-access-notice" data-cb-core-access-notice="<?php echo esc_attr( \CoreBlueprint\Core\Security\AccessMode::MODE_ADMIN_ONLY ); ?>"<?php echo \CoreBlueprint\Core\Security\AccessMode::MODE_ADMIN_ONLY === $current ? '' : ' hidden'; ?>>
			<?php
			echo \CoreBlueprint\Core\UI\Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
				'title'   => __( 'Before enabling Admin-Only Mode:', 'core-blueprint' ),
				'items'   => [
					__( 'Verify you can log in via /wp-login.php.', 'core-blueprint' ),
					__( 'Save your Core Blueprint failsafe bypass URL so you retain an emergency recovery path.', 'core-blueprint' ),
					__( 'Search engines receive 403 on public URLs and may remove those URLs from their index.', 'core-blueprint' ),
				],
			] );
			?>
		</div>

		<div class="cb-core-access-notice" data-cb-core-access-notice="<?php echo esc_attr( \CoreBlueprint\Core\Security\AccessMode::MODE_MAINTENANCE ); ?>"<?php echo \CoreBlueprint\Core\Security\AccessMode::MODE_MAINTENANCE === $current ? '' : ' hidden'; ?>>
			<?php
			echo \CoreBlueprint\Core\UI\Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
				'title'   => __( 'Maintenance keeps temporary downtime machine-readable.', 'core-blueprint' ),
				'items'   => [
					__( 'Anonymous public requests return HTTP 503 Service Unavailable.', 'core-blueprint' ),
					__( 'The browser stays on the originally requested URL; there is no maintenance redirect.', 'core-blueprint' ),
					__( 'WordPress admin, login, REST, AJAX, cron, WP-CLI and registered machine/webhook bypasses remain reachable.', 'core-blueprint' ),
				],
			] );
			?>
		</div>

		<div class="cb-core-access-actions">
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary" data-cb-core-access-submit>
				<?php esc_html_e( 'Save Access Mode', 'core-blueprint' ); ?>
			</button>
			<span class="cb-core-form-status" data-cb-core-access-save-status aria-live="polite"></span>
		</div>
	</form>

	<div class="cb-core-access-status-bar">
		<span class="cb-core-access-status-label"><?php esc_html_e( 'Current status', 'core-blueprint' ); ?></span>
		<span class="cb-core-status" data-cb-core-access-status data-current-mode="<?php echo esc_attr( $current ); ?>">
			<span class="cb-core-status__dot <?php echo $is_public ? 'cb-core-status__dot--success' : 'cb-core-status__dot--warning'; ?>" data-cb-core-access-status-dot aria-hidden="true"></span>
			<span class="cb-core-status__label" data-cb-core-access-status-label><?php echo esc_html( \CoreBlueprint\Core\Security\AccessMode::status_label( $current ) ); ?></span>
		</span>
	</div>

</div>
