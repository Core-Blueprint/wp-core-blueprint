<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Safeguards - Environment Governance.
 *
 * Variables:
 * - $environment_type string WordPress canonical environment type.
 * - $policy array{protect_search_indexing:bool} Portable governance policy.
 * - $save_state string Presentation-only result from the admin-post redirect.
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$protect_search_indexing = ! empty( $policy[ \CoreBlueprint\Core\Environment\Governance::PROTECT_SEARCH_INDEXING ] );
$is_non_production       = 'production' !== $environment_type;
?>
<div class="wrap cb-core-wrap cb-core-environment-governance">
	<h1 class="cb-core-title"><?php esc_html_e( 'Environment', 'core-blueprint' ); ?></h1>
	<p class="cb-core-intro">
		<?php esc_html_e( 'WordPress determines this site’s environment. Core Blueprint only applies a portable governance policy for non-production search indexing.', 'core-blueprint' ); ?>
	</p>

	<?php
	if ( 'success' === $save_state ) {
		echo \CoreBlueprint\Core\UI\Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'variant' => \CoreBlueprint\Core\UI\Notice::SUCCESS,
			'title'   => __( 'Environment Governance saved.', 'core-blueprint' ),
		] );
	} elseif ( 'error' === $save_state ) {
		echo \CoreBlueprint\Core\UI\Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'variant' => \CoreBlueprint\Core\UI\Notice::ERROR,
			'title'   => __( 'Environment Governance could not be saved.', 'core-blueprint' ),
		] );
	}
	?>

	<section class="cb-core-panel" aria-labelledby="cb-core-wordpress-environment-title">
		<h2 id="cb-core-wordpress-environment-title"><?php esc_html_e( 'WordPress Environment', 'core-blueprint' ); ?></h2>
		<p>
			<?php
			echo \CoreBlueprint\Core\UI\Status::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$is_non_production ? 'ready' : 'active',
				\CoreBlueprint\Core\Environment\Admin::environment_label( $environment_type )
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Read-only. This value comes directly from wp_get_environment_type(). Core Blueprint does not detect, infer, or store the environment identity.', 'core-blueprint' ); ?>
		</p>
	</section>

	<section class="cb-core-panel" aria-labelledby="cb-core-environment-governance-title">
		<h2 id="cb-core-environment-governance-title"><?php esc_html_e( 'Environment Governance', 'core-blueprint' ); ?></h2>

		<?php
		echo \CoreBlueprint\Core\UI\Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
			'items'   => [
				__( 'Environment and Access Mode are separate. A staging environment can still use Public Access Mode.', 'core-blueprint' ),
				__( 'Noindex asks search engines not to index a response. It is not access protection.', 'core-blueprint' ),
				__( 'On production this policy is stored for portability but has no search-indexing runtime effect.', 'core-blueprint' ),
			],
		] );
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( \CoreBlueprint\Core\Environment\Admin::SAVE_ACTION ); ?>" />
			<?php wp_nonce_field( \CoreBlueprint\Core\Environment\Admin::NONCE_ACTION ); ?>

			<div class="cb-core-field">
				<label for="cb-core-protect-search-indexing">
					<input
						id="cb-core-protect-search-indexing"
						type="checkbox"
						name="<?php echo esc_attr( \CoreBlueprint\Core\Environment\Governance::PROTECT_SEARCH_INDEXING ); ?>"
						value="1"
						<?php checked( $protect_search_indexing ); ?>
					/>
					<strong><?php esc_html_e( 'Protect search indexing on non-production environments', 'core-blueprint' ); ?></strong>
				</label>
				<p class="description">
					<?php esc_html_e( 'When enabled, local, development, and staging responses receive noindex through WordPress robots APIs while existing directives are preserved.', 'core-blueprint' ); ?>
				</p>
			</div>

			<?php
			submit_button(
				__( 'Save Environment Governance', 'core-blueprint' ),
				'primary cb-core-button cb-core-button--primary',
				'submit',
				false
			);
			?>
		</form>
	</section>
</div>
