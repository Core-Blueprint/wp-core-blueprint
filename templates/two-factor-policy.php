<?php
/**
 * Template: Two-Factor policy tab.
 *
 * Available variables:
 * - $policy         normalized site policy
 * - $can_manage     whether current identity is a trusted approved CB Operator
 * - $base_enrolled whether current identity has Base-owned 2FA enrolled
 * - $providers      supported external providers active for current identity
 * - $bypassed       whether Failsafe currently bypasses restrictive features
 * - $profile_url    current user's native WordPress profile URL
 */

defined( 'ABSPATH' ) || exit;

$current_mode = (string) ( $policy['mode'] ?? \CB\Core\Security\TwoFactor\Policy::MODE_OPTIONAL );
?>
<div class="wrap cb-core-wrap cb-core-two-factor-policy" data-cb-core-two-factor-policy>
	<h1 class="cb-core-title"><?php esc_html_e( 'Two-factor authentication', 'core-blueprint' ); ?></h1>
	<p class="cb-core-intro">
		<?php esc_html_e( 'Control whether privileged WordPress accounts are required to use a second factor. Base provides TOTP and one-time recovery codes, while supported external providers can remain responsible for users they already protect.', 'core-blueprint' ); ?>
	</p>

	<?php
	if ( $bypassed ) {
		echo \CB\Core\UI\Notice::render( [
			'variant' => \CB\Core\UI\Notice::WARNING,
			'title'   => __( 'Failsafe bypass is active.', 'core-blueprint' ),
			'message' => __( 'Two-factor enforcement is temporarily bypassed. A new enforce policy cannot be enabled until Failsafe is closed.', 'core-blueprint' ),
		] );
	}

	if ( ! $can_manage ) {
		echo \CB\Core\UI\Notice::render( [
			'variant' => \CB\Core\UI\Notice::INFO,
			'title'   => __( 'Policy is read-only for this account.', 'core-blueprint' ),
			'message' => __( 'Only a signed and approved CB Operator can change the site-wide two-factor policy.', 'core-blueprint' ),
		] );
	} elseif ( ! $base_enrolled && \CB\Core\Security\TwoFactor\Policy::MODE_ENFORCE !== $current_mode ) {
		$message = [] !== $providers
			? __( 'The acting CB Operator must have Base two-factor authentication enrolled before this site can switch to Enforce. Your account is currently owned by a supported external provider. Use another trusted Base-enrolled Operator, or move your own account to Base two-factor authentication first.', 'core-blueprint' )
			: __( 'The acting CB Operator must have Base two-factor authentication enrolled before this site can switch to Enforce. Configure Base two-factor authentication on your WordPress profile first.', 'core-blueprint' );
		echo \CB\Core\UI\Notice::render( [
			'variant' => \CB\Core\UI\Notice::WARNING,
			'title'   => __( 'Enroll Base two-factor authentication before enforcing.', 'core-blueprint' ),
			'message' => $message,
		] );
	}
	?>

	<section class="cb-core-panel">
		<h2><?php esc_html_e( 'Privileged account policy', 'core-blueprint' ); ?></h2>
		<p>
			<?php esc_html_e( 'Scope is fixed to privileged accounts for v1. Existing Base enrollments remain active in either policy mode.', 'core-blueprint' ); ?>
		</p>

		<?php if ( $can_manage ) : ?>
			<div data-cb-core-two-factor-policy-control>
				<?php
				echo \CB\Core\UI\RadioGroup::render( [
					'name'    => 'cb_core_two_factor_mode',
					'value'   => $current_mode,
					'options' => [
						[
							'value'      => \CB\Core\Security\TwoFactor\Policy::MODE_OPTIONAL,
							'label'      => __( 'Optional', 'core-blueprint' ),
							'desc'       => __( 'Privileged users may enroll in Base two-factor authentication, but unenrolled accounts are not forced into setup. Existing enrollments still require a second factor at login.', 'core-blueprint' ),
							'input_data' => [ 'data-cb-core-two-factor-mode' => '' ],
						],
						[
							'value'      => \CB\Core\Security\TwoFactor\Policy::MODE_ENFORCE,
							'label'      => __( 'Enforce', 'core-blueprint' ),
							'desc'       => __( 'Privileged accounts must establish a second factor. Users protected by a supported external provider stay with that provider; other privileged users are routed through Base enrollment.', 'core-blueprint' ),
							'input_data' => [ 'data-cb-core-two-factor-mode' => '' ],
						],
					],
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
		<?php else : ?>
			<p>
				<strong><?php echo esc_html( \CB\Core\Security\TwoFactor\Policy::MODE_ENFORCE === $current_mode ? __( 'Enforce', 'core-blueprint' ) : __( 'Optional', 'core-blueprint' ) ); ?></strong>
			</p>
		<?php endif; ?>
	</section>

	<section class="cb-core-panel">
		<h2><?php esc_html_e( 'Your authentication state', 'core-blueprint' ); ?></h2>
		<p>
			<?php
			echo $base_enrolled
				? esc_html__( 'Base two-factor authentication is active.', 'core-blueprint' )
				: esc_html__( 'Base two-factor authentication is not active for this account.', 'core-blueprint' );
			?>
		</p>
		<?php if ( [] !== $providers ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated external provider identifiers */
					esc_html__( 'Supported external provider state detected for your account: %s.', 'core-blueprint' ),
					esc_html( implode( ', ', $providers ) )
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $profile_url ); ?>">
				<?php esc_html_e( 'Manage my two-factor authentication', 'core-blueprint' ); ?>
			</a>
		</p>
	</section>
</div>
