<?php
declare(strict_types=1);
/**
 * Shared form-composition presentation boundary.
 *
 * Core Admin already receives Stack through the minimal shell and may opt into
 * Field through PageRegistry. Standalone WordPress admin screens can use this
 * helper to reuse the same semantic markup while keeping WordPress-native
 * controls, colours, focus behaviour and admin chrome entirely owned by
 * WordPress. Base owns only reusable form structure and spacing.
 *
 * @package Core_Blueprint
 * @since   1.0.0-rc3.36
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class FormComposition {

	public const PRESENTATION_CORE = 'core';
	public const PRESENTATION_WP_NATIVE = 'wp-native';

	/**
	 * Enqueue shared Field + Stack composition styling.
	 *
	 * With no explicit presentation, Base resolves the adapter from the actual
	 * admin screen: pages below the Core Blueprint parent menu use Core Admin;
	 * standalone WordPress admin pages use the WP-native structural adapter.
	 * Consumers may still force either supported presentation explicitly.
	 *
	 * The WP-native adapter never restyles native WordPress form controls. It
	 * provides only field grouping, spacing and structural states. Consumers are
	 * responsible for using canonical WordPress admin markup, explicit HTML input
	 * types and WordPress classes such as `regular-text`, `small-text`, `widefat`
	 * and native `.button` variants where appropriate.
	 *
	 * Core Admin pages should normally prefer PageRegistry's semantic `fields`
	 * requirement; Stack is already part of the minimal shell.
	 *
	 * @param string|null $presentation `wp-native`, `core`, or null for auto.
	 */
	public static function enqueue( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::PRESENTATION_CORE
				: self::PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::PRESENTATION_WP_NATIVE, self::PRESENTATION_CORE ], true ) ) {
			$presentation = self::PRESENTATION_WP_NATIVE;
		}

		if ( self::PRESENTATION_WP_NATIVE === $presentation ) {
			wp_enqueue_style(
				'cb-core-css-form-composition-native',
				CB_CORE_URL . 'assets/css/components/form-composition-native.css',
				[],
				CB_CORE_VERSION
			);
			return;
		}

		if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
			wp_enqueue_style(
				'cb-core-css-tokens',
				CB_CORE_URL . 'assets/css/tokens.css',
				[],
				CB_CORE_VERSION
			);
		}
		if ( ! wp_style_is( 'cb-core-css-layout', 'enqueued' ) ) {
			wp_enqueue_style(
				'cb-core-css-layout',
				CB_CORE_URL . 'assets/css/layout.css',
				[ 'cb-core-css-tokens' ],
				CB_CORE_VERSION
			);
		}
		if ( ! wp_style_is( 'cb-core-css-field', 'enqueued' ) ) {
			wp_enqueue_style(
				'cb-core-css-field',
				CB_CORE_URL . 'assets/css/components/field.css',
				[ 'cb-core-css-tokens' ],
				CB_CORE_VERSION
			);
		}
	}

	/** Whether the current wp-admin screen belongs to Core Admin. */
	private static function is_core_admin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		$screen_id = (string) $screen->id;
		return 'toplevel_page_' . CB_CORE_PARENT_MENU === $screen_id
			|| 0 === strpos( $screen_id, CB_CORE_PARENT_MENU . '_page_' );
	}
}
