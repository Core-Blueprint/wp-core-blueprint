<?php
declare(strict_types=1);
/** Private BASE-10E.2 module definitions: Preferences. */

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminModuleDefinitionsPreferences {

	/** @return array<string,callable():array<string,mixed>> */
	public static function factories( string $admin_nonce, string $ajax_url, array $save_status ): array {
		return [
			'@cb-core/appearance' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/appearance',
					'src'  => 'features/appearance.js',
					'deps' => [ '@cb-core/dom', '@cb-core/public-api' ],
					'data' => [
						'i18n' => [
							'saved'      => $save_status['saved'],
							'saveFailed' => $save_status['saveFailed'],
						],
					],
				];
			},
			'@cb-core/language' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/language',
					'src'  => 'features/language.js',
					'deps' => [ '@cb-core/dom', '@cb-core/public-api' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => [
							'saved'      => $save_status['saved'],
							'saveFailed' => $save_status['saveFailed'],
						],
					],
				];
			},
			'@cb-core/notifications' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/notifications',
					'src'  => 'features/notifications.js',
					'deps' => [ '@cb-core/dom' ],
					'data' => [
						'nonce' => $admin_nonce,
					],
				];
			},
			'@cb-core/alert-recipients' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/alert-recipients',
					'src'  => 'features/alert-recipients.js',
					'deps' => [ '@cb-core/dom' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => [
							'lsSaving'            => $save_status['saving'],
							'lsSaved'             => $save_status['saved'],
							'recipientSaveFailed' => __( 'Could not save recipient - try again.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/privacy' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/privacy',
					'src'  => 'features/privacy.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal', '@cb-core/toast' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => [
							'saving'                => $save_status['saving'],
							'saved'                 => $save_status['saved'],
							'saveFailed'            => $save_status['saveFailed'],
							'confirmPreset'         => __( 'Apply this preset? All current settings will be overwritten.', 'core-blueprint' ),
							'confirmPresetTitle'    => __( 'Apply this preset?', 'core-blueprint' ),
							'confirmPresetConfirm'  => __( 'Apply preset', 'core-blueprint' ),
							'privacyPresetFailed'   => __( 'Could not apply preset.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/reports-preferences' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/reports-preferences',
					'src'  => 'features/reports-preferences.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal' ],
					'data' => [
						'i18n' => array_merge(
							$save_status,
							[
								'reportsNonceMissing'         => __( 'Nonce missing - reload the page.', 'core-blueprint' ),
								'reportsMasterToggleFailed'   => __( 'Could not update Reports - try again.', 'core-blueprint' ),
								'brandingNoLogo'              => __( 'No logo set', 'core-blueprint' ),
								'brandingSelectLogo'          => __( 'Select logo', 'core-blueprint' ),
								'brandingChangeLogo'          => __( 'Change logo', 'core-blueprint' ),
								'brandingPickerTitle'         => __( 'Select logo', 'core-blueprint' ),
								'brandingPickerButton'        => __( 'Use this image', 'core-blueprint' ),
								'brandingMediaUnavailable'    => __( 'Media Library not available - reload the page.', 'core-blueprint' ),
								'brandingInvalidHex'          => __( 'Hex colour must be in #RRGGBB form.', 'core-blueprint' ),
								'brandingConfirmReset'        => __( 'Reset report settings to defaults? Logo, report provider details, and accent colour will be cleared.', 'core-blueprint' ),
								'brandingConfirmResetTitle'   => __( 'Reset report settings?', 'core-blueprint' ),
								'brandingConfirmResetConfirm' => __( 'Reset to defaults', 'core-blueprint' ),
								'brandingResetting'           => __( 'Resetting…', 'core-blueprint' ),
								'brandingResetDone'           => __( 'Reset to defaults.', 'core-blueprint' ),
								'previewLoading'              => __( 'Rendering report preview…', 'core-blueprint' ),
								'previewFailed'               => __( 'The report preview could not be rendered.', 'core-blueprint' ),
							]
						),
					],
				];
			},
			'@cb-core/permissions' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/permissions',
					'src'  => 'features/permissions.js',
					'deps' => [ '@cb-core/dom' ],
					'data' => [
						'i18n' => [
							'saving'          => $save_status['saving'],
							'saved'           => $save_status['saved'],
							'saveFailedShort' => $save_status['saveFailedShort'],
							'networkError'    => $save_status['networkError'],
						],
					],
				];
			},
		];
	}
}
