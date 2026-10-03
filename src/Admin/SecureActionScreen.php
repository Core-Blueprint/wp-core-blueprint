<?php
declare(strict_types=1);
/**
 * Internal Base-owned shell for short-lived authenticated security actions.
 *
 * This is deliberately not a public Design Foundation primitive and does not
 * register a WordPress menu page. It keeps sensitive one-request material such
 * as setup secrets and recovery codes request-local while composing the
 * existing Core Blueprint Admin Theme and Design Foundation components.
 *
 * @internal
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin;

use CoreBlueprint\Core\Brand\CoreBlueprintLockup;
use CoreBlueprint\Core\UI\AdminTheme;
use CoreBlueprint\Core\UI\AdminThemeAdapters;
use CoreBlueprint\Core\UI\Card;
use CoreBlueprint\Core\UI\Status;

defined( 'ABSPATH' ) || exit;

final class SecureActionScreen {

	private const HOOK = 'cb-core-secure-action';

	/**
	 * Render a non-navigational wp-admin security surface and terminate.
	 *
	 * Recognised args:
	 * - title: page heading.
	 * - status_variant: Status semantic variant.
	 * - status_label: Status label.
	 * - body: already-escaped/component-owned HTML for the card body.
	 *
	 * @param array<string,mixed> $args Screen arguments.
	 */
	public static function render( array $args ): never {
		$title          = trim( (string) ( $args['title'] ?? '' ) );
		$status_variant = trim( (string) ( $args['status_variant'] ?? 'idle' ) );
		$status_label   = trim( (string) ( $args['status_label'] ?? '' ) );
		$body           = (string) ( $args['body'] ?? '' );

		if ( '' === $title ) {
			$title = __( 'Security action', 'core-blueprint' );
		}

		nocache_headers();

		$GLOBALS['hook_suffix'] = self::HOOK;
		$GLOBALS['title']       = $title;
		$GLOBALS['parent_file'] = CB_CORE_PARENT_MENU;

		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( self::HOOK );
		}

		// admin-post.php is an endpoint rather than an admin screen, so Base's
		// normal screen bootstrap intentionally skipped presentation setup.
		// Re-enter only the canonical theme/design layer needed by this surface.
		AdminTheme::init();
		AdminThemeAdapters::init();

		$context = ScreenContext::from_request( self::HOOK );
		foreach ( [
			'shell.tokens',
			'foundation.icons',
			'foundation.modal',
			'foundation.clipboard',
			'shell.layout',
			'component.cards',
			'component.notices',
			'component.status-indicators',
			'component.field',
			'shell.buttons',
			'shell.form-controls',
			'shell.theme-canvas',
		] as $asset_id ) {
			AdminAssetCatalog::enqueue( $asset_id, $context );
		}

		CoreBlueprintLockup::enqueue_assets();

		wp_enqueue_style(
			'cb-core-css-page-secure-action',
			CB_CORE_URL . 'assets/css/pages/secure-action.css',
			[
				'cb-core-css-tokens',
				'cb-core-css-layout',
				'cb-core-css-cards',
				'cb-core-css-status-indicators',
				'cb-core-css-field',
				'cb-core-css-buttons',
				'cb-core-css-form-controls',
			],
			CB_CORE_VERSION
		);

		require_once ABSPATH . 'wp-admin/admin-header.php';

		echo '<div class="wrap cb-core-wrap cb-core-wrap--narrow cb-core-secure-action">';
		echo '<header class="cb-core-secure-action__header">';
		echo CoreBlueprintLockup::html( 'cb-core-secure-action__brand' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper owns escaping.
		echo '<h1 class="cb-core-title">' . esc_html( $title ) . '</h1>';
		if ( '' !== $status_label ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
			echo Status::render( $status_variant, $status_label );
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</header>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Both internal callers construct body from static markup, escaped dynamic values, and audited UI components before entering Card's caller-owned raw body slot.
		echo Card::render( [
			'variant' => Card::VARIANT_SPACIOUS,
			'body'    => $body,
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';

		require_once ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}
}
