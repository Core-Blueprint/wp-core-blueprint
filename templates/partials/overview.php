<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Partial: Overview-tab body.
 *
 * Rendered via {@see \CB\Core\Admin\Overview::render()}. Not intended to
 * be included directly - always go through the Overview class so the
 * $config array is defaulted correctly.
 *
 * Variables in scope (all from Overview::render() after defaults are merged):
 *   $config array - full Overview config. See Overview class docblock.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap cb-core-wrap cb-core-overview">

	<h1 class="cb-core-title"><?php echo esc_html( $config['title'] ); ?></h1>

	<?php if ( '' !== $config['intro'] ) : ?>
		<p class="cb-core-intro"><?php echo esc_html( $config['intro'] ); ?></p>
	<?php endif; ?>

	<?php
	// Banner is the one slot where the caller controls escaping - used for
	// things like the Failsafe bypass banner which already ships formatted
	// HTML including dynamic layer listings. Callers are responsible.
	if ( '' !== $config['banner'] ) {
		echo $config['banner']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>

	<?php if ( ! empty( $config['status_cards'] ) ) : ?>
		<div class="cb-core-status-strip">
			<?php foreach ( $config['status_cards'] as $card ) :
				$state_class = '';
				if ( ! empty( $card['state'] ) ) {
					$state_class = ' is-' . sanitize_html_class( $card['state'] );
				}
			?>
				<div class="cb-core-status-card<?php echo esc_attr( $state_class ); ?>">
					<?php if ( ! empty( $card['label'] ) ) : ?>
						<span class="label"><?php echo esc_html( $card['label'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $card['value'] ) ) : ?>
						<span class="value"><?php echo esc_html( $card['value'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $card['detail'] ) ) : ?>
						<span class="detail">
							<?php
							// 'detail' may contain a link - caller must escape.
							// When it's a plain string, wrap in esc_html via the
							// presence of a '<' sniff: if it looks like markup
							// we echo it as-is; otherwise we escape.
							$detail = (string) $card['detail'];
							if ( false !== strpos( $detail, '<' ) ) {
								echo $detail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								echo esc_html( $detail );
							}
							?>
						</span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $config['tab_cards'] ) ) : ?>
		<div class="cb-core-tab-cards">
			<?php foreach ( $config['tab_cards'] as $card ) :
				if ( empty( $card['url'] ) || empty( $card['label'] ) ) {
					continue;
				}
				$icon = ! empty( $card['icon'] ) ? sanitize_key( (string) $card['icon'] ) : 'admin-generic';
			?>
				<a class="cb-core-tab-card" href="<?php echo esc_url( $card['url'] ); ?>">
					<span class="cb-core-tab-card__icon" aria-hidden="true"><?php echo \CB\Core\UI\Icon::render( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() is escape-clean. ?></span>
					<span class="cb-core-tab-card__body">
						<span class="cb-core-tab-card__label"><?php echo esc_html( $card['label'] ); ?></span>
						<?php if ( ! empty( $card['desc'] ) ) : ?>
							<span class="cb-core-tab-card__desc"><?php echo esc_html( $card['desc'] ); ?></span>
						<?php endif; ?>
					</span>
					<span class="cb-core-tab-card__arrow" aria-hidden="true"><?php echo \CB\Core\UI\Icon::render( 'chevron-right', [ 'size' => \CB\Core\UI\Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() is escape-clean. ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $config['quick_actions'] ) ) : ?>
		<section class="cb-core-overview-quick-actions" aria-labelledby="cb-core-overview-quick-actions-title">
			<h2 id="cb-core-overview-quick-actions-title"><?php esc_html_e( 'Quick actions', 'core-blueprint' ); ?></h2>
			<div class="cb-core-overview-quick-actions__buttons">
				<?php foreach ( $config['quick_actions'] as $action ) :
					if ( empty( $action['url'] ) || empty( $action['label'] ) ) {
						continue;
					}
					$class = ! empty( $action['primary'] ) ? 'cb-core-button--primary' : 'cb-core-button--secondary';
				?>
					<a href="<?php echo esc_url( $action['url'] ); ?>" class="button cb-core-button <?php echo esc_attr( $class ); ?>">
						<?php echo esc_html( $action['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

</div>
