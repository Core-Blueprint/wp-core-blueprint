<?php
declare(strict_types=1);
/** Private BASE-10E.2 module definitions: Core. */

namespace CoreBlueprint\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminModuleDefinitionsCore {

	/** @return array<string,callable():array<string,mixed>> */
	public static function factories( string $admin_nonce, string $ajax_url, array $save_status ): array {
		return [
			'@cb-core/dom' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/dom',
					'src'  => 'core/dom.js',
					'deps' => [],
					'data' => [
						'ajaxUrl' => $ajax_url,
						'i18n'    => [
							'copiedToClipboard' => __( 'Copied to clipboard', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/toast' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/toast',
					'src'  => 'core/toast.js',
					'deps' => [],
					'data' => [
						'presentation' => 'core',
						'i18n' => [
							'dismiss' => __( 'Dismiss notification', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/busy' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/busy',
					'src'  => 'core/busy.js',
					'deps' => [],
					'data' => null,
				];
			},
			'@cb-core/modal' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/modal',
					'src'  => 'core/modal.js',
					'deps' => [ '@cb-core/icon' ],
					'data' => [
						'presentation' => 'core',
						'i18n' => [
							'confirm'          => __( 'Confirm', 'core-blueprint' ),
							'cancel'           => __( 'Cancel', 'core-blueprint' ),
							'close'            => __( 'Close', 'core-blueprint' ),
							'typeToConfirm'    => __( 'Type to confirm:', 'core-blueprint' ),
							'textDoesNotMatch' => __( 'Text does not match.', 'core-blueprint' ),
							'input'            => __( 'Input', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/public-api' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/public-api',
					'src'  => 'core/public-api.js',
					'deps' => [ '@cb-core/dom' ],
					'data' => [
						'nonceTheme'  => wp_create_nonce( 'cb_core_theme' ),
						'nonceLocale' => wp_create_nonce( 'cb_core_locale' ),
					],
				];
			},
			'@cb-core/mode-switcher' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/mode-switcher',
					'src'  => 'core/mode-switcher.js',
					'deps' => [ '@cb-core/dom' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => [
							'modeChangeFailed' => __( 'Could not change reading mode.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/status-menu' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/status-menu',
					'src'  => 'core/status-menu.js',
					'deps' => [],
					'data' => null,
				];
			},
			'@cb-core/module-activation' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/module-activation',
					'src'  => 'features/module-activation.js',
					'deps' => [ '@cb-core/dom', '@cb-core/toast' ],
					'data' => [
						'nonce'      => $admin_nonce,
						'modules'    => class_exists( '\\CoreBlueprint\\Core\\Modules\\ActivationRegistry' )
							? \CoreBlueprint\Core\Modules\ActivationRegistry::slugs()
							: [],
						'extensions' => class_exists( '\\CoreBlueprint\\Core\\ExtensionLifecycle' )
							? \CoreBlueprint\Core\ExtensionLifecycle::ids()
							: [],
						'i18n'       => [
							'updateFailed'          => __( 'Could not update module - try again.', 'core-blueprint' ),
							'extensionUpdateFailed' => __( 'Could not update extension - try again.', 'core-blueprint' ),
						],
					],
				];
			},
		];
	}
}
