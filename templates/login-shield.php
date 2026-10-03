<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Login Shield tab.
 *
 * Configuration UI for Core Blueprint's passive login-hardening feature.
 * Renders the custom-URL input with live preview, protection
 * mode radios (Standard / Strict), post-login redirect dropdown, and an
 * advanced block-response code selector. All writes go through the
 * `cb_core_login_shield_save` AJAX endpoint.
 *
 * Copy is deliberately kept to a level a non-technical user can parse:
 * plain language on the top-line controls; terminology creeps in only in
 * the Advanced section where its readers accept it.
 *
 * Available variables (set by {@see \CoreBlueprint\Core\Admin\Pages\Safeguards::render_login_shield_tab}):
 *   $config         array  - normalised login_shield config
 *   $custom_url     string - public URL for the currently-saved slug (empty if no slug)
 *   $shield_on      bool   - Core Shield master switch state
 *   $bypassed       bool   - any Failsafe bypass layer active
 *   $enforcing      bool   - all gates open, Login Shield is live on this request
 *   $safeguards_url string - link to the Failsafe tab on this page
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$ls_mode          = $config['mode'] ?? \CoreBlueprint\Core\Security\LoginShield::MODE_STANDARD;
$ls_redirect      = $config['redirect_after_login'] ?? \CoreBlueprint\Core\Security\LoginShield::REDIRECT_DASHBOARD;
$ls_response_code = (int) ( $config['block_response_code'] ?? 404 );
?>
<div class="wrap cb-core-wrap cb-core-login-shield" data-cb-core-login-shield>

	<h1 class="cb-core-title"><?php esc_html_e( 'Login Shield', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Reduces the volume of brute-force scans against your login page by serving it from a URL that only you know. Automated attackers that blindly hit /wp-login.php get a 404 and move on. Login Shield does not block targeted attacks against a specific account - for that, pair it with a rate-limiting or 2FA plugin.', 'core-blueprint' ); ?>
	</p>

	<!-- Master activation is managed from the Core Blueprint Dashboard. -->

	<!-- ─── Runtime status ────────────────────────────────────────── -->

	<?php
	if ( ! $shield_on ) {
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
			'title'   => __( 'Core Shield is off.', 'core-blueprint' ),
			'message' => __( 'Login Shield stands down while the global Core Shield master switch is disabled. Your settings here are saved but not enforced.', 'core-blueprint' ),
		] );
	} elseif ( $bypassed ) {
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
			'title'   => __( 'Failsafe bypass is active.', 'core-blueprint' ),
			'message' => __( 'While the bypass window is open, Login Shield does not enforce - this is the lockout-recovery guarantee. Enforcement resumes automatically when the bypass ends.', 'core-blueprint' ),
		] );
	} elseif ( ! empty( $config['enabled'] ) && '' === $config['slug'] ) {
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => \CoreBlueprint\Core\UI\Notice::WARNING,
			'title'   => __( 'Login Shield is enabled but no custom URL is set.', 'core-blueprint' ),
			'message' => __( 'Choose a custom URL below and save to activate.', 'core-blueprint' ),
		] );
	} elseif ( empty( $config['enabled'] ) && '' !== $config['slug'] ) {
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
			'title'   => __( 'Login Shield is disabled.', 'core-blueprint' ),
			'message' => __( 'Your settings are saved but not enforced. Enable Login Shield from the Core Blueprint Dashboard when you are ready to apply them.', 'core-blueprint' ),
		] );
	} elseif ( $enforcing ) {
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => \CoreBlueprint\Core\UI\Notice::SUCCESS,
			'title'   => __( 'Login Shield is active.', 'core-blueprint' ),
			'message' => sprintf(
				/* translators: %s: the custom login URL, e.g. https://example.com/my-login/ */
				__( 'Your login page is served from %s.', 'core-blueprint' ),
				$custom_url
			),
		] );
	}
	?>

	<!-- ─── Settings form ──────────────────────────────────────────── -->

	<section class="cb-core-ls-section">

		<h2><?php esc_html_e( 'Configuration', 'core-blueprint' ); ?></h2>

		<form class="cb-core-ls-form" data-cb-core-ls-form onsubmit="return false;">

			<!-- 1. Custom URL slug -->

			<?php
			ob_start();
			?>
				<div class="cb-core-ls-slug-row">
					<span class="cb-core-ls-slug-prefix"><?php echo esc_html( trailingslashit( home_url( '/' ) ) ); ?></span>
					<input type="text"
						id="cb-core-ls-slug"
						name="slug"
						class="regular-text cb-core-ls-slug-input"
						value="<?php echo esc_attr( $config['slug'] ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. private-entrance', 'core-blueprint' ); ?>"
						autocomplete="off"
						spellcheck="false"
						data-cb-core-ls-slug />
					<span class="cb-core-ls-slug-suffix">/</span>
				</div>
				<p class="description cb-core-ls-preview"
					data-cb-core-ls-preview
					data-url-base="<?php echo esc_attr( trailingslashit( home_url( '/' ) ) ); ?>">
					<?php
					if ( '' !== $config['slug'] ) {
						$preview_html = sprintf(
							/* translators: %s: full preview URL wrapped in a code element. */
							__( 'Preview: %s', 'core-blueprint' ),
							'<code data-cb-core-ls-preview-url>' . esc_html( $custom_url ) . '</code>'
						);
						echo wp_kses(
							$preview_html,
							[
								'code' => [
									'data-cb-core-ls-preview-url' => true,
								],
							]
						);
					} else {
						echo '<code data-cb-core-ls-preview-url hidden></code>';
						esc_html_e( 'No URL configured yet.', 'core-blueprint' );
					}
					?>
				</p>
			<?php
			echo \CoreBlueprint\Core\UI\Field::render( [
				'variant'   => 'separated',
				'label'     => __( 'Custom login URL', 'core-blueprint' ),
				'label_sub' => __( 'Letters, numbers, and hyphens only. Pick something memorable to you but not guessable - avoid obvious choices like "login" or "admin".', 'core-blueprint' ),
				'label_for' => 'cb-core-ls-slug',
				'control'   => ob_get_clean(),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
			?>

			<!-- 2. Protection mode -->

			<?php
			echo \CoreBlueprint\Core\UI\Field::render( [
				'variant' => 'separated',
				'label'   => __( 'Protection mode', 'core-blueprint' ),
				'control' => \CoreBlueprint\Core\UI\RadioGroup::render( [
					'name'    => 'mode',
					'value'   => $ls_mode,
					'options' => [
						[
							'value'      => \CoreBlueprint\Core\Security\LoginShield::MODE_STANDARD,
							'label'      => __( 'Standard', 'core-blueprint' ),
							'desc'       => __( 'Blocks /wp-login.php for guests. Visitors to /wp-admin are redirected to your custom URL. Recommended default.', 'core-blueprint' ),
							'input_data' => [ 'data-cb-core-ls-mode' => '' ],
						],
						[
							'value'      => \CoreBlueprint\Core\Security\LoginShield::MODE_STRICT,
							'label'      => __( 'Strict', 'core-blueprint' ),
							'desc'       => __( 'Blocks /wp-login.php AND /wp-admin for guests - no redirect. Your custom URL is never revealed to attackers who probe default endpoints. Requires you to remember the URL.', 'core-blueprint' ),
							'input_data' => [ 'data-cb-core-ls-mode' => '' ],
						],
					],
				] ),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
			?>

			<!-- 3. Redirect after login -->

			<?php
			ob_start();
			?>
				<select id="cb-core-ls-redirect" name="redirect_after_login" data-cb-core-ls-redirect>
					<option value="<?php echo esc_attr( \CoreBlueprint\Core\Security\LoginShield::REDIRECT_DASHBOARD ); ?>" <?php selected( $ls_redirect, \CoreBlueprint\Core\Security\LoginShield::REDIRECT_DASHBOARD ); ?>>
						<?php esc_html_e( 'Dashboard (WP default)', 'core-blueprint' ); ?>
					</option>
					<option value="<?php echo esc_attr( \CoreBlueprint\Core\Security\LoginShield::REDIRECT_HOMEPAGE ); ?>" <?php selected( $ls_redirect, \CoreBlueprint\Core\Security\LoginShield::REDIRECT_HOMEPAGE ); ?>>
						<?php esc_html_e( 'Homepage', 'core-blueprint' ); ?>
					</option>
					<option value="<?php echo esc_attr( \CoreBlueprint\Core\Security\LoginShield::REDIRECT_CUSTOM ); ?>" <?php selected( $ls_redirect, \CoreBlueprint\Core\Security\LoginShield::REDIRECT_CUSTOM ); ?>>
						<?php esc_html_e( 'Custom URL', 'core-blueprint' ); ?>
					</option>
				</select>

				<div class="cb-core-ls-redirect-custom"
					data-cb-core-ls-redirect-custom
					<?php echo \CoreBlueprint\Core\Security\LoginShield::REDIRECT_CUSTOM === $ls_redirect ? '' : 'hidden'; ?>>
					<input type="url"
						name="redirect_custom_url"
						class="regular-text"
						value="<?php echo esc_attr( $config['redirect_custom_url'] ); ?>"
						placeholder="https://example.com/members/"
						autocomplete="off" />
				</div>
			<?php
			echo \CoreBlueprint\Core\UI\Field::render( [
				'variant'   => 'separated',
				'label'     => __( 'Redirect after login', 'core-blueprint' ),
				'label_sub' => __( 'Fallback destination - used only when no other rule claims the target. Form-level redirects always win: if a Bricks, BricksForge, Elementor, or standard WP login form sends a redirect_to value, that is honoured. Plugin-level role redirects also win: WooCommerce routing customers to My Account, membership plugins routing members to their dashboard, LMS plugins routing students to their course overview - all take precedence over this setting.', 'core-blueprint' ),
				'label_for' => 'cb-core-ls-redirect',
				'control'   => ob_get_clean(),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
			?>

			<!-- 4. Advanced - response code -->

			<details class="cb-core-disclosure cb-core-disclosure--compact cb-core-ls-advanced">
				<summary class="cb-core-disclosure__summary">
					<?php echo \CoreBlueprint\Core\UI\Icon::render( 'expand', [ 'size' => \CoreBlueprint\Core\UI\Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
					<span class="cb-core-disclosure__title"><?php esc_html_e( 'Advanced', 'core-blueprint' ); ?></span>
				</summary>
				<div class="cb-core-disclosure__body">

				<?php
				echo \CoreBlueprint\Core\UI\Field::render( [
					'variant'   => 'separated',
					'label'     => __( 'Block response', 'core-blueprint' ),
					'label_sub' => __( 'What blocked requests receive. 404 hides the endpoint from automated fingerprinting; 403 is explicit and useful for audit trails; a homepage redirect is the friendliest option for real visitors who bookmarked /wp-login.php.', 'core-blueprint' ),
					'control'   => \CoreBlueprint\Core\UI\RadioGroup::render( [
						'variant' => 'compact',
						'name'    => 'block_response_code',
						'value'   => $ls_response_code,
						'options' => [
							[
								'value' => \CoreBlueprint\Core\Security\LoginShield::RESPONSE_CODE_404,
								'label' => '404 Not Found',
								'desc'  => __( 'Recommended', 'core-blueprint' ),
							],
							[
								'value' => \CoreBlueprint\Core\Security\LoginShield::RESPONSE_CODE_403,
								'label' => '403 Forbidden',
							],
							[
								'value' => \CoreBlueprint\Core\Security\LoginShield::RESPONSE_CODE_302,
								'label' => __( 'Redirect to homepage', 'core-blueprint' ),
								'desc'  => __( '302', 'core-blueprint' ),
							],
						],
					] ),
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
				?>
				</div>
			</details>

			<!-- Actions -->

			<div class="cb-core-ls-actions">
				<button type="button" class="button cb-core-button cb-core-button--primary" data-cb-core-ls-save>
					<?php esc_html_e( 'Save Login Shield settings', 'core-blueprint' ); ?>
				</button>

				<button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-core-ls-test
					<?php disabled( '' === $config['slug'] ); ?>>
					<?php esc_html_e( 'Test custom URL', 'core-blueprint' ); ?>
				</button>

				<?php echo \CoreBlueprint\Core\UI\FormStatus::render( [ 'data' => [ 'data-cb-core-ls-save-status' => '' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
			</div>

		</form>
	</section>

	<!-- ─── Notes ─────────────────────────────────────────────────── -->

	<details class="cb-core-disclosure cb-core-disclosure--compact cb-core-disclosure--subtle cb-core-ls-notes">
		<summary class="cb-core-disclosure__summary">
			<?php echo \CoreBlueprint\Core\UI\Icon::render( 'expand', [ 'size' => \CoreBlueprint\Core\UI\Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
			<span class="cb-core-disclosure__title"><?php esc_html_e( 'What Login Shield does not do', 'core-blueprint' ); ?></span>
		</summary>
		<div class="cb-core-disclosure__body">
			<ul>
				<li><?php esc_html_e( 'It does not count failed login attempts. For rate limiting and IP lockouts, use a dedicated plugin (Wordfence, Solid Security, Limit Login Attempts Reloaded).', 'core-blueprint' ); ?></li>
				<li><?php esc_html_e( 'It does not close XML-RPC (xmlrpc.php). That is a separate endpoint and a separate configuration task.', 'core-blueprint' ); ?></li>
				<li><?php esc_html_e( 'It does not protect against attackers who already know your custom URL. Treat the URL as a low-friction obstacle, not a secret credential.', 'core-blueprint' ); ?></li>
				<li><?php esc_html_e( 'It does not replace two-factor authentication. 2FA remains the strongest protection against credential theft; Login Shield compliments it by reducing scan noise.', 'core-blueprint' ); ?></li>
			</ul>
		</div>
	</details>

	<!-- ─── Failsafe reminder ─────────────────────────────────────── -->

	<p class="cb-core-ls-failsafe-hint">
		<?php
		$failsafe_hint = sprintf(
			/* translators: %s: HTML link to the Failsafe tab. */
			__( 'Locked out after changing your login URL? Use the %s to recover.', 'core-blueprint' ),
			'<a href="' . esc_url( $safeguards_url ) . '">' . esc_html__( 'Failsafe bypass URL', 'core-blueprint' ) . '</a>'
		);
		echo wp_kses(
			$failsafe_hint,
			[
				'a' => [
					'href' => true,
				],
			]
		);
		?>
	</p>

</div>
