<?php
declare(strict_types=1);
/**
 * Opt-in UI Foundation assets for Core Blueprint extensions.
 *
 * Standalone WordPress admin pages should not load the full Core Admin Theme.
 * This helper exposes narrowly-scoped primitives that are safe to opt into
 * without pulling dark mode, cards, page layouts, or other CB presentation.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const TOAST_PRESENTATION_CORE = 'core';
	public const TOAST_PRESENTATION_WP_NATIVE = 'wp-native';
	public const MODAL_PRESENTATION_CORE = 'core';
	public const MODAL_PRESENTATION_WP_NATIVE = 'wp-native';
	public const TOKEN_INPUT_PRESENTATION_CORE = 'core';
	public const TOKEN_INPUT_PRESENTATION_WP_NATIVE = 'wp-native';
	public const CLIPBOARD_PRESENTATION_CORE = 'core';
	public const CLIPBOARD_PRESENTATION_WP_NATIVE = 'wp-native';
	public const TIME_PICKER_PRESENTATION_CORE = 'core';
	public const TIME_PICKER_PRESENTATION_WP_NATIVE = 'wp-native';
	public const CHOICE_GROUP_PRESENTATION_CORE = 'core';
	public const CHOICE_GROUP_PRESENTATION_WP_NATIVE = 'wp-native';
	public const ICON_PICKER_PRESENTATION_CORE = 'core';
	public const ICON_PICKER_PRESENTATION_WP_NATIVE = 'wp-native';
	public const CAPABILITY_PICKER_PRESENTATION_CORE = 'core';
	public const CAPABILITY_PICKER_PRESENTATION_WP_NATIVE = 'wp-native';
	public const OBJECT_PICKER_PRESENTATION_CORE = 'core';
	public const OBJECT_PICKER_PRESENTATION_WP_NATIVE = 'wp-native';
	public const SELECT_PICKER_PRESENTATION_CORE = 'core';
	public const SELECT_PICKER_PRESENTATION_WP_NATIVE = 'wp-native';
	public const REORDER_PRESENTATION_CORE = 'core';
	public const REORDER_PRESENTATION_WP_NATIVE = 'wp-native';

	private static bool $icon_data_filter_registered = false;
	private static bool $toast_data_filter_registered = false;
	private static bool $modal_data_filter_registered = false;
	private static bool $token_input_data_filter_registered = false;
	private static bool $clipboard_data_filter_registered = false;
	private static bool $time_picker_data_filter_registered = false;
	private static bool $icon_picker_data_filter_registered = false;
	private static bool $capability_picker_data_filter_registered = false;
	private static bool $object_picker_data_filter_registered = false;
	private static bool $select_picker_data_filter_registered = false;
	private static bool $reorder_data_filter_registered = false;
	private static string $modal_presentation = self::MODAL_PRESENTATION_WP_NATIVE;
	private static string $token_input_presentation = self::TOKEN_INPUT_PRESENTATION_WP_NATIVE;
	private static string $clipboard_presentation = self::CLIPBOARD_PRESENTATION_WP_NATIVE;
	private static string $time_picker_presentation = self::TIME_PICKER_PRESENTATION_WP_NATIVE;
	private static string $icon_picker_presentation = self::ICON_PICKER_PRESENTATION_WP_NATIVE;
	private static string $capability_picker_presentation = self::CAPABILITY_PICKER_PRESENTATION_WP_NATIVE;
	private static string $object_picker_presentation = self::OBJECT_PICKER_PRESENTATION_WP_NATIVE;
	private static string $select_picker_presentation = self::SELECT_PICKER_PRESENTATION_WP_NATIVE;


	/**
	 * Enqueue the shared Toast Foundation for an extension-owned admin screen.
	 *
	 * Standalone Core Blueprint plugins should normally use the `wp-native`
	 * presentation so the toast follows WordPress admin geometry instead of
	 * importing the Core Admin Theme. Screens that live underneath the Core
	 * Blueprint parent menu already receive the `core` presentation from Base
	 * and generally do not need to call this method themselves.
	 *
	 * The runtime API is always `window.cbCore.toast(...)`; presentation is an
	 * asset concern and is intentionally kept out of extension call sites.
	 *
	 * @param string $presentation `wp-native` (default) or `core`.
	 */
	public static function enqueue_toasts( string $presentation = self::TOAST_PRESENTATION_WP_NATIVE ): void {
		if ( ! in_array( $presentation, [ self::TOAST_PRESENTATION_WP_NATIVE, self::TOAST_PRESENTATION_CORE ], true ) ) {
			$presentation = self::TOAST_PRESENTATION_WP_NATIVE;
		}

		// Defensive boundary: if Base has already established the Core Admin
		// toast presentation on this request, a sibling must not downgrade it
		// to the standalone adapter by calling the default helper.
		if ( wp_style_is( 'cb-core-css-toasts', 'enqueued' ) ) {
			$presentation = self::TOAST_PRESENTATION_CORE;
		}

		if ( self::TOAST_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style(
					'cb-core-css-tokens',
					CB_CORE_URL . 'assets/css/tokens.css',
					[],
					CB_CORE_VERSION
				);
			}

			wp_enqueue_style(
				'cb-core-css-toasts',
				CB_CORE_URL . 'assets/css/components/toasts.css',
				[ 'cb-core-css-tokens' ],
				CB_CORE_VERSION
			);
		} else {
			wp_enqueue_style(
				'cb-core-css-toasts-native',
				CB_CORE_URL . 'assets/css/components/toasts-native.css',
				[],
				CB_CORE_VERSION
			);
		}

		wp_enqueue_script_module(
			'@cb-core/toast',
			CB_CORE_URL . 'assets/js/core/toast.js',
			[],
			CB_CORE_VERSION
		);

		if ( ! self::$toast_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/toast',
				static function ( array $existing ) use ( $presentation ): array {
					return array_merge( $existing, [
						'presentation' => $presentation,
						'i18n' => [
							'dismiss' => __( 'Dismiss notification', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$toast_data_filter_registered = true;
		}
	}


	/**
	 * Enqueue the shared Modal Foundation for an extension-owned admin screen.
	 *
	 * Standalone Core Blueprint plugins should normally use the `wp-native`
	 * presentation so the dialog follows WordPress admin geometry and native
	 * controls without importing Core Admin Theme tokens, dark mode, or layout.
	 * Screens underneath the Core Blueprint parent menu already receive the
	 * `core` presentation from Base and generally do not need this helper.
	 *
	 * The runtime API is always `window.cbCore.modal.show( options )` and the
	 * script-module dependency identifier is always `@cb-core/modal`.
	 *
	 * @param string $presentation `wp-native` (default) or `core`.
	 */
	public static function enqueue_modals( string $presentation = self::MODAL_PRESENTATION_WP_NATIVE ): void {
		if ( ! in_array( $presentation, [ self::MODAL_PRESENTATION_WP_NATIVE, self::MODAL_PRESENTATION_CORE ], true ) ) {
			$presentation = self::MODAL_PRESENTATION_WP_NATIVE;
		}

		// A Core Admin screen owns the stronger presentation boundary. A sibling
		// calling the default helper on that request must not downgrade it.
		if ( wp_style_is( 'cb-core-css-modals', 'enqueued' ) ) {
			$presentation = self::MODAL_PRESENTATION_CORE;
		}

		self::$modal_presentation = $presentation;
		self::enqueue_icons();

		if ( self::MODAL_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style(
					'cb-core-css-tokens',
					CB_CORE_URL . 'assets/css/tokens.css',
					[],
					CB_CORE_VERSION
				);
			}

			wp_enqueue_style(
				'cb-core-css-modals',
				CB_CORE_URL . 'assets/css/components/modals.css',
				[ 'cb-core-css-tokens' ],
				CB_CORE_VERSION
			);
			wp_enqueue_style(
				'cb-core-css-buttons',
				CB_CORE_URL . 'assets/css/components/buttons.css',
				[ 'cb-core-css-tokens' ],
				CB_CORE_VERSION
			);
			wp_enqueue_style(
				'cb-core-css-form-controls',
				CB_CORE_URL . 'assets/css/components/form-controls.css',
				[ 'cb-core-css-tokens' ],
				CB_CORE_VERSION
			);
		} else {
			wp_enqueue_style(
				'cb-core-css-modals-native',
				CB_CORE_URL . 'assets/css/components/modals-native.css',
				[ 'cb-core-css-icons' ],
				CB_CORE_VERSION
			);
		}

		wp_enqueue_script_module(
			'@cb-core/modal',
			CB_CORE_URL . 'assets/js/core/modal.js',
			[ '@cb-core/icon' ],
			CB_CORE_VERSION
		);

		if ( ! self::$modal_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/modal',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$modal_presentation,
						'i18n' => [
							'confirm'          => __( 'Confirm', 'core-blueprint' ),
							'cancel'           => __( 'Cancel', 'core-blueprint' ),
							'close'            => __( 'Close', 'core-blueprint' ),
							'typeToConfirm'    => __( 'Type to confirm:', 'core-blueprint' ),
							'textDoesNotMatch' => __( 'Text does not match.', 'core-blueprint' ),
							'input'            => __( 'Input', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$modal_data_filter_registered = true;
		}
	}


	/**
	 * Enqueue the shared Token Input Foundation for an extension-owned admin screen.
	 *
	 * Token Input progressively enhances one real native text input. The native
	 * input remains the submitted/serialized form value; the segmented editor is
	 * interaction and presentation only. Consumers own token meaning and business
	 * validation. Runtime API: `window.cbCore.tokenInput.create( input, options )`.
	 * Script-module dependency: `@cb-core/token-input`.
	 *
	 * With no explicit presentation, Base resolves the adapter from the actual
	 * admin screen: pages below the Core Blueprint parent menu use Core Admin;
	 * standalone WordPress admin pages use the WP-native adapter. Consumers may
	 * still force either supported presentation explicitly.
	 *
	 * @param string|null $presentation `wp-native`, `core`, or null for auto.
	 */
	public static function enqueue_token_inputs( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::TOKEN_INPUT_PRESENTATION_CORE
				: self::TOKEN_INPUT_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::TOKEN_INPUT_PRESENTATION_WP_NATIVE, self::TOKEN_INPUT_PRESENTATION_CORE ], true ) ) {
			$presentation = self::TOKEN_INPUT_PRESENTATION_WP_NATIVE;
		}

		self::$token_input_presentation = $presentation;

		if ( self::TOKEN_INPUT_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-buttons', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-buttons', CB_CORE_URL . 'assets/css/components/buttons.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			wp_enqueue_style( 'cb-core-css-token-inputs', CB_CORE_URL . 'assets/css/components/token-inputs.css', [ 'cb-core-css-tokens', 'cb-core-css-buttons' ], CB_CORE_VERSION );
		} else {
			wp_enqueue_style( 'cb-core-css-token-inputs-native', CB_CORE_URL . 'assets/css/components/token-inputs-native.css', [], CB_CORE_VERSION );
		}

		wp_enqueue_script_module( '@cb-core/token-input', CB_CORE_URL . 'assets/js/core/token-input.js', [], CB_CORE_VERSION );

		if ( ! self::$token_input_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/token-input',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$token_input_presentation,
						'i18n' => [
							'tokenInput'          => __( 'Token input', 'core-blueprint' ),
							'availableVariables'  => __( 'Available variables', 'core-blueprint' ),
							'insertVariable'      => __( 'Insert variable', 'core-blueprint' ),
							'removeVariable'      => __( 'Remove variable', 'core-blueprint' ),
							'variable'            => __( 'variable', 'core-blueprint' ),
							'unknownVariable'     => __( 'Unknown variable', 'core-blueprint' ),
							'unknownVariables'    => __( 'Unknown variables', 'core-blueprint' ),
							'textSegment'         => __( 'text', 'core-blueprint' ),
							'entireValueSelected' => __( 'Entire value selected', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$token_input_data_filter_registered = true;
		}
	}


	/**
	 * Whether the current wp-admin screen belongs to the Core Blueprint admin.
	 *
	 * This intentionally checks the actual screen identity rather than loaded
	 * token styles: standalone extensions may legitimately consume individual
	 * Core assets without becoming Core Admin presentation contexts.
	 */
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


	/**
	 * Enqueue the shared Clipboard Foundation for an extension-owned admin screen.
	 *
	 * Clipboard is behavior-first: consumers supply an existing button and an
	 * explicit text value/callback. Base owns clipboard access, fallback, shared
	 * icon state, and Toast feedback. It never infers business meaning or copies
	 * visible DOM text implicitly.
	 *
	 * @param string $presentation `wp-native` (default) or `core`.
	 */
	public static function enqueue_clipboard( string $presentation = self::CLIPBOARD_PRESENTATION_WP_NATIVE ): void {
		if ( ! in_array( $presentation, [ self::CLIPBOARD_PRESENTATION_WP_NATIVE, self::CLIPBOARD_PRESENTATION_CORE ], true ) ) {
			$presentation = self::CLIPBOARD_PRESENTATION_WP_NATIVE;
		}

		// Core Admin owns the stronger presentation boundary when its token layer
		// is already active. Standalone screens remain WordPress-native.
		if ( wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
			$presentation = self::CLIPBOARD_PRESENTATION_CORE;
		}

		self::$clipboard_presentation = $presentation;
		self::enqueue_icons();
		self::enqueue_toasts(
			self::CLIPBOARD_PRESENTATION_CORE === $presentation
				? self::TOAST_PRESENTATION_CORE
				: self::TOAST_PRESENTATION_WP_NATIVE
		);

		if ( self::CLIPBOARD_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			wp_enqueue_style( 'cb-core-css-buttons', CB_CORE_URL . 'assets/css/components/buttons.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
		}

		wp_enqueue_style(
			'cb-core-css-clipboard',
			CB_CORE_URL . 'assets/css/components/clipboard.css',
			self::CLIPBOARD_PRESENTATION_CORE === $presentation ? [ 'cb-core-css-buttons' ] : [ 'cb-core-css-icons' ],
			CB_CORE_VERSION
		);

		wp_enqueue_script_module(
			'@cb-core/clipboard',
			CB_CORE_URL . 'assets/js/core/clipboard.js',
			[ '@cb-core/toast', '@cb-core/icon' ],
			CB_CORE_VERSION
		);

		if ( ! self::$clipboard_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/clipboard',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$clipboard_presentation,
						'i18n' => [
							'copyLabel'  => __( 'Copy to clipboard', 'core-blueprint' ),
							'copied'     => __( 'Copied to clipboard.', 'core-blueprint' ),
							'copyFailed' => __( 'Could not copy to clipboard.', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$clipboard_data_filter_registered = true;
		}
	}

	/**
	 * Enqueue the shared TimePicker Foundation for an extension-owned admin screen.
	 *
	 * TimePicker progressively enhances a small wrapper around one real text input
	 * and a toggle button. The native input remains the only serialized form value;
	 * Base owns HH:MM validation/normalization and the convenience picker only.
	 * Runtime API: `window.cbCore.timePicker`.
	 * Script-module dependency: `@cb-core/time-picker`.
	 *
	 * With no explicit presentation, Base resolves the adapter from the actual
	 * admin screen: pages below the Core Blueprint parent menu use Core Admin;
	 * standalone WordPress admin pages use the WP-native adapter.
	 *
	 * @param string|null $presentation `wp-native`, `core`, or null for auto.
	 */
	public static function enqueue_time_picker( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::TIME_PICKER_PRESENTATION_CORE
				: self::TIME_PICKER_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::TIME_PICKER_PRESENTATION_WP_NATIVE, self::TIME_PICKER_PRESENTATION_CORE ], true ) ) {
			$presentation = self::TIME_PICKER_PRESENTATION_WP_NATIVE;
		}

		self::$time_picker_presentation = $presentation;
		self::enqueue_icons();

		if ( self::TIME_PICKER_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-buttons', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-buttons', CB_CORE_URL . 'assets/css/components/buttons.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-form-controls', CB_CORE_URL . 'assets/css/components/form-controls.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			wp_enqueue_style(
				'cb-core-css-time-picker',
				CB_CORE_URL . 'assets/css/components/time-picker.css',
				[ 'cb-core-css-tokens', 'cb-core-css-buttons', 'cb-core-css-form-controls', 'cb-core-css-icons' ],
				CB_CORE_VERSION
			);
		} else {
			wp_enqueue_style(
				'cb-core-css-time-picker-native',
				CB_CORE_URL . 'assets/css/components/time-picker-native.css',
				[ 'cb-core-css-icons' ],
				CB_CORE_VERSION
			);
		}

		wp_enqueue_script_module(
			'@cb-core/time-picker',
			CB_CORE_URL . 'assets/js/core/time-picker.js',
			[ '@cb-core/icon' ],
			CB_CORE_VERSION
		);

		if ( ! self::$time_picker_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/time-picker',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$time_picker_presentation,
						'i18n' => [
							'chooseTime'  => __( 'Choose time', 'core-blueprint' ),
							'hour'        => __( 'Hour', 'core-blueprint' ),
							'minute'      => __( 'Minute', 'core-blueprint' ),
							'done'        => __( 'Done', 'core-blueprint' ),
							'invalidTime' => __( 'Enter a valid time from 00:00 through 23:59.', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$time_picker_data_filter_registered = true;
		}
	}




	/**
	 * Enqueue the shared Reorder Foundation for an extension-owned admin screen.
	 *
	 * Reorder progressively enhances consumer-owned ordered-list markup. Base
	 * owns generic pointer/keyboard movement, focus, announcements and rollback
	 * presentation. Consumers own item meaning, authorization and persistence.
	 *
	 * Runtime API: `window.cbCore.reorder`.
	 * Script-module dependency: `@cb-core/reorder`.
	 *
	 * @param string|null $presentation `wp-native`, `core`, or null for auto.
	 */
	public static function enqueue_reorder( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::REORDER_PRESENTATION_CORE
				: self::REORDER_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::REORDER_PRESENTATION_WP_NATIVE, self::REORDER_PRESENTATION_CORE ], true ) ) {
			$presentation = self::REORDER_PRESENTATION_WP_NATIVE;
		}

		if ( self::REORDER_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			wp_enqueue_style(
				'cb-core-css-reorder',
				CB_CORE_URL . 'assets/css/components/reorder.css',
				[ 'cb-core-css-tokens' ],
				CB_CORE_VERSION
			);
		} else {
			wp_enqueue_style(
				'cb-core-css-reorder-native',
				CB_CORE_URL . 'assets/css/components/reorder-native.css',
				[],
				CB_CORE_VERSION
			);
		}

		wp_enqueue_script_module(
			'@cb-core/reorder',
			CB_CORE_URL . 'assets/js/core/reorder.js',
			[],
			CB_CORE_VERSION
		);

		if ( ! self::$reorder_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/reorder',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'i18n' => [
							'movedWithin' => __( '%1$s moved to position %2$d of %3$d.', 'core-blueprint' ),
							'movedAcross' => __( '%1$s moved to %2$s, position %3$d of %4$d.', 'core-blueprint' ),
							'rollback'    => __( 'Move could not be completed. The previous position was restored.', 'core-blueprint' ),
							'cancelled'   => __( 'Move cancelled.', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$reorder_data_filter_registered = true;
		}
	}

	/** @return array<int,array{value:string,label:string}> */
	private static function dashicon_choices(): array {
		$choices = [
			'admin-post', 'admin-page', 'admin-generic', 'admin-settings', 'admin-tools', 'admin-site', 'admin-home', 'admin-media', 'admin-links', 'admin-comments', 'admin-users', 'admin-network', 'admin-plugins', 'admin-appearance', 'admin-collapse', 'admin-customizer', 'admin-multisite', 'welcome-write-blog', 'welcome-view-site', 'welcome-learn-more', 'format-aside', 'format-image', 'format-gallery', 'format-video', 'format-audio', 'format-chat', 'portfolio', 'book', 'products', 'cart', 'money-alt', 'index-card', 'list-view', 'excerpt-view', 'grid-view', 'images-alt2', 'camera', 'video-alt3', 'megaphone', 'chart-bar', 'chart-line', 'analytics', 'performance', 'visibility', 'lock', 'shield', 'privacy', 'search', 'tag', 'category', 'star-filled', 'heart', 'yes-alt', 'dismiss', 'warning', 'clock', 'calendar-alt', 'location', 'email-alt', 'phone', 'desktop', 'laptop', 'tablet', 'smartphone', 'database', 'backup', 'cloud', 'download', 'upload', 'edit', 'edit-page', 'welcome-add-page', 'feedback', 'groups', 'businessperson', 'id', 'art', 'layout', 'menu', 'menu-alt', 'menu-alt2', 'paperclip', 'media-code', 'media-document', 'media-spreadsheet',
		];
		return array_map(
			static function ( string $slug ): array {
				return [
					'value' => 'dashicons-' . $slug,
					'label' => ucwords( str_replace( '-', ' ', $slug ) ),
				];
			},
			$choices
		);
	}

	/** @return array<int,array{value:string,label:string}> */
	private static function lucide_choices(): array {
		$choices = [];
		foreach ( array_keys( Icon::export_registry()['icons'] ) as $name ) {
			$choices[] = [
				'value' => 'lucide:' . $name,
				'label' => ucwords( str_replace( '-', ' ', (string) $name ) ),
			];
		}
		usort(
			$choices,
			static fn( array $a, array $b ): int => strcmp( (string) $a['label'], (string) $b['label'] )
		);
		return $choices;
	}

	/** @return array<int,array{value:string,label:string,keywords:string}> */
	private static function capability_choices(): array {
		$preferred = [
			'manage_options', 'edit_posts', 'edit_pages', 'publish_posts', 'publish_pages', 'upload_files', 'moderate_comments', 'manage_categories', 'list_users', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'switch_themes', 'edit_theme_options', 'update_core', 'export', 'import', 'read_private_posts', 'read_private_pages', 'manage_woocommerce', 'cb_manage_content_models', 'cb_manage_permissions', 'cb_manage_roles', 'cb_view_permissions', 'cb_use_cli', 'cb_core_hud_use',
		];
		$pool = array_fill_keys( $preferred, true );
		$roles = wp_roles();
		if ( $roles instanceof \WP_Roles ) {
			foreach ( $roles->roles as $role ) {
				foreach ( (array) ( $role['capabilities'] ?? [] ) as $capability => $granted ) {
					if ( ! empty( $granted ) && is_string( $capability ) && '' !== $capability ) {
						$pool[ $capability ] = true;
					}
				}
			}
		}
		$values = array_keys( $pool );
		natcasesort( $values );
		return array_map(
			static function ( string $capability ): array {
				return [
					'value' => $capability,
					'label' => ucwords( str_replace( '_', ' ', $capability ) ),
					'keywords' => str_replace( '_', ' ', $capability ),
				];
			},
			array_values( $values )
		);
	}

	/**
	 * Enqueue the shared Choice Group Foundation.
	 *
	 * Choice Group is a presentation primitive for grouped native checkbox or
	 * radio controls. Consumers keep ownership of names, values, persistence and
	 * validation. Core Admin receives the token-based presentation; standalone
	 * extension screens receive a WordPress-native adapter.
	 *
	 * @param string|null $presentation `wp-native`, `core`, or null for auto.
	 */
	public static function enqueue_choice_group( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::CHOICE_GROUP_PRESENTATION_CORE
				: self::CHOICE_GROUP_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::CHOICE_GROUP_PRESENTATION_WP_NATIVE, self::CHOICE_GROUP_PRESENTATION_CORE ], true ) ) {
			$presentation = self::CHOICE_GROUP_PRESENTATION_WP_NATIVE;
		}

		if ( self::CHOICE_GROUP_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-form-controls', CB_CORE_URL . 'assets/css/components/form-controls.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			wp_enqueue_style(
				'cb-core-css-choice-group',
				CB_CORE_URL . 'assets/css/components/choice-group.css',
				[ 'cb-core-css-tokens', 'cb-core-css-form-controls' ],
				CB_CORE_VERSION
			);
		} else {
			wp_enqueue_style(
				'cb-core-css-choice-group-native',
				CB_CORE_URL . 'assets/css/components/choice-group-native.css',
				[],
				CB_CORE_VERSION
			);
		}
	}


	/**
	 * Enqueue the shared Icon Picker Foundation.
	 *
	 * Progressive enhancement over one real text input. The text input remains
	 * the submitted value and acts as the no-JS fallback; JavaScript upgrades it
	 * into a searchable picker for curated Dashicons and Core Blueprint Lucide
	 * icons.
	 */
	public static function enqueue_icon_picker( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::ICON_PICKER_PRESENTATION_CORE
				: self::ICON_PICKER_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::ICON_PICKER_PRESENTATION_WP_NATIVE, self::ICON_PICKER_PRESENTATION_CORE ], true ) ) {
			$presentation = self::ICON_PICKER_PRESENTATION_WP_NATIVE;
		}

		self::$icon_picker_presentation = $presentation;
		self::enqueue_icons();
		wp_enqueue_style( 'dashicons' );

		if ( self::ICON_PICKER_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-buttons', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-buttons', CB_CORE_URL . 'assets/css/components/buttons.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-form-controls', CB_CORE_URL . 'assets/css/components/form-controls.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			wp_enqueue_style( 'cb-core-css-pickers', CB_CORE_URL . 'assets/css/components/pickers.css', [ 'cb-core-css-tokens', 'cb-core-css-buttons', 'cb-core-css-form-controls', 'cb-core-css-icons', 'dashicons' ], CB_CORE_VERSION );
		} else {
			wp_enqueue_style( 'cb-core-css-pickers-native', CB_CORE_URL . 'assets/css/components/pickers-native.css', [ 'cb-core-css-icons', 'dashicons' ], CB_CORE_VERSION );
		}

		wp_enqueue_script_module( '@cb-core/icon-picker', CB_CORE_URL . 'assets/js/core/icon-picker.js', [ '@cb-core/icon' ], CB_CORE_VERSION );

		if ( ! self::$icon_picker_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/icon-picker',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$icon_picker_presentation,
						'dashicons'    => self::dashicon_choices(),
						'lucide'       => self::lucide_choices(),
						'i18n'         => [
							'chooseIcon' => __( 'Choose icon', 'core-blueprint' ),
							'searchIcons' => __( 'Search icons…', 'core-blueprint' ),
							'dashicons'   => __( 'Dashicons', 'core-blueprint' ),
							'lucide'      => __( 'Lucide', 'core-blueprint' ),
							'noResults'   => __( 'No matching icons found.', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$icon_picker_data_filter_registered = true;
		}
	}

	/** Enqueue the shared Capability Picker Foundation. */
	public static function enqueue_capability_picker( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::CAPABILITY_PICKER_PRESENTATION_CORE
				: self::CAPABILITY_PICKER_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::CAPABILITY_PICKER_PRESENTATION_WP_NATIVE, self::CAPABILITY_PICKER_PRESENTATION_CORE ], true ) ) {
			$presentation = self::CAPABILITY_PICKER_PRESENTATION_WP_NATIVE;
		}

		self::$capability_picker_presentation = $presentation;
		self::enqueue_icons();

		if ( self::CAPABILITY_PICKER_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-buttons', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-buttons', CB_CORE_URL . 'assets/css/components/buttons.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-form-controls', CB_CORE_URL . 'assets/css/components/form-controls.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			wp_enqueue_style( 'cb-core-css-pickers', CB_CORE_URL . 'assets/css/components/pickers.css', [ 'cb-core-css-tokens', 'cb-core-css-buttons', 'cb-core-css-form-controls', 'cb-core-css-icons' ], CB_CORE_VERSION );
		} else {
			wp_enqueue_style( 'cb-core-css-pickers-native', CB_CORE_URL . 'assets/css/components/pickers-native.css', [ 'cb-core-css-icons' ], CB_CORE_VERSION );
		}

		wp_enqueue_script_module( '@cb-core/capability-picker', CB_CORE_URL . 'assets/js/core/capability-picker.js', [ '@cb-core/icon' ], CB_CORE_VERSION );

		if ( ! self::$capability_picker_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/capability-picker',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$capability_picker_presentation,
						'capabilities' => self::capability_choices(),
						'i18n'         => [
							'chooseCapability' => __( 'Choose capability', 'core-blueprint' ),
							'searchCapabilities' => __( 'Search capabilities…', 'core-blueprint' ),
							'noResults' => __( 'No matching capabilities found.', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$capability_picker_data_filter_registered = true;
		}
	}



	/**
	 * Enqueue the shared async Object Picker Foundation.
	 *
	 * Consumers provide their own AJAX action and authorization contract through
	 * ObjectPicker::render(). Foundation owns only selection/search presentation.
	 *
	 * @param string|null $presentation `wp-native`, `core`, or null for auto.
	 */
	public static function enqueue_object_picker( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::OBJECT_PICKER_PRESENTATION_CORE
				: self::OBJECT_PICKER_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::OBJECT_PICKER_PRESENTATION_WP_NATIVE, self::OBJECT_PICKER_PRESENTATION_CORE ], true ) ) {
			$presentation = self::OBJECT_PICKER_PRESENTATION_WP_NATIVE;
		}

		self::$object_picker_presentation = $presentation;
		if ( self::OBJECT_PICKER_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-form-controls', CB_CORE_URL . 'assets/css/components/form-controls.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			wp_enqueue_style(
				'cb-core-css-object-picker',
				CB_CORE_URL . 'assets/css/components/object-picker.css',
				[ 'cb-core-css-tokens', 'cb-core-css-form-controls' ],
				CB_CORE_VERSION
			);
		} else {
			wp_enqueue_style(
				'cb-core-css-object-picker-native',
				CB_CORE_URL . 'assets/css/components/object-picker-native.css',
				[],
				CB_CORE_VERSION
			);
		}

		wp_enqueue_script_module(
			'@cb-core/object-picker',
			CB_CORE_URL . 'assets/js/core/object-picker.js',
			[],
			CB_CORE_VERSION
		);

		if ( ! self::$object_picker_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/object-picker',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$object_picker_presentation,
						'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
						'i18n'         => [
							'noneSelected' => __( 'Nothing selected.', 'core-blueprint' ),
							'noResults'    => __( 'No matching items found.', 'core-blueprint' ),
							'searchError'  => __( 'Search failed.', 'core-blueprint' ),
							'remove'       => __( 'Remove', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$object_picker_data_filter_registered = true;
		}
	}


	/**
	 * Enqueue the shared Select Picker Foundation.
	 *
	 * Select Picker progressively enhances one real native select. The original
	 * select remains the submitted value and no-JS fallback; Foundation owns the
	 * searchable grouped presentation and mirrors selection back through native
	 * `change` events so consumer validation and business logic remain unchanged.
	 *
	 * @param string|null $presentation `wp-native`, `core`, or null for auto.
	 */
	public static function enqueue_select_picker( ?string $presentation = null ): void {
		if ( null === $presentation ) {
			$presentation = self::is_core_admin_screen()
				? self::SELECT_PICKER_PRESENTATION_CORE
				: self::SELECT_PICKER_PRESENTATION_WP_NATIVE;
		} elseif ( ! in_array( $presentation, [ self::SELECT_PICKER_PRESENTATION_WP_NATIVE, self::SELECT_PICKER_PRESENTATION_CORE ], true ) ) {
			$presentation = self::SELECT_PICKER_PRESENTATION_WP_NATIVE;
		}

		self::$select_picker_presentation = $presentation;

		if ( self::SELECT_PICKER_PRESENTATION_CORE === $presentation ) {
			if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
			}
			if ( ! wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) ) {
				wp_enqueue_style( 'cb-core-css-form-controls', CB_CORE_URL . 'assets/css/components/form-controls.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
			}
			wp_enqueue_style(
				'cb-core-css-pickers',
				CB_CORE_URL . 'assets/css/components/pickers.css',
				[ 'cb-core-css-tokens', 'cb-core-css-form-controls' ],
				CB_CORE_VERSION
			);
		} else {
			wp_enqueue_style(
				'cb-core-css-pickers-native',
				CB_CORE_URL . 'assets/css/components/pickers-native.css',
				[],
				CB_CORE_VERSION
			);
		}

		wp_enqueue_script_module(
			'@cb-core/select-picker',
			CB_CORE_URL . 'assets/js/core/select-picker.js',
			[],
			CB_CORE_VERSION
		);

		if ( ! self::$select_picker_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/select-picker',
				static function ( array $existing ): array {
					return array_merge( $existing, [
						'presentation' => self::$select_picker_presentation,
						'i18n' => [
							'searchOptions' => __( 'Search options…', 'core-blueprint' ),
							'noResults'     => __( 'No matching options found.', 'core-blueprint' ),
						],
					] );
				}
			);
			self::$select_picker_data_filter_registered = true;
		}
	}

	/** Enqueue only the shared Lucide icon primitive. */
	public static function enqueue_icons(): void {
		wp_enqueue_style(
			'cb-core-css-icons',
			CB_CORE_URL . 'assets/css/components/icons.css',
			[],
			CB_CORE_VERSION
		);

		wp_enqueue_script_module(
			'@cb-core/icon',
			CB_CORE_URL . 'assets/js/core/icon.js',
			[],
			CB_CORE_VERSION
		);

		if ( ! self::$icon_data_filter_registered ) {
			add_filter(
				'script_module_data_@cb-core/icon',
				static function ( array $existing ): array {
					return array_merge( $existing, Icon::export_registry() );
				}
			);
			self::$icon_data_filter_registered = true;
		}
	}
}
