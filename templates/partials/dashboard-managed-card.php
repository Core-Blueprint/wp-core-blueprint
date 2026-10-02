<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Dashboard managed card: navigation body + top-right Status Menu Foundation.
 *
 * Expects $card with title/meta/url/status_menu and optional state.
 */
defined( 'ABSPATH' ) || exit;

$card_url   = (string) ( $card['url'] ?? '' );
$card_state = sanitize_key( (string) ( $card['state'] ?? '' ) );
?>
<div class="cb-core-tile cb-core-tile--managed"<?php echo '' !== $card_state ? ' data-state="' . esc_attr( $card_state ) . '"' : ''; ?>>
	<?php if ( '' !== $card_url ) : ?>
		<a class="cb-core-tile__bodylink" href="<?php echo esc_url( $card_url ); ?>">
			<span class="cb-core-tile__title"><?php echo esc_html( (string) ( $card['title'] ?? '' ) ); ?></span>
			<span class="cb-core-tile__meta"><?php echo esc_html( (string) ( $card['meta'] ?? '' ) ); ?></span>
		</a>
	<?php else : ?>
		<div class="cb-core-tile__bodylink cb-core-tile__bodylink--static">
			<span class="cb-core-tile__title"><?php echo esc_html( (string) ( $card['title'] ?? '' ) ); ?></span>
			<span class="cb-core-tile__meta"><?php echo esc_html( (string) ( $card['meta'] ?? '' ) ); ?></span>
		</div>
	<?php endif; ?>
	<?php echo (string) ( $card['status_menu'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusMenu renderer returns escape-clean HTML. ?>
</div>
