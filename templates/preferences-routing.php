<?php
/**
 * Preferences > Routing & URLs.
 *
 * @var bool                     $routing_enabled
 * @var bool                     $routing_runtime_active
 * @var array<string,mixed>|null $routing_preflight
 * @var string                   $routing_state
 * @var string                   $routing_example
 * @var string                   $category_base
 */

defined( 'ABSPATH' ) || exit;

$default_root = '/' . trim( $category_base . '/' . $routing_example, '/' ) . '/';
$default_page = '/' . trim( $category_base . '/' . $routing_example, '/' ) . '/page/2/';
$clean_root   = '/' . trim( $routing_example, '/' ) . '/';
$clean_page   = '/' . trim( $routing_example, '/' ) . '/p2/';

$notice = null;
$states = [
	'preflight-ready'      => [ \CB\Core\UI\Notice::SUCCESS, __( 'Preflight passed. Review the findings below before enabling Clean Archive URLs.', 'core-blueprint' ) ],
	'preflight-blocked'    => [ \CB\Core\UI\Notice::ERROR, __( 'Clean Archive URLs cannot be enabled until the reported route collisions are resolved.', 'core-blueprint' ) ],
	'preflight-required'   => [ \CB\Core\UI\Notice::WARNING, __( 'Run a fresh routing preflight before enabling Clean Archive URLs.', 'core-blueprint' ) ],
	'preflight-stale'      => [ \CB\Core\UI\Notice::WARNING, __( 'The routing configuration changed after the preflight. Run the check again before enabling.', 'core-blueprint' ) ],
	'ack-required'         => [ \CB\Core\UI\Notice::WARNING, __( 'Confirm that you understand the public URL change before enabling Clean Archive URLs.', 'core-blueprint' ) ],
	'disable-ack-required' => [ \CB\Core\UI\Notice::WARNING, __( 'Confirm that disabling this policy changes canonical category URLs back to WordPress defaults.', 'core-blueprint' ) ],
	'enabled'              => [ \CB\Core\UI\Notice::SUCCESS, __( 'Clean Archive URLs are enabled. WordPress rewrite rules will be reconciled automatically.', 'core-blueprint' ) ],
	'disabled'             => [ \CB\Core\UI\Notice::SUCCESS, __( 'Clean Archive URLs are disabled. WordPress default category routing is active.', 'core-blueprint' ) ],
	'error'                => [ \CB\Core\UI\Notice::ERROR, __( 'The Routing & URLs policy could not be changed.', 'core-blueprint' ) ],
];
if ( isset( $states[ $routing_state ] ) ) {
	$notice = $states[ $routing_state ];
}
?>
<div class="wrap cb-core-wrap cb-core-routing-settings">
	<h1 class="cb-core-title"><?php esc_html_e( 'Routing & URLs', 'core-blueprint' ); ?></h1>
	<p class="cb-core-intro">
		<?php esc_html_e( 'Review how public category archives are routed and optionally use clean root-level archive URLs with compact pagination.', 'core-blueprint' ); ?>
	</p>

	<?php if ( is_array( $notice ) ) : ?>
		<?php
		echo \CB\Core\UI\Notice::render( [
			'variant' => (string) $notice[0],
			'message' => (string) $notice[1],
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component owns escaping.
		?>
	<?php endif; ?>

	<section class="cb-core-panel" aria-labelledby="cb-core-routing-policy-title">
		<h2 id="cb-core-routing-policy-title"><?php esc_html_e( 'Clean Archive URLs', 'core-blueprint' ); ?></h2>
		<p>
			<?php
			echo \CB\Core\UI\Status::render(
				$routing_enabled ? 'active' : 'idle',
				$routing_enabled ? __( 'Enabled', 'core-blueprint' ) : __( 'WordPress default', 'core-blueprint' )
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component owns escaping.
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'This policy removes the category base from public category archive URLs and uses p{n} for archive pagination. It is always opt-in.', 'core-blueprint' ); ?>
		</p>

		<?php if ( $routing_enabled && ! $routing_runtime_active ) : ?>
			<?php
			$routing_blockers = is_array( $routing_preflight['blockers'] ?? null )
				? $routing_preflight['blockers']
				: [];
			echo \CB\Core\UI\Notice::render( [
				'variant' => \CB\Core\UI\Notice::WARNING,
				'title'   => __( 'Blocking route collisions found', 'core-blueprint' ),
				'message' => __( 'Clean Archive URLs are temporarily suspended because route collisions were detected. WordPress default category routing remains active until the collisions are resolved.', 'core-blueprint' ),
				'items'   => $routing_blockers,
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component owns escaping.
			?>
		<?php endif; ?>

		<table class="widefat striped cb-core-kv-table">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'WordPress archive', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( $default_root ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'WordPress pagination', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( $default_page ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Core Blueprint archive', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( $clean_root ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Core Blueprint pagination', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( $clean_page ); ?></code></td></tr>
			</tbody>
		</table>
	</section>

	<?php if ( ! $routing_enabled ) : ?>
		<section class="cb-core-panel" aria-labelledby="cb-core-routing-preflight-title">
			<h2 id="cb-core-routing-preflight-title"><?php esc_html_e( 'Activation preflight', 'core-blueprint' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Core Blueprint checks known public content, post type, taxonomy and p{n} collisions before this policy can be enabled.', 'core-blueprint' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( \CB\Core\Routing\Admin::PREFLIGHT_ACTION ); ?>" />
				<?php wp_nonce_field( \CB\Core\Routing\Admin::PREFLIGHT_NONCE ); ?>
				<?php submit_button( __( 'Run routing preflight', 'core-blueprint' ), 'secondary cb-core-button cb-core-button--secondary', 'submit', false ); ?>
			</form>

			<?php if ( is_array( $routing_preflight ) ) : ?>
				<?php
				$blockers = is_array( $routing_preflight['blockers'] ?? null ) ? $routing_preflight['blockers'] : [];
				$warnings = is_array( $routing_preflight['warnings'] ?? null ) ? $routing_preflight['warnings'] : [];
				$ready    = ! empty( $routing_preflight['ready'] );
				echo \CB\Core\UI\Notice::render( [
					'variant' => $ready ? \CB\Core\UI\Notice::SUCCESS : \CB\Core\UI\Notice::ERROR,
					'title'   => $ready ? __( 'No known blocking collisions found', 'core-blueprint' ) : __( 'Blocking route collisions found', 'core-blueprint' ),
					'items'   => $blockers,
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component owns escaping.
				?>
				<?php if ( [] !== $warnings ) : ?>
					<?php
					echo \CB\Core\UI\Notice::render( [
						'variant' => \CB\Core\UI\Notice::WARNING,
						'title'   => __( 'Review before enabling', 'core-blueprint' ),
						'items'   => $warnings,
					] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component owns escaping.
					?>
				<?php endif; ?>

				<?php if ( $ready ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( \CB\Core\Routing\Admin::ENABLE_ACTION ); ?>" />
						<input type="hidden" name="preflight_fingerprint" value="<?php echo esc_attr( (string) ( $routing_preflight['fingerprint'] ?? '' ) ); ?>" />
						<?php wp_nonce_field( \CB\Core\Routing\Admin::ENABLE_NONCE ); ?>
						<?php
						\CB\Core\Admin\MutationAcknowledgement::render(
							\CB\Core\Routing\Admin::acknowledgement_field(),
							'cb-core-routing-enable-ack',
							__( 'I understand that this changes public category URLs.', 'core-blueprint' ),
							__( 'Existing WordPress category routes will redirect to the clean canonical routes while this policy is active.', 'core-blueprint' )
						);
						submit_button( __( 'Enable Clean Archive URLs', 'core-blueprint' ), 'primary cb-core-button cb-core-button--primary', 'submit', false );
						?>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	<?php else : ?>
		<section class="cb-core-panel" aria-labelledby="cb-core-routing-disable-title">
			<h2 id="cb-core-routing-disable-title"><?php esc_html_e( 'Disable policy', 'core-blueprint' ); ?></h2>
			<?php
			echo \CB\Core\UI\Notice::render( [
				'variant' => \CB\Core\UI\Notice::WARNING,
				'message' => __( 'Disabling this policy changes canonical category URLs back to the WordPress category base and default pagination format.', 'core-blueprint' ),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component owns escaping.
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( \CB\Core\Routing\Admin::DISABLE_ACTION ); ?>" />
				<?php wp_nonce_field( \CB\Core\Routing\Admin::DISABLE_NONCE ); ?>
				<?php
				\CB\Core\Admin\MutationAcknowledgement::render(
					\CB\Core\Routing\Admin::acknowledgement_field(),
					'cb-core-routing-disable-ack',
					__( 'I understand that disabling changes the public category URLs.', 'core-blueprint' ),
					__( 'Review external redirects and cached links before changing this policy on an established site.', 'core-blueprint' )
				);
				submit_button( __( 'Disable Clean Archive URLs', 'core-blueprint' ), 'secondary cb-core-button cb-core-button--secondary', 'submit', false );
				?>
			</form>
		</section>
	<?php endif; ?>
</div>
