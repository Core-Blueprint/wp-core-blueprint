<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Safeguards - Core Shield tab (1.0.20+).
 *
 * Configuration hub for Core Blueprint's security-hardening layer. Extracted
 * from the old `security.php` during the 1.0.20 tab reshuffle, which split
 * that template's status-view + config-hub double role into two tabs:
 *
 *   - Overview (overview.php)      - read-only status, bypass banner, quick actions
 *   - Core Shield (this template)  - everything that mutates hardening state
 *
 * This template owns:
 *
 *   1. Complementary-plugin detector notice (Wordfence, etc. - affects which
 *      features Core Shield delegates rather than enforcing)
 *   2. Current Shield status and adaptive preset binding
 *   3. "Apply recommended defaults" button (overwrites feature toggles)
 *   4. Security modules list (Fingerprint, Headers - with feature-toggles)
 *   5. Security header test (diagnostic - verify Headers module is landing)
 *
 * Available variables (set by \CoreBlueprint\Core\Admin\Pages\Safeguards::render_core_shield_tab):
 *   $settings   - full settings array
 *   $site_mode  - current site mode ('hub' | 'production' | 'development')
 *   $detector   - output of \CoreBlueprint\Core\Detector::summary()
 *   $modules    - all registered \CoreBlueprint\Core\Security\Module instances
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap cb-core-wrap">

	<?php
	// Plain/Technical resolver for Shield copy. Hoisted above page-intro
	// so the intro itself can use mode-aware variants - keeps the suite's
	// plain↔technical philosophy visible at the very first paragraph
	// non-technical readers see.
	$_shield_mode = class_exists( '\CoreBlueprint\Core\UI' ) ? \CoreBlueprint\Core\UI::current_mode() : 'plain';
	if ( 'sync' === $_shield_mode ) {
		$_shield_mode = ( 'development' === $site_mode ) ? 'technical' : 'plain';
	}
	$_sh_pick = static function ( array $variants ) use ( $_shield_mode ) {
		return $variants[ $_shield_mode ] ?? ( $variants['plain'] ?? ( $variants['technical'] ?? '' ) );
	};
	?>

	<h1 class="cb-core-title"><?php esc_html_e( 'Core Shield', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php echo esc_html( $_sh_pick( [
			'plain'     => __( 'Configure how Core Blueprint security behaves. Master activation is managed from the Dashboard; when active, Shield adapts its hardening profile to Access Mode.', 'core-blueprint' ),
			'technical' => __( 'Configure Core Blueprint security features and hardening policy. The Dashboard owns the master gate; when enabled, Core Blueprint derives its hardening profile from Access Mode.', 'core-blueprint' ),
		] ) ); ?>
	</p>

	<!-- ─── Core Shield master switch ──────────────────────────────── -->

	<?php
	// Dashboard owns the master activation; this page reads the canonical
	// state only to present the effective security level and configuration.
	$_shield_on      = \CoreBlueprint\Core\Settings::shield_enabled();
	$_effective_mode = \CoreBlueprint\Core\Settings::effective_hardening_mode();
	?>

	<section class="cb-core-shield-panel">


		<!-- Master activation is managed from the Core Blueprint Dashboard. -->

		<div class="cb-core-shield-controls">
			<div class="cb-core-shield-status-group">
				<span class="cb-core-shield-control-label">
					<?php echo esc_html( $_sh_pick( [
						'plain'     => __( 'Security level', 'core-blueprint' ),
						'technical' => __( 'Active preset', 'core-blueprint' ),
					] ) ); ?>
				</span>
				<div class="cb-core-shield-status" aria-live="polite">
					<?php
					if ( $_shield_on ) {
						$_profile_label = 'hub' === $_effective_mode
							? $_sh_pick( [ 'plain' => __( 'Maximum', 'core-blueprint' ), 'technical' => __( 'Hub preset', 'core-blueprint' ) ] )
							: ( 'development' === $_effective_mode
								? $_sh_pick( [ 'plain' => __( 'Minimal (staging)', 'core-blueprint' ), 'technical' => __( 'Development preset', 'core-blueprint' ) ] )
								: $_sh_pick( [ 'plain' => __( 'Balanced', 'core-blueprint' ), 'technical' => __( 'Production preset', 'core-blueprint' ) ] ) );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Status::render() returns escape-clean HTML.
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
						echo \CoreBlueprint\Core\UI\Status::render( 'active', $_profile_label );
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						$_status_label = $_sh_pick( [
							'plain'     => __( 'Shield is off. All features are disabled.', 'core-blueprint' ),
							'technical' => __( 'Shield is off. All features disabled regardless of individual state.', 'core-blueprint' ),
						] );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Status::render() returns escape-clean HTML.
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
						echo \CoreBlueprint\Core\UI\Status::render( 'warning', $_status_label );
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
			</div>

			<div class="cb-core-shield-recommended">
				<button type="button" class="button cb-core-button cb-core-button--primary cb-core-apply-defaults"<?php echo $_shield_on ? '' : ' disabled'; ?>>
					<?php echo esc_html( $_sh_pick( [
						'plain'     => __( 'Apply recommended settings', 'core-blueprint' ),
						'technical' => __( 'Apply recommended defaults', 'core-blueprint' ),
					] ) ); ?>
				</button>
				<span class="cb-core-muted">
					<?php echo esc_html( $_sh_pick( [
						'plain'     => __( 'Overwrites current settings based on Shield + Access Mode. This is logged.', 'core-blueprint' ),
						'technical' => __( 'Overwrites current feature toggles based on Shield + Access Mode. Audit-logged.', 'core-blueprint' ),
					] ) ); ?>
				</span>
			</div>
		</div>

	</section>

	<!-- ─── Detector notice ────────────────────────────────────────── -->

	<?php if ( ! empty( $detector['plugins'] ) ) : ?>
		<?php
		$plugin_count = count( $detector['plugins'] );
		$detector_items = [];
		foreach ( $detector['plugins'] as $p ) {
			$detector_items[] = sprintf(
				/* translators: 1: plugin name, 2: delegated security features */
				__( '%1$s — handles: %2$s', 'core-blueprint' ),
				(string) ( $p['label'] ?? '' ),
				implode( ', ', (array) ( $p['features'] ?? [] ) )
			);
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
			'title'   => sprintf(
				/* translators: %d: number of detected security plugins */
				_n( 'Detected %d complementary security plugin', 'Detected %d complementary security plugins', $plugin_count, 'core-blueprint' ),
				$plugin_count
			),
			'message' => __( 'Core Blueprint security will delegate overlapping features to avoid double-enforcement.', 'core-blueprint' ),
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<details class="cb-core-disclosure cb-core-disclosure--compact cb-core-disclosure--subtle cb-core-detector-details">
			<summary class="cb-core-disclosure__summary">
				<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output ?>
				<?php echo \CoreBlueprint\Core\UI\Icon::render( 'expand', [ 'size' => \CoreBlueprint\Core\UI\Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); ?>
				<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="cb-core-disclosure__title"><?php esc_html_e( 'View delegated features', 'core-blueprint' ); ?></span>
			</summary>
			<div class="cb-core-disclosure__body">
				<ul class="cb-core-detector-list">
					<?php foreach ( $detector_items as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</details>
	<?php endif; ?>


	<!-- ─── Privileged Access Protection ─────────────────────────── -->

	<section class="cb-core-privileged-access" id="cb-core-privileged-access">
		<?php
		$_privileged_mode_enforce = \CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy::MODE_ENFORCE === $privileged_access_mode;
		$_privileged_mode_label   = $_privileged_mode_enforce
			? __( 'Enforce approval', 'core-blueprint' )
			: __( 'Monitor only', 'core-blueprint' );
		?>
		<div class="cb-core-section-header cb-core-privileged-access__header">
			<div>
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Privileged Access Protection', 'core-blueprint' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Core Blueprint always detects, fingerprints, logs and surfaces new or changed administrator-level identities for CB Operator review. The selected mode determines whether unapproved privileged access is also restricted while review is pending.', 'core-blueprint' ); ?>
				</p>
			</div>
			<span class="cb-core-badge cb-core-badge-standard" data-cb-core-privileged-mode-badge><?php echo esc_html( $_privileged_mode_label ); ?></span>
		</div>

		<?php if ( ! $can_manage_privileged ) : ?>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
			echo \CoreBlueprint\Core\UI\Notice::render( [
				'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
				'message' => sprintf(
					/* translators: %s: active privileged access protection mode */
					__( 'Current mode: %s. Only approved CB Operators can change this protection policy, inspect identities that require review, or approve privileged access.', 'core-blueprint' ),
					$_privileged_mode_label
				),
			] );
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		<?php else : ?>
			<div class="cb-core-radio-grid cb-core-radio-grid--columns-2 cb-core-privileged-access__modes" role="radiogroup" aria-label="<?php esc_attr_e( 'Privileged access protection mode', 'core-blueprint' ); ?>">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - RadioCard::render() escapes its own output.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
				echo \CoreBlueprint\Core\UI\RadioCard::render( [
					'name'       => 'cb-core-privileged-access-mode',
					'value'      => \CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy::MODE_ENFORCE,
					'label'      => __( 'Enforce approval — Recommended', 'core-blueprint' ),
					'desc'       => __( 'New or changed privileged accounts still require CB Operator review. Until approved, administrator-level capabilities are temporarily restricted.', 'core-blueprint' ),
					'checked'    => $_privileged_mode_enforce,
					'input_data' => [ 'data-cb-core-privileged-mode' => '' ],
				] );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - RadioCard::render() escapes its own output.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
				echo \CoreBlueprint\Core\UI\RadioCard::render( [
					'name'       => 'cb-core-privileged-access-mode',
					'value'      => \CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy::MODE_MONITOR,
					'label'      => __( 'Monitor only', 'core-blueprint' ),
					'desc'       => __( 'Detection, fingerprinting, audit logging and review remain active. Existing WordPress permissions keep working while review is pending; Core Blueprint trust-authority controls still require approval.', 'core-blueprint' ),
					'checked'    => ! $_privileged_mode_enforce,
					'input_data' => [ 'data-cb-core-privileged-mode' => '' ],
				] );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<p class="description">
				<?php esc_html_e( 'Changing mode never approves an account and never erases its review history. Detection and review stay active in both modes.', 'core-blueprint' ); ?>
			</p>

			<?php if ( ! $_privileged_mode_enforce ) : ?>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
				echo \CoreBlueprint\Core\UI\Notice::render( [
					'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
					'title'   => __( 'Monitor only provides visibility, not automatic restriction', 'core-blueprint' ),
					'message' => __( 'Unapproved administrator-level accounts can continue using their existing WordPress permissions until you review them. Core Blueprint trust-authority controls stay protected. Use Enforce approval when you also want administrator-level WordPress access restricted automatically.', 'core-blueprint' ),
				] );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>

			<?php $review_count = count( $privileged_review_users ); ?>

			<?php if ( empty( $privileged_review_users ) ) : ?>
				<div class="cb-core-privileged-access__clear" aria-live="polite">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML.
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
					echo \CoreBlueprint\Core\UI\StateBadge::render( __( 'All clear', 'core-blueprint' ), [ 'variant' => \CoreBlueprint\Core\UI\StateBadge::SUCCESS ] );
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<span class="cb-core-muted">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: approved privileged identities */
								_n( '%d approved privileged identity', '%d approved privileged identities', $approved_privileged_count, 'core-blueprint' ),
								$approved_privileged_count
							)
						);
						?>
					</span>
				</div>
			<?php else : ?>
				<details class="cb-core-disclosure cb-core-disclosure--section cb-core-disclosure--subtle cb-core-privileged-review">
					<summary class="cb-core-disclosure__summary">
						<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output ?>
						<?php echo \CoreBlueprint\Core\UI\Icon::render( 'expand', [ 'size' => \CoreBlueprint\Core\UI\Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); ?>
						<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="cb-core-disclosure__title">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: privileged identities waiting for review */
									_n( '%d privileged identity requires review', '%d privileged identities require review', $review_count, 'core-blueprint' ),
									$review_count
								)
							);
							?>
						</span>
						<span class="cb-core-disclosure__meta">
							<?php
							$_review_badge = $_privileged_mode_enforce ? __( 'Restricted until approved', 'core-blueprint' ) : __( 'Access not restricted', 'core-blueprint' );
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - StateBadge::render() returns escape-clean HTML.
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
							echo \CoreBlueprint\Core\UI\StateBadge::render( $_review_badge, [ 'variant' => \CoreBlueprint\Core\UI\StateBadge::WARNING ] );
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</span>
					</summary>
					<div class="cb-core-disclosure__body">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Notice::render() returns escape-clean HTML.
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
						echo \CoreBlueprint\Core\UI\Notice::render( [
							'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
							'message' => __( 'Privileged Access Protection is independent of the Core Shield master switch. Turning Shield off does not stop privileged-account detection, fingerprinting, review or the selected approval policy.', 'core-blueprint' ),
						] );
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>

						<div class="cb-core-privileged-access__summary" aria-live="polite">
							<strong>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: privileged users requiring review, 2: approved privileged users */
										__( '%1$d need review · %2$d approved privileged identities', 'core-blueprint' ),
										$review_count,
										$approved_privileged_count
									)
								);
								?>
							</strong>
						</div>

						<div class="cb-core-table-scroll">
							<table class="widefat striped cb-core-privileged-access__table">
								<colgroup>
									<col class="cb-core-privileged-access__col-account">
									<col class="cb-core-privileged-access__col-roles">
									<col class="cb-core-privileged-access__col-capabilities">
									<col class="cb-core-privileged-access__col-detected">
									<col class="cb-core-privileged-access__col-action">
								</colgroup>
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Account', 'core-blueprint' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Roles', 'core-blueprint' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Privileged capabilities', 'core-blueprint' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Detected', 'core-blueprint' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Action', 'core-blueprint' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $privileged_review_users as $entry ) : ?>
										<?php
										$user = $entry['user'] ?? null;
										if ( ! ( $user instanceof \WP_User ) ) {
											continue;
										}
										$roles         = array_map( 'strval', (array) ( $entry['roles'] ?? $user->roles ) );
										$critical_caps = array_map( 'strval', (array) ( $entry['critical_caps'] ?? [] ) );
										$detected_at   = (int) ( $entry['detected_at'] ?? 0 );
										$reason        = (string) ( $entry['reason'] ?? '' );
										$source        = (string) ( $entry['source'] ?? '' );
										?>
										<tr data-cb-core-privileged-row data-user-id="<?php echo (int) $user->ID; ?>">
											<td>
												<strong><?php echo esc_html( (string) $user->user_login ); ?></strong>
												<?php if ( '' !== trim( (string) $user->display_name ) && (string) $user->display_name !== (string) $user->user_login ) : ?>
													<br><span><?php echo esc_html( (string) $user->display_name ); ?></span>
												<?php endif; ?>
												<br><a class="cb-core-privileged-access__email" href="mailto:<?php echo esc_attr( (string) $user->user_email ); ?>"><?php echo esc_html( (string) $user->user_email ); ?></a><br>
												<span class="description">
													<?php
													$registered = '' !== (string) $user->user_registered
														? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $user->user_registered )
														: '';
													echo esc_html(
														sprintf(
															/* translators: 1: WordPress user ID, 2: registration date/time */
															__( 'User ID: %1$d · Registered: %2$s', 'core-blueprint' ),
															(int) $user->ID,
															'' !== $registered ? $registered : __( 'Unknown', 'core-blueprint' )
														)
													);
													?>
												</span>
											</td>
											<td><code><?php echo esc_html( implode( ', ', $roles ) ); ?></code></td>
											<td>
												<?php if ( ! empty( $critical_caps ) ) : ?>
													<code><?php echo esc_html( implode( ', ', $critical_caps ) ); ?></code>
												<?php elseif ( in_array( 'administrator', $roles, true ) ) : ?>
													<code>administrator</code>
												<?php else : ?>
													<em><?php esc_html_e( 'Privileged identity', 'core-blueprint' ); ?></em>
												<?php endif; ?>
											</td>
											<td>
												<span class="cb-core-privileged-access__detected-at"><?php echo $detected_at > 0 ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $detected_at ) ) : '—'; ?></span>
												<br><span class="description cb-core-privileged-access__detected-source"><?php echo esc_html( trim( $reason . ( '' !== $source ? ' · ' . $source : '' ) ) ); ?></span>
											</td>
											<td>
												<button
													type="button"
													class="button cb-core-button cb-core-button--primary cb-core-button--compact cb-core-privileged-approve"
													data-user-id="<?php echo (int) $user->ID; ?>"
													data-user-login="<?php echo esc_attr( (string) $user->user_login ); ?>"
												>
													<?php esc_html_e( 'Approve access', 'core-blueprint' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<p class="description">
							<?php esc_html_e( 'Approve only after verifying that the account and requested administrator-level privileges are expected. Approval is bound to the current privilege fingerprint; later privilege changes automatically require a new review.', 'core-blueprint' ); ?>
						</p>
					</div>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<?php
	// Description variant (plain or technical) used by module/feature blocks
	// further down to render the appropriate description text.
	$desc_mode_effective = \CoreBlueprint\Core\UI::current_mode();
	$desc_mode_site      = \CoreBlueprint\Core\UI::site_default_mode();
	$active_variant      = 'sync' === $desc_mode_effective
		? ( 'technical' === $desc_mode_site ? 'technical' : 'plain' )
		: $desc_mode_effective;

	// Compute the global master-toggle state from the per-module flags so
	// the UI reflects reality on first paint (all-on / all-off / mixed).
	$_all_modules_total   = 0;
	$_all_modules_enabled = 0;
	if ( ! empty( $modules ) ) {
		foreach ( $modules as $_m ) {
			$_all_modules_total++;
			if ( ! empty( $settings['modules'][ $_m->slug() ]['enabled'] ) ) {
				$_all_modules_enabled++;
			}
		}
	}
	$_global_state = 'off';
	if ( $_all_modules_total > 0 && $_all_modules_enabled === $_all_modules_total ) {
		$_global_state = 'on';
	} elseif ( $_all_modules_enabled > 0 ) {
		$_global_state = 'mixed';
	}
	?>

	<!-- ─── Modules list ───────────────────────────────────────────── -->

	<section class="cb-core-module-rack cb-core-module-rack--compact">
		<div class="cb-core-section-header">
			<h2 class="cb-core-section-title"><?php esc_html_e( 'Security modules', 'core-blueprint' ); ?></h2>
			<?php if ( ! empty( $modules ) ) : ?>
				<label class="cb-core-rack-toggle cb-core-global-toggle" title="<?php esc_attr_e( 'Enable or disable all modules at once', 'core-blueprint' ); ?>">
					<span class="cb-core-global-toggle-label"><?php esc_html_e( 'All modules', 'core-blueprint' ); ?></span>
					<input
						type="checkbox"
						class="cb-core-all-modules-toggle"
						data-state="<?php echo esc_attr( $_global_state ); ?>"
						<?php checked( 'on' === $_global_state ); ?>
					/>
					<span class="cb-core-rack-toggle-track" aria-hidden="true"><span class="cb-core-rack-toggle-thumb"></span></span>
					<span class="screen-reader-text"><?php esc_html_e( 'All modules enabled', 'core-blueprint' ); ?></span>
				</label>
			<?php endif; ?>
		</div>

		<?php if ( empty( $modules ) ) : ?>
			<div class="cb-core-empty">
				<strong><?php esc_html_e( 'No feature modules registered yet.', 'core-blueprint' ); ?></strong>
				<?php esc_html_e( 'Feature modules are loaded via the core_blueprint_modules filter. The built-in modules ship with Core Blueprint itself; additional modules can be provided by other Core Blueprint plugins.', 'core-blueprint' ); ?>
			</div>
		<?php else : ?>
			<?php foreach ( $modules as $module ) :
				$slug     = $module->slug();
				$enabled  = ! empty( $settings['modules'][ $slug ]['enabled'] );
				$features = $module->features();

				// Module description can come back in two shapes per the
				// Module interface contract:
				//   - string                                 → technical-only
				//   - [ 'plain' => ..., 'technical' => ... ] → preferred dual form
				// Plus modules can optionally implement description_plain()
				// as a separate accessor. Normalise here so the rest of the
				// template doesn't need to know which path produced the data.
				$module_desc_raw = $module->description();

				if ( is_array( $module_desc_raw ) ) {
					$module_desc_technical = (string) ( $module_desc_raw['technical'] ?? '' );
					$array_desc_plain      = (string) ( $module_desc_raw['plain']     ?? '' );
				} else {
					$module_desc_technical = (string) $module_desc_raw;
					$array_desc_plain      = '';
				}

				// description_plain() takes priority over an array-shape
				// 'plain' key when both are provided - a module that
				// explicitly opted into the separate accessor signalled
				// its intent to override the array form.
				$module_desc_plain = method_exists( $module, 'description_plain' )
					? (string) $module->description_plain()
					: $array_desc_plain;

				$module_desc = ( '' !== $module_desc_plain )
					? [ 'plain' => $module_desc_plain, 'technical' => $module_desc_technical ]
					: $module_desc_technical;

				$module_badges            = method_exists( $module, 'badges' ) ? (array) $module->badges() : [];
				$module_summary_plain      = wp_trim_words( wp_strip_all_tags( \CoreBlueprint\Core\UI::pick_description( $module_desc, 'plain' ) ), 18, '…' );
				$module_summary_technical  = wp_trim_words( wp_strip_all_tags( \CoreBlueprint\Core\UI::pick_description( $module_desc, 'technical' ) ), 18, '…' );
			?>
				<div class="cb-core-module <?php echo $enabled ? 'is-enabled' : ''; ?>" data-module="<?php echo esc_attr( $slug ); ?>">
					<div class="cb-core-module-header">
						<span class="cb-core-rack-dot <?php echo $enabled ? 'is-active' : 'is-idle'; ?>" aria-hidden="true"></span>
						<div class="cb-core-module-header-content">
							<div class="title"><?php echo esc_html( $module->label() ); ?></div>
							<?php if ( '' !== $module_summary_plain || '' !== $module_summary_technical ) : ?>
								<div class="cb-core-module-summary cb-core-dual" data-active="<?php echo esc_attr( $active_variant ); ?>">
									<span class="cb-core-desc-plain" <?php echo 'plain' === $active_variant ? '' : 'hidden'; ?>><?php echo esc_html( '' !== $module_summary_plain ? $module_summary_plain : $module_summary_technical ); ?></span>
									<span class="cb-core-desc-technical" <?php echo 'technical' === $active_variant ? '' : 'hidden'; ?>><?php echo esc_html( '' !== $module_summary_technical ? $module_summary_technical : $module_summary_plain ); ?></span>
								</div>
							<?php endif; ?>
						</div>
						<label class="cb-core-rack-toggle">
							<input type="checkbox" class="cb-core-module-toggle" <?php checked( $enabled ); ?> />
							<span class="cb-core-rack-toggle-track" aria-hidden="true"><span class="cb-core-rack-toggle-thumb"></span></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Enabled', 'core-blueprint' ); ?></span>
						</label>
						<button type="button" class="cb-core-module-collapse" aria-expanded="false" aria-label="<?php esc_attr_e( 'Toggle module details', 'core-blueprint' ); ?>">
							<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() owns context-specific escaping for its complete SVG payload. ?>
							<?php echo \CoreBlueprint\Core\UI\Icon::render( 'expand', [ 'class' => 'cb-core-chevron', 'size' => 'compact' ] ); ?>
							<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
					</div>

					<div class="cb-core-module-body">
						<div class="cb-core-module-body-inner">
						<div class="cb-core-module-details">
							<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- existing callsite documents this output boundary as safe. ?>
							<?php echo \CoreBlueprint\Core\UI::render_description_text( $module_desc, $active_variant, 'cb-core-module-description' ); ?>
							<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- existing callsite documents this output boundary as safe. ?>
							<?php echo \CoreBlueprint\Core\UI::render_badges( $module_badges ); ?>
							<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>

					<?php if ( ! empty( $features ) ) : ?>
						<div class="cb-core-feature-list">
							<?php foreach ( $features as $feature ) :
								$feature_id = $feature['id'] ?? '';
								if ( empty( $feature_id ) ) {
									continue;
								}

								$feature_enabled = ! empty( $settings['modules'][ $slug ]['features'][ $feature_id ] );
								$risk            = $feature['risk'] ?? 'none';
								$is_restrictive  = ! empty( $feature['restrictive'] );
								$conflict_id     = $feature['conflict'] ?? null;
								$delegated_to    = $conflict_id ? \CoreBlueprint\Core\Detector::delegated_to( $conflict_id ) : null;
								$delegated_label = $delegated_to ? \CoreBlueprint\Core\Detector::plugin_label( $delegated_to ) : null;
								$feature_desc      = $feature['description'] ?? '';
								$feature_badges    = (array) ( $feature['badges'] ?? [] );
								$feature_label     = (string) ( $feature['label'] ?? $feature_id );
								$feature_detail_id = 'cb-core-feature-details-' . sanitize_html_class( $slug . '-' . $feature_id );
							?>
								<div class="cb-core-feature <?php echo $delegated_label ? 'is-delegated' : ''; ?> <?php
									if ( $delegated_label ) {
										echo 'cb-core-feature--delegated';
									} elseif ( $feature_enabled && $enabled ) {
										echo 'cb-core-feature--active';
									} else {
										echo 'cb-core-feature--idle';
									}
								?>" data-feature="<?php echo esc_attr( $feature_id ); ?>">
									<div class="cb-core-feature-main">
										<span class="cb-core-rack-dot <?php
											if ( $delegated_label ) {
												echo 'is-delegated';
											} elseif ( $feature_enabled && $enabled ) {
												echo 'is-active';
											} else {
												echo 'is-idle';
											}
										?>" aria-hidden="true"></span>

										<div class="cb-core-feature-copy">
											<div class="cb-core-feature-heading">
												<span class="cb-core-feature-label"><?php echo esc_html( $feature_label ); ?></span>

												<?php if ( 'high' === $risk ) : ?>
													<span class="cb-core-badge cb-core-badge-risk-high"><?php esc_html_e( 'high risk', 'core-blueprint' ); ?></span>
												<?php elseif ( 'medium' === $risk ) : ?>
													<span class="cb-core-badge cb-core-badge-risk-medium"><?php esc_html_e( 'medium risk', 'core-blueprint' ); ?></span>
												<?php endif; ?>

												<?php if ( $is_restrictive ) : ?>
													<span class="cb-core-badge cb-core-badge-restrictive"><?php esc_html_e( 'restrictive', 'core-blueprint' ); ?></span>
												<?php endif; ?>
											</div>

											<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- existing callsite documents this output boundary as safe. ?>
											<?php echo \CoreBlueprint\Core\UI::render_description_text( $feature_desc, $active_variant, 'cb-core-feature-summary' ); ?>
											<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>

										<label class="cb-core-rack-toggle">
											<input
												type="checkbox"
												class="cb-core-feature-toggle"
												data-feature="<?php echo esc_attr( $feature_id ); ?>"
												<?php checked( $feature_enabled ); ?>
												<?php disabled( (bool) $delegated_label || ! $enabled ); ?>
											/>
											<span class="cb-core-rack-toggle-track" aria-hidden="true"><span class="cb-core-rack-toggle-thumb"></span></span>
											<span class="screen-reader-text"><?php esc_html_e( 'Enabled', 'core-blueprint' ); ?></span>
										</label>

										<?php
										/* translators: %s: security feature label. */
										$feature_details_label = sprintf( __( 'Toggle details for %s', 'core-blueprint' ), $feature_label );
										?>
										<button
											type="button"
											class="cb-core-feature-details-toggle"
											aria-expanded="false"
											aria-controls="<?php echo esc_attr( $feature_detail_id ); ?>"
											aria-label="<?php echo esc_attr( $feature_details_label ); ?>"
										>
											<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() owns context-specific escaping for its complete SVG payload. ?>
											<?php echo \CoreBlueprint\Core\UI\Icon::render( 'expand', [ 'class' => 'cb-core-feature-chevron', 'size' => 'compact' ] ); ?>
											<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</button>
									</div>

									<div class="cb-core-feature-body" id="<?php echo esc_attr( $feature_detail_id ); ?>" hidden>
										<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- existing callsite documents this output boundary as safe. ?>
										<?php echo \CoreBlueprint\Core\UI::render_description_text( $feature_desc, $active_variant, 'cb-core-feature-description' ); ?>
										<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>

										<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UI::render_badges() escapes URLs, attributes, and visible labels for every supported badge type. ?>
										<?php echo \CoreBlueprint\Core\UI::render_badges( $feature_badges ); ?>
										<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>

										<?php if ( $delegated_label ) : ?>
											<div class="cb-core-feature-delegated">
												<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- StateBadge::render() owns context-specific escaping for its complete structured payload. ?>
												<?php echo \CoreBlueprint\Core\UI\StateBadge::render( __( 'Delegated', 'core-blueprint' ), [ 'variant' => \CoreBlueprint\Core\UI\StateBadge::INFO ] ); ?>
												<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												<span>
													<?php
													printf(
														/* translators: %s: name of the plugin that handles this feature */
														esc_html__( 'Handled by %s — Core Blueprint will not enforce.', 'core-blueprint' ),
														esc_html( $delegated_label )
													);
													?>
												</span>
											</div>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
						</div><!-- /.cb-core-module-body-inner -->
					</div><!-- /.cb-core-module-body -->
				</div><!-- /.cb-core-module -->
			<?php endforeach; ?>
		<?php endif; ?>
	</section>

	<!-- ─── Diagnostics ────────────────────────────────────────────── -->

	<section class="cb-core-diagnostics">
		<h2 class="cb-core-section-title"><?php esc_html_e( 'Diagnostics', 'core-blueprint' ); ?></h2>
		<div class="cb-core-header-test">
			<h3><?php esc_html_e( 'Security header test', 'core-blueprint' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Fetch the public homepage of this site and inspect which security-relevant response headers are present. Useful for verifying the Security Headers module landed correctly and for auditing the baseline before enabling features.', 'core-blueprint' ); ?>
			</p>
			<div class="cb-core-actions">
				<button type="button" class="button cb-core-button cb-core-button--primary cb-core-run-header-test">
					<?php esc_html_e( 'Run header test', 'core-blueprint' ); ?>
				</button>
				<span class="cb-core-muted cb-core-muted--inset">
					<?php esc_html_e( 'Fetches:', 'core-blueprint' ); ?>
					<code><?php echo esc_html( home_url( '/' ) ); ?></code>
				</span>
			</div>
			<div class="cb-core-header-test-results" aria-live="polite"></div>
		</div>
	</section>

</div>
