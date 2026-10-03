<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Core Blueprint - Failsafe management page
 *
 * Available variables (set by \CoreBlueprint\Core\Admin\Admin::render_failsafe):
 *   $self_test - \CoreBlueprint\Core\Security\Failsafe::self_test() result
 *   $layers    - \CoreBlueprint\Core\Security\Failsafe::active_layers() result
 *   $bypassed  - bool, is any failsafe layer active
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$has_token  = (bool) get_option( CB_CORE_BYPASS_TOK, '' );
$admin_email = get_option( 'admin_email', '' );
?>
<div class="wrap cb-core-wrap">

	<h1 class="cb-core-title"><?php esc_html_e( 'Failsafe', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Four independent lockout-prevention layers. At least one must always be functional so administrators can recover from misconfiguration.', 'core-blueprint' ); ?>
	</p>

	<?php if ( $bypassed ) : ?>
		<?php
		$parts = [];
		if ( $layers['constant'] )  { $parts[] = __( 'Layer 1 (wp-config.php constant)', 'core-blueprint' ); }
		if ( $layers['option'] )    { $parts[] = __( 'Layer 2 (emergency option)', 'core-blueprint' ); }
		if ( $layers['transient'] ) { $parts[] = __( 'Layer 3 (60-minute window)', 'core-blueprint' ); }

		echo \CoreBlueprint\Core\UI\Notice::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
			[
				'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
				'title'   => __( 'Emergency bypass is currently active.', 'core-blueprint' ),
				'message' => __( 'Restrictive Core Blueprint features are currently bypassed through one or more recovery layers.', 'core-blueprint' ),
				'items'   => $parts,
			]
		);
		?>
	<?php endif; ?>

	<!-- ─── Layer overview ────────────────────────────────────────── -->

	<section class="cb-core-failsafe-section" aria-labelledby="cb-core-failsafe-layers-title">
		<h2 id="cb-core-failsafe-layers-title"><?php esc_html_e( 'Failsafe layers', 'core-blueprint' ); ?></h2>

		<table class="widefat striped cb-core-failsafe-layers">
			<colgroup>
				<col class="cb-core-col-layer" />
				<col />
				<col class="cb-core-col-status" />
			</colgroup>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Layer', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'Mechanism', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong>Layer 1</strong></td>
					<td>
						<strong><?php esc_html_e( 'wp-config.php constant', 'core-blueprint' ); ?></strong><br />
						<span class="cb-core-muted">
							<?php esc_html_e( 'Add to wp-config.php:', 'core-blueprint' ); ?>
							<code>define( 'CB_CORE_BYPASS', true );</code>
						</span>
					</td>
					<td>
						<?php
						echo \CoreBlueprint\Core\UI\StateBadge::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML.
							$layers['constant'] ? __( 'ACTIVE', 'core-blueprint' ) : __( 'Inactive', 'core-blueprint' ),
							[ 'variant' => $layers['constant'] ? \CoreBlueprint\Core\UI\StateBadge::WARNING : \CoreBlueprint\Core\UI\StateBadge::NEUTRAL ]
						);
						?>
					</td>
				</tr>
				<tr>
					<td><strong>Layer 2</strong></td>
					<td>
						<strong><?php esc_html_e( 'WP-CLI commands', 'core-blueprint' ); ?></strong><br />
						<span class="cb-core-muted">
							<code>wp core-blueprint emergency-disable</code><br />
							<code>wp core-blueprint status</code>
						</span>
					</td>
					<td>
						<?php
						echo \CoreBlueprint\Core\UI\StateBadge::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML.
							$layers['option'] ? __( 'ACTIVE', 'core-blueprint' ) : __( 'Inactive', 'core-blueprint' ),
							[ 'variant' => $layers['option'] ? \CoreBlueprint\Core\UI\StateBadge::WARNING : \CoreBlueprint\Core\UI\StateBadge::NEUTRAL ]
						);
						?>
					</td>
				</tr>
				<tr>
					<td><strong>Layer 3</strong></td>
					<td>
						<strong><?php esc_html_e( 'Secret bypass URL', 'core-blueprint' ); ?></strong><br />
						<span class="cb-core-muted">
							<?php esc_html_e( 'Single-use token, opens 60-minute bypass window, sends email notification', 'core-blueprint' ); ?>
						</span>
					</td>
					<td>
						<?php
						if ( $layers['transient'] ) {
							$layer3_label   = __( 'WINDOW OPEN', 'core-blueprint' );
							$layer3_variant = \CoreBlueprint\Core\UI\StateBadge::WARNING;
						} elseif ( $has_token ) {
							$layer3_label   = __( 'Armed', 'core-blueprint' );
							$layer3_variant = \CoreBlueprint\Core\UI\StateBadge::SUCCESS;
						} else {
							$layer3_label   = __( 'No token', 'core-blueprint' );
							$layer3_variant = \CoreBlueprint\Core\UI\StateBadge::DANGER;
						}
						echo \CoreBlueprint\Core\UI\StateBadge::render( $layer3_label, [ 'variant' => $layer3_variant ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML.
						?>
					</td>
				</tr>
				<tr>
					<td><strong>Layer 4</strong></td>
					<td>
						<strong><?php esc_html_e( 'Admin panic button', 'core-blueprint' ); ?></strong><br />
						<span class="cb-core-muted">
							<?php esc_html_e( 'Available on this page - requires password confirmation', 'core-blueprint' ); ?>
						</span>
					</td>
					<td>
						<?php echo \CoreBlueprint\Core\UI\StateBadge::render( __( 'Available', 'core-blueprint' ), [ 'variant' => \CoreBlueprint\Core\UI\StateBadge::INFO ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML. ?>
					</td>
				</tr>
			</tbody>
		</table>
	</section>

	<section class="cb-core-failsafe-section" aria-labelledby="cb-core-failsafe-self-test-title">
		<h2 id="cb-core-failsafe-self-test-title"><?php esc_html_e( 'Self-test', 'core-blueprint' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Verifies the infrastructure on which the failsafe depends is functional on this server.', 'core-blueprint' ); ?>
		</p>

		<table class="widefat striped cb-core-failsafe-self-test">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Check', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'Result', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'Message', 'core-blueprint' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $self_test as $check => $result ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $check ); ?></strong></td>
						<td>
							<?php
							echo \CoreBlueprint\Core\UI\StateBadge::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML.
								$result['ok'] ? __( 'PASS', 'core-blueprint' ) : __( 'FAIL', 'core-blueprint' ),
								[ 'variant' => $result['ok'] ? \CoreBlueprint\Core\UI\StateBadge::SUCCESS : \CoreBlueprint\Core\UI\StateBadge::ERROR ]
							);
							?>
						</td>
						<td><?php echo esc_html( $result['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<!-- ─── Token management ──────────────────────────────────────── -->

	<section class="cb-core-failsafe-section" aria-labelledby="cb-core-failsafe-token-management-title">
		<h2 id="cb-core-failsafe-token-management-title"><?php esc_html_e( 'Secret bypass URL (Layer 3)', 'core-blueprint' ); ?></h2>

		<?php if ( $has_token ) : ?>
			<div class="cb-core-failsafe-setting-state">
				<?php echo \CoreBlueprint\Core\UI\StateBadge::render( __( 'Armed', 'core-blueprint' ), [ 'variant' => \CoreBlueprint\Core\UI\StateBadge::SUCCESS ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML. ?>
				<p class="description">
					<?php esc_html_e( 'A bypass token is currently armed. The plaintext URL is never stored - only a hash. If you have lost your copy, rotate the token to generate a new one.', 'core-blueprint' ); ?>
				</p>
			</div>
			<div class="cb-core-failsafe-action-stack">
				<button type="button" class="button cb-core-button cb-core-button--remediation cb-core-rotate-token">
					<?php esc_html_e( 'Rotate bypass token', 'core-blueprint' ); ?>
				</button>
				<span class="cb-core-muted"><?php esc_html_e( 'Requires password confirmation.', 'core-blueprint' ); ?></span>
			</div>
		<?php else : ?>
			<?php
			echo \CoreBlueprint\Core\UI\Notice::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
				[
					'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
					'title'   => __( 'No bypass token is currently armed.', 'core-blueprint' ),
					'message' => __( 'Generate one now and store it in your password manager. Without a token, Layer 3 of the failsafe cannot protect you.', 'core-blueprint' ),
				]
			);
			?>
			<div class="cb-core-failsafe-action-stack">
				<button type="button" class="button cb-core-button cb-core-button--primary cb-core-rotate-token">
					<?php esc_html_e( 'Generate bypass token', 'core-blueprint' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</section>

	<!-- ─── Emergency controls ────────────────────────────────────── -->

	<section class="cb-core-failsafe-section" aria-labelledby="cb-core-failsafe-emergency-controls-title">
		<h2 id="cb-core-failsafe-emergency-controls-title"><?php esc_html_e( 'Emergency controls', 'core-blueprint' ); ?></h2>

		<?php if ( $layers['option'] ) : ?>
			<?php
			echo \CoreBlueprint\Core\UI\Notice::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
				[
					'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
					'title'   => __( 'Emergency bypass is active.', 'core-blueprint' ),
					'message' => __( 'The emergency bypass (Layer 2) is currently active. Restrictive features are disabled.', 'core-blueprint' ),
				]
			);
			?>
			<div class="cb-core-failsafe-action-stack">
				<button type="button" class="button cb-core-button cb-core-button--primary cb-core-panic-deactivate">
					<?php esc_html_e( 'Resume enforcement', 'core-blueprint' ); ?>
				</button>
			</div>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'The panic button instantly disables every restrictive Core Blueprint feature. Use only when you are locked out of legitimate functionality.', 'core-blueprint' ); ?>
			</p>
			<div class="cb-core-failsafe-action-stack">
				<button type="button" class="button cb-core-button cb-core-button--warning cb-core-panic-activate">
					<?php esc_html_e( 'Activate emergency bypass', 'core-blueprint' ); ?>
				</button>
				<span class="cb-core-muted"><?php esc_html_e( 'Requires password confirmation. Audit-logged.', 'core-blueprint' ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $layers['transient'] ) : ?>
			<div class="cb-core-failsafe-window-control">
				<?php
				echo \CoreBlueprint\Core\UI\Notice::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
					[
						'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
						'title'   => __( 'A 60-minute bypass window (Layer 3) is currently open.', 'core-blueprint' ),
						'message' => __( 'Close it immediately to resume enforcement before the window expires naturally.', 'core-blueprint' ),
					]
				);
				?>
				<button type="button" class="button cb-core-button cb-core-button--remediation cb-core-close-window">
					<?php esc_html_e( 'Close bypass window now', 'core-blueprint' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</section>

</div>
