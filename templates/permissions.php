<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Preferences → Permissions tab
 *
 * Variables provided by Preferences::render_permissions_tab():
 *   - $candidates                     WP_User[] administrators eligible for promotion
 *   - $current_operator_ids           int[] currently-assigned operator user IDs
 *   - $current_user_id                int  active user (used to mark "(you)")
 *   - $can_manage                     bool current user can cb_manage_permissions
 *   - $hide_active                    bool current value of permissions.hide_from_admins
 *   - $admin_can_generate_maintenance bool current value of reports.admin_can_generate.maintenance
 *   - $admin_can_run_integrity        bool current value of integrity.admin_can_run
 *   - $nonce                          string cb_core_admin nonce
 *
 * Layout: three independent settings sections, each with its own save
 * button. Splitting saves keeps the lockout-prevention logic per-action
 * - saving operators won't accidentally flip hide-state and vice versa.
 *
 * Source strings are English; translations live in
 * languages/core-blueprint-{locale}.po per the plugin's i18n convention.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap cb-core-wrap cb-core-permissions">

	<h1 class="cb-core-title"><?php esc_html_e( 'Permissions', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Who may configure Core Blueprint? Operators can change all CB settings, generate reports, and adjust branding. Administrators see status only by default and can change only what is explicitly granted via the toggles below.', 'core-blueprint' ); ?>
	</p>

	<?php if ( ! $can_manage ) : ?>
		<?php
		echo \CB\Core\UI\Notice::render( [
			'variant' => \CB\Core\UI\Notice::INFO,
			'message' => __( 'You can view this page but not modify it. Only Core Blueprint operators may change permissions.', 'core-blueprint' ),
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
		?>
	<?php endif; ?>

	<form
		id="cb-core-permissions-form"
		class="cb-core-form"
		data-nonce="<?php echo esc_attr( $nonce ); ?>"
	>

		<?php // ── Section 1: Operator assignment ── ?>
		<section class="cb-core-preferences-section cb-core-permissions-operators-panel">
			<h2><?php esc_html_e( 'CB Operators', 'core-blueprint' ); ?></h2>

			<p>
				<?php esc_html_e( 'Select which administrators should also have the CB Operator role. Operators can generate reports, change branding, and manage this permissions page.', 'core-blueprint' ); ?>
			</p>

			<?php
			// Self-status banner - quickly answers "am I an operator?" without
			// scanning the table.
			$self_is_operator = in_array( (int) $current_user_id, $current_operator_ids, true );
			?>
			<div class="cb-core-permissions-self-state" data-is-operator="<?php echo esc_attr( $self_is_operator ? 'yes' : 'no' ); ?>">
				<?php
				echo \CB\Core\UI\Status::render(
					$self_is_operator ? 'active' : 'warning',
					$self_is_operator ? __( 'CB Operator', 'core-blueprint' ) : __( 'Administrator', 'core-blueprint' )
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
				?>
				<p class="description">
					<?php if ( $self_is_operator ) : ?>
						<?php esc_html_e( 'You can manage this page.', 'core-blueprint' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Read-only - ask an existing CB Operator to grant operator access. Emergency recovery is available from the server CLI.', 'core-blueprint' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<?php if ( empty( $candidates ) ) : ?>
				<p class="description">
					<em><?php esc_html_e( 'No administrators found on this site.', 'core-blueprint' ); ?></em>
				</p>
			<?php else : ?>
				<table class="widefat striped cb-core-operator-list">
					<colgroup>
						<col class="cb-core-col-operator-icon" />
						<col />
						<col />
						<col />
						<col class="cb-core-col-status" />
					</colgroup>
					<thead>
						<tr>
							<th scope="col" class="cb-core-nowrap"><?php esc_html_e( 'Operator', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Login', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Name', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Email', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $candidates as $user ) : ?>
							<?php
							$is_operator   = in_array( (int) $user->ID, $current_operator_ids, true );
							$is_self       = (int) $user->ID === (int) $current_user_id;
							$disabled_attr = $can_manage ? '' : ' disabled';
							?>
							<tr<?php echo $is_operator ? ' class="cb-core-row-operator"' : ''; ?>>
								<td>
									<input
										type="checkbox"
										class="cb-core-operator-toggle"
										name="operator_ids[]"
										value="<?php echo (int) $user->ID; ?>"
										<?php checked( $is_operator ); ?>
										<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									>
								</td>
								<td>
									<strong><?php echo esc_html( (string) $user->user_login ); ?></strong>
									<?php if ( $is_self ) : ?>
										<em>(<?php esc_html_e( 'you', 'core-blueprint' ); ?>)</em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $user->display_name ); ?></td>
								<td><?php echo esc_html( (string) $user->user_email ); ?></td>
								<td>
									<?php if ( $is_operator ) : ?>
										<span class="cb-core-badge cb-core-badge-identity">
											<?php esc_html_e( 'CB Operator', 'core-blueprint' ); ?>
										</span>
									<?php else : ?>
										<span class="cb-core-badge cb-core-badge-identity cb-core-badge-identity--muted">
											<?php esc_html_e( 'Administrator', 'core-blueprint' ); ?>
										</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="cb-core-actions">
					<button
						type="button"
						class="button cb-core-button cb-core-button--primary"
						id="cb-core-save-operators"
						<?php disabled( ! $can_manage ); ?>
					>
						<?php esc_html_e( 'Save operators', 'core-blueprint' ); ?>
					</button>
					<?php echo \CB\Core\UI\FormStatus::render( [ 'target' => 'operators' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</section>

		<?php // ── Section 2: Page visibility ── ?>
		<section class="cb-core-preferences-section cb-core-permissions-visibility-panel">
			<h2><?php esc_html_e( 'Page visibility', 'core-blueprint' ); ?></h2>

			<p>
				<?php esc_html_e( 'Decide whether administrators without the operator role can see this Permissions page. When hidden, the tab only becomes available again once the setting is turned off (via WP-CLI or by an operator).', 'core-blueprint' ); ?>
			</p>

			<table class="form-table cb-core-permissions-settings-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Administrator access', 'core-blueprint' ); ?></th>
						<td>
							<label for="cb-core-hide-toggle">
								<input type="checkbox" id="cb-core-hide-toggle" name="hide_from_admins" value="1" <?php checked( $hide_active ); ?> <?php disabled( ! $can_manage ); ?>>
								<?php esc_html_e( 'Hide this page from administrators (operators only)', 'core-blueprint' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Available once at least one CB Operator is assigned. When no operators remain, this setting is automatically disabled to prevent lockout.', 'core-blueprint' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="cb-core-actions">
				<button
					type="button"
					class="button cb-core-button cb-core-button--primary"
					id="cb-core-save-hide"
					<?php disabled( ! $can_manage ); ?>
				>
					<?php esc_html_e( 'Save visibility', 'core-blueprint' ); ?>
				</button>
				<?php echo \CB\Core\UI\FormStatus::render( [ 'target' => 'hide' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>

		<?php // ── Section 3: Administrator capabilities ── ?>
		<section class="cb-core-preferences-section cb-core-permissions-caps-panel">
			<h2><?php esc_html_e( 'Administrator capabilities', 'core-blueprint' ); ?></h2>

			<p>
				<?php esc_html_e( 'By default only CB Operators can generate reports and run Core Scanner. Enable below which actions administrators (without operator role) may also perform.', 'core-blueprint' ); ?>
			</p>

			<table class="form-table cb-core-permissions-settings-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed actions', 'core-blueprint' ); ?></th>
						<td>
							<fieldset class="cb-core-permissions-capabilities">
								<label for="cb-core-admin-can-generate-maintenance">
									<input type="checkbox" id="cb-core-admin-can-generate-maintenance" name="admin_can_generate_maintenance" value="1" <?php checked( $admin_can_generate_maintenance ); ?> <?php disabled( ! $can_manage ); ?>>
									<?php esc_html_e( 'Administrators may generate Maintenance Reports', 'core-blueprint' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, all administrators effectively gain cb_manage_reports - without becoming operators. Intended for clients who want to run their own reports without further CB configuration.', 'core-blueprint' ); ?></p>

								<label for="cb-core-admin-can-run-integrity">
									<input type="checkbox" id="cb-core-admin-can-run-integrity" name="admin_can_run_integrity" value="1" <?php checked( $admin_can_run_integrity ); ?> <?php disabled( ! $can_manage ); ?>>
									<?php esc_html_e( 'Administrators may run Core Scanner', 'core-blueprint' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, all administrators effectively gain cb_manage_integrity - without becoming operators. Lets clients run integrity scans and approve baselines themselves on sites where the operator is not actively involved.', 'core-blueprint' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="cb-core-actions">
				<button
					type="button"
					class="button cb-core-button cb-core-button--primary"
					id="cb-core-save-admin-caps"
					<?php disabled( ! $can_manage ); ?>
				>
					<?php esc_html_e( 'Save admin capabilities', 'core-blueprint' ); ?>
				</button>
				<?php echo \CB\Core\UI\FormStatus::render( [ 'target' => 'admin-caps' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>

	</form>

</div>
