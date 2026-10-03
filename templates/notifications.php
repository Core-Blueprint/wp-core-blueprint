<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Preferences > Notifications tab (1.0.20+).
 *
 * Single surface for all Core Blueprint email routing: who receives what,
 * when. Built as a "groups" layout so additional CB sibling plugins (Hub
 * pairing events, License expirations, future modules) can land their own
 * notification groups here without restructuring the page.
 *
 * Current groups:
 *   - Audit events  - severity-filtered audit log alerts
 *
 * Future groups (not yet implemented, but layout is ready for them):
 *   - Hub events    - pairing loss, key rotation, remote-command anomalies
 *   - License       - upcoming expirations, seat breaches
 *   - ...
 *
 * Schema convention: each group claims its own subtree under the global
 * CB_CORE_SETTINGS option. The audit group uses `audit.email_recipient`
 * + `audit.email_alerts`. Future groups use analogous keys under their own
 * namespace (e.g. `hub.email_recipient`). The AJAX handler
 * `cb_core_set_alert_recipient` accepts a `group` parameter (default
 * `audit`) and writes to the matching subtree - one handler, many groups.
 *
 * Scope exclusion (documented once, at the bottom of the page):
 * Failsafe emergency-bypass notifications always route to admin_email
 * directly, deliberately bypassing this UI so a compromised admin account
 * cannot silently redirect its own lockout-recovery alerts.
 *
 * Available variables (set by \CoreBlueprint\Core\Admin\Pages\Preferences::render_notifications_tab):
 *   $settings       - full settings array
 *   $email_override - current audit.email_recipient value
 *   $email_alerts   - current audit.email_alerts array (per-severity toggles)
 *   $admin_email    - fallback address (get_option('admin_email'))
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap cb-core-wrap">

	<h1 class="cb-core-title"><?php esc_html_e( 'Notifications', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Configure who receives email notifications from Core Blueprint and which events trigger an alert. Each section below controls notifications for a distinct subsystem; leaving a recipient empty falls back to the site administrator address.', 'core-blueprint' ); ?>
	</p>

	<!-- ─── Group: Audit events ─────────────────────────────────────── -->

	<section class="cb-core-preferences-section cb-core-notification-group" data-cb-core-notification-group="audit">
		<header class="cb-core-notification-group__header">
			<div class="cb-core-notification-group__title-wrap">
				<h2 class="cb-core-notification-group__title"><?php esc_html_e( 'Audit events', 'core-blueprint' ); ?></h2>
				<p class="cb-core-notification-group__desc">
					<?php esc_html_e( 'Alerts triggered by audit log entries - configuration changes, security events, login anomalies. Throttled to one email per event type per 15 minutes.', 'core-blueprint' ); ?>
				</p>
			</div>
		</header>

		<table class="widefat striped cb-core-policy-table">
			<colgroup>
				<col class="cb-core-col-recipient" />
				<col />
			</colgroup>
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Recipient', 'core-blueprint' ); ?></th>
					<td>
						<div class="cb-core-field cb-core-field--inline">
							<input type="text"
								class="regular-text cb-core-alert-recipient-input"
								data-cb-core-alert-recipient
								data-cb-core-alert-group="audit"
								value="<?php echo esc_attr( $email_override ); ?>"
								placeholder="<?php
									/* translators: %s: site admin email used as fallback when no override is configured */
									echo esc_attr( sprintf( __( 'Defaults to %s', 'core-blueprint' ), $admin_email ?: __( 'no address configured', 'core-blueprint' ) ) );
								?>"
								autocomplete="off"
								spellcheck="false" />
							<button type="button"
								class="button button-primary cb-core-button cb-core-button--primary cb-core-alert-recipient-save"
								data-cb-core-alert-recipient-save>
								<?php esc_html_e( 'Save', 'core-blueprint' ); ?>
							</button>
							<?php echo \CoreBlueprint\Core\UI\FormStatus::render( [ 'data' => [ 'data-cb-core-alert-recipient-status' => '' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Leave empty to use the site administrator address. Separate multiple addresses with commas - every valid address receives the alert; invalid entries are dropped on save.', 'core-blueprint' ); ?>
						</p>
						<?php if ( has_filter( 'cb_core_alert_recipient' ) ) : ?>
							<p class="description">
								<?php esc_html_e( 'Note: a developer filter (cb_core_alert_recipient) is active and may override this value at send time.', 'core-blueprint' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Alert on severity', 'core-blueprint' ); ?></th>
					<td>
						<label class="cb-core-severity-option">
							<input type="checkbox" class="cb-core-alert-toggle" data-severity="critical" <?php checked( ! empty( $email_alerts['critical'] ) ); ?> />
							<span class="cb-core-badge cb-core-badge-severity cb-core-badge-severity--critical"><?php esc_html_e( 'critical', 'core-blueprint' ); ?></span>
						</label>
						<label class="cb-core-severity-option">
							<input type="checkbox" class="cb-core-alert-toggle" data-severity="warning" <?php checked( ! empty( $email_alerts['warning'] ) ); ?> />
							<span class="cb-core-badge cb-core-badge-severity cb-core-badge-severity--warning"><?php esc_html_e( 'warning', 'core-blueprint' ); ?></span>
						</label>
						<label class="cb-core-severity-option">
							<input type="checkbox" class="cb-core-alert-toggle" data-severity="notice" <?php checked( ! empty( $email_alerts['notice'] ) ); ?> />
							<span class="cb-core-badge cb-core-badge-severity cb-core-badge-severity--notice"><?php esc_html_e( 'notice', 'core-blueprint' ); ?></span>
						</label>
						<label>
							<input type="checkbox" class="cb-core-alert-toggle" data-severity="info" <?php checked( ! empty( $email_alerts['info'] ) ); ?> />
							<span class="cb-core-badge cb-core-badge-severity cb-core-badge-severity--info"><?php esc_html_e( 'info', 'core-blueprint' ); ?></span>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
	</section>

	<!-- ─── Group: Permissions events (v1.1+) ──────────────────────── -->

	<section class="cb-core-preferences-section cb-core-notification-group" data-cb-core-notification-group="permissions">
		<header class="cb-core-notification-group__header">
			<div class="cb-core-notification-group__title-wrap">
				<h2 class="cb-core-notification-group__title"><?php esc_html_e( 'Permissions events', 'core-blueprint' ); ?></h2>
				<p class="cb-core-notification-group__desc">
					<?php esc_html_e( 'Alerts about privileged access and Core Blueprint governance - administrator-level accounts requiring review, operator role assignments, and lockout-prevention triggers.', 'core-blueprint' ); ?>
				</p>
				<?php if ( ! $can_manage_permissions_notifications ) : ?>
					<p class="description"><?php esc_html_e( 'Only CB Operators can change this security-notification channel.', 'core-blueprint' ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<table class="widefat striped cb-core-policy-table">
			<colgroup>
				<col class="cb-core-col-recipient" />
				<col />
			</colgroup>
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Recipient', 'core-blueprint' ); ?></th>
					<td>
						<div class="cb-core-field cb-core-field--inline">
							<input type="text"
								class="regular-text cb-core-alert-recipient-input"
								data-cb-core-alert-recipient
								data-cb-core-alert-group="permissions"
								value="<?php echo esc_attr( $permissions_recipient ); ?>"
								placeholder="<?php
									$audit_fb = '' !== $email_override ? $email_override : ( $admin_email ?: __( 'no address configured', 'core-blueprint' ) );
									/* translators: %s: fallback email address inherited from audit recipient or admin_email */
									echo esc_attr( sprintf( __( 'Defaults to %s', 'core-blueprint' ), $audit_fb ) );
								?>"
								autocomplete="off"
								spellcheck="false"
								<?php disabled( ! $can_manage_permissions_notifications ); ?> />
							<button type="button"
								class="button button-primary cb-core-button cb-core-button--primary cb-core-alert-recipient-save"
								data-cb-core-alert-recipient-save
								data-cb-core-alert-group="permissions"
								<?php disabled( ! $can_manage_permissions_notifications ); ?>>
								<?php esc_html_e( 'Save', 'core-blueprint' ); ?>
							</button>
							<?php echo \CoreBlueprint\Core\UI\FormStatus::render( [ 'data' => [ 'data-cb-core-alert-recipient-status' => '', 'data-cb-core-alert-group' => 'permissions' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Optional override for permissions notifications. Leave empty to use the audit-tab recipient (or the site admin email if that is also empty).', 'core-blueprint' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Alert on event', 'core-blueprint' ); ?></th>
					<td>
						<label class="cb-core-event-toggle cb-core-event-toggle--first">
							<input type="checkbox"
								class="cb-core-alert-toggle"
								data-cb-core-alert-group="permissions"
								data-alert-key="privileged_review"
								<?php checked( ! empty( $permissions_alerts['privileged_review'] ) ); ?>
								<?php disabled( ! $can_manage_permissions_notifications ); ?> />
							<strong><?php esc_html_e( 'Privileged account requires review', 'core-blueprint' ); ?></strong>
							<span class="cb-core-muted cb-core-muted--gap">
								<?php esc_html_e( '- a new or changed administrator-level identity requires CB Operator review; restriction depends on the selected protection mode', 'core-blueprint' ); ?>
							</span>
						</label>
						<label class="cb-core-event-toggle">
							<input type="checkbox"
								class="cb-core-alert-toggle"
								data-cb-core-alert-group="permissions"
								data-alert-key="role_change"
								<?php checked( ! empty( $permissions_alerts['role_change'] ) ); ?>
								<?php disabled( ! $can_manage_permissions_notifications ); ?> />
							<strong><?php esc_html_e( 'Operator role changes', 'core-blueprint' ); ?></strong>
							<span class="cb-core-muted cb-core-muted--gap">
								<?php esc_html_e( '- operators added, removed, or first assigned', 'core-blueprint' ); ?>
							</span>
						</label>
						<label class="cb-core-event-toggle">
							<input type="checkbox"
								class="cb-core-alert-toggle"
								data-cb-core-alert-group="permissions"
								data-alert-key="operator_guard_triggered"
								<?php checked( ! empty( $permissions_alerts['operator_guard_triggered'] ) ); ?>
								<?php disabled( ! $can_manage_permissions_notifications ); ?> />
							<strong><?php esc_html_e( 'Operator guard triggered', 'core-blueprint' ); ?></strong>
							<span class="cb-core-muted cb-core-muted--gap">
								<?php esc_html_e( '- hide-from-admins auto-disabled because no operators remain (lockout prevention)', 'core-blueprint' ); ?>
							</span>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
	</section>


	<!-- ─── Group: Core Scanner events ─────────────────────────────── -->
	<?php $can_manage_integrity_policy = current_user_can( 'cb_manage_integrity_policy' ); ?>

	<section class="cb-core-preferences-section cb-core-notification-group" data-cb-core-notification-group="integrity">
		<header class="cb-core-notification-group__header">
			<div class="cb-core-notification-group__title-wrap">
				<h2 class="cb-core-notification-group__title"><?php esc_html_e( 'Core Scanner events', 'core-blueprint' ); ?></h2>
				<p class="cb-core-notification-group__desc">
					<?php esc_html_e( 'Alerts are tied to finding lifecycle changes, not every scan. Persistent unchanged anomalies remain visible in Core Scanner without generating a new incident email.', 'core-blueprint' ); ?>
					<?php if ( ! $can_manage_integrity_policy ) : ?>
						<?php esc_html_e( ' Notification routing is read-only here; only a CB Operator may change Core Scanner security notifications.', 'core-blueprint' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</header>

		<table class="widefat striped cb-core-policy-table">
			<colgroup>
				<col class="cb-core-col-recipient" />
				<col />
			</colgroup>
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Recipient', 'core-blueprint' ); ?></th>
					<td>
						<div class="cb-core-field cb-core-field--inline">
							<input type="text"
								class="regular-text cb-core-alert-recipient-input"
								data-cb-core-alert-recipient
								data-cb-core-alert-group="integrity"
								value="<?php echo esc_attr( $integrity_recipient ); ?>"
								placeholder="<?php
									$audit_fb = '' !== $email_override ? $email_override : ( $admin_email ?: __( 'no address configured', 'core-blueprint' ) );
									/* translators: %s: fallback email address inherited from audit recipient or admin_email */
									echo esc_attr( sprintf( __( 'Defaults to %s', 'core-blueprint' ), $audit_fb ) );
								?>"
								autocomplete="off"
								spellcheck="false"
								<?php disabled( ! $can_manage_integrity_policy ); ?> />
							<button type="button"
								class="button button-primary cb-core-button cb-core-button--primary cb-core-alert-recipient-save"
								data-cb-core-alert-recipient-save
								data-cb-core-alert-group="integrity"
								<?php disabled( ! $can_manage_integrity_policy ); ?>>
								<?php esc_html_e( 'Save', 'core-blueprint' ); ?>
							</button>
							<?php echo \CoreBlueprint\Core\UI\FormStatus::render( [ 'data' => [ 'data-cb-core-alert-recipient-status' => '', 'data-cb-core-alert-group' => 'integrity' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Optional override for Core Scanner notifications. Leave empty to use the audit recipient, then the site administrator address.', 'core-blueprint' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Alert on event', 'core-blueprint' ); ?></th>
					<td>
						<label class="cb-core-event-toggle cb-core-event-toggle--first">
							<input type="checkbox"
								class="cb-core-alert-toggle"
								data-cb-core-alert-group="integrity"
								data-alert-key="critical_anomaly"
								<?php checked( ! empty( $integrity_alerts['critical_anomaly'] ) ); ?>
								<?php disabled( ! $can_manage_integrity_policy ); ?> />
							<strong><?php esc_html_e( 'New or changed critical anomaly', 'core-blueprint' ); ?></strong>
							<span class="cb-core-muted cb-core-muted--gap"><?php esc_html_e( '- a critical finding appeared or materially changed since the previous scan', 'core-blueprint' ); ?></span>
						</label>
						<label class="cb-core-event-toggle">
							<input type="checkbox"
								class="cb-core-alert-toggle"
								data-cb-core-alert-group="integrity"
								data-alert-key="warning_anomaly"
								<?php checked( ! empty( $integrity_alerts['warning_anomaly'] ) ); ?>
								<?php disabled( ! $can_manage_integrity_policy ); ?> />
							<strong><?php esc_html_e( 'New or changed warning anomaly', 'core-blueprint' ); ?></strong>
							<span class="cb-core-muted cb-core-muted--gap"><?php esc_html_e( '- useful for broader monitoring, but disabled by default to avoid noisy first-run or baseline-review mail', 'core-blueprint' ); ?></span>
						</label>
						<label class="cb-core-event-toggle">
							<input type="checkbox"
								class="cb-core-alert-toggle"
								data-cb-core-alert-group="integrity"
								data-alert-key="resolved"
								<?php checked( ! empty( $integrity_alerts['resolved'] ) ); ?>
								<?php disabled( ! $can_manage_integrity_policy ); ?> />
							<strong><?php esc_html_e( 'Confirmed anomaly resolution', 'core-blueprint' ); ?></strong>
							<span class="cb-core-muted cb-core-muted--gap"><?php esc_html_e( '- sent only when the affected scan area completed successfully and the previous anomaly is no longer observed', 'core-blueprint' ); ?></span>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
	</section>

	<!-- ─── Group: Reports events (v1.1+) ──────────────────────────── -->

	<section class="cb-core-preferences-section cb-core-notification-group" data-cb-core-notification-group="reports">
		<header class="cb-core-notification-group__header">
			<div class="cb-core-notification-group__title-wrap">
				<h2 class="cb-core-notification-group__title"><?php esc_html_e( 'Reports events', 'core-blueprint' ); ?></h2>
				<p class="cb-core-notification-group__desc">
					<?php esc_html_e( 'Alerts about report generation. Generation failures can use a Reports-specific recipient override.', 'core-blueprint' ); ?>
				</p>
			</div>
		</header>

		<table class="widefat striped cb-core-policy-table">
			<colgroup>
				<col class="cb-core-col-recipient" />
				<col />
			</colgroup>
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Recipient', 'core-blueprint' ); ?></th>
					<td>
						<div class="cb-core-field cb-core-field--inline">
							<input type="text"
								class="regular-text cb-core-alert-recipient-input"
								data-cb-core-alert-recipient
								data-cb-core-alert-group="reports"
								value="<?php echo esc_attr( $reports_recipient ); ?>"
								placeholder="<?php
									$audit_fb = '' !== $email_override ? $email_override : ( $admin_email ?: __( 'no address configured', 'core-blueprint' ) );
									/* translators: %s: fallback email address inherited from audit recipient or admin_email */
									echo esc_attr( sprintf( __( 'Defaults to %s', 'core-blueprint' ), $audit_fb ) );
								?>"
								autocomplete="off"
								spellcheck="false" />
							<button type="button"
								class="button button-primary cb-core-button cb-core-button--primary cb-core-alert-recipient-save"
								data-cb-core-alert-recipient-save
								data-cb-core-alert-group="reports">
								<?php esc_html_e( 'Save', 'core-blueprint' ); ?>
							</button>
							<?php echo \CoreBlueprint\Core\UI\FormStatus::render( [ 'data' => [ 'data-cb-core-alert-recipient-status' => '', 'data-cb-core-alert-group' => 'reports' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Optional override for report notifications. Leave empty to use the audit-tab recipient.', 'core-blueprint' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Alert on event', 'core-blueprint' ); ?></th>
					<td>
						<label class="cb-core-event-toggle">
							<input type="checkbox"
								class="cb-core-alert-toggle"
								data-cb-core-alert-group="reports"
								data-alert-key="generation_failed"
								<?php checked( ! empty( $reports_alerts['generation_failed'] ) ); ?> />
							<strong><?php esc_html_e( 'Report generation failed', 'core-blueprint' ); ?></strong>
							<span class="cb-core-muted cb-core-muted--gap">
								<?php esc_html_e( '- a Maintenance Report snapshot could not be generated', 'core-blueprint' ); ?>
							</span>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
	</section>

	<!-- ─── Scope note (page-level, not group-level) ────────────────── -->

	<?php
	echo \CoreBlueprint\Core\UI\Notice::render( [
		'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
		'title'   => __( 'Scope note:', 'core-blueprint' ),
		'message' => __( 'Failsafe emergency-bypass notifications always go to the site administrator address and cannot be redirected here. Keeping that channel out of the admin UI ensures lockout-recovery alerts remain reachable even if an administrator account is compromised.', 'core-blueprint' ),
		'class'   => 'cb-core-notification-footer',
	] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
	?>

</div>
