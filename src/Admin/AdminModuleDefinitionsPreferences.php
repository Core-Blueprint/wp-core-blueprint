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
					'deps' => [ '@cb-core/dom', '@cb-core/modal', '@cb-core/design-editor' ],
					'data' => [
						'composer' => \CB\Core\Reports\Composer\MaintenanceTemplate::current(),
						'blockLabels' => [
							'header'        => __( 'Header', 'core-blueprint' ),
							'status'        => __( 'Status', 'core-blueprint' ),
							'kpis'          => __( 'Maintenance summary', 'core-blueprint' ),
							'current_state' => __( 'Current State', 'core-blueprint' ),
							'activity'      => __( 'Maintenance Details', 'core-blueprint' ),
							'summary'       => __( 'Summary', 'core-blueprint' ),
							'notes'         => __( 'Notes / Observations', 'core-blueprint' ),
							'footer'        => __( 'Footer', 'core-blueprint' ),
						],
						'composerUi' => [
							'blocks'   => __( 'Blocks', 'core-blueprint' ),
							'visible'  => __( 'Visible', 'core-blueprint' ),
							'hidden'   => __( 'Hidden', 'core-blueprint' ),
							'moveUp'   => __( 'Move up', 'core-blueprint' ),
							'moveDown' => __( 'Move down', 'core-blueprint' ),
						],
						'blockSettings' => [
							'header' => [
								'show_site_url' => __( 'Show site URL', 'core-blueprint' ),
								'show_metadata' => __( 'Show report metadata', 'core-blueprint' ),
							],
							'status' => [
								'show_details' => __( 'Show status details', 'core-blueprint' ),
							],
							'kpis' => [
								'show_details' => __( 'Show metric details', 'core-blueprint' ),
							],
							'current_state' => [
								'show_notes' => __( 'Show Notes column', 'core-blueprint' ),
							],
							'activity' => [
								'show_intro' => __( 'Show introduction', 'core-blueprint' ),
							],
							'summary' => [
								'show_security' => __( 'Show security summary', 'core-blueprint' ),
								'show_backups'  => __( 'Show backup summary', 'core-blueprint' ),
							],
							'notes' => [
								'show_heading' => __( 'Show section heading', 'core-blueprint' ),
							],
							'footer' => [
								'show_page_number' => __( 'Show page number in PDF', 'core-blueprint' ),
							],
						],
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
								'brandingConfirmReset'        => sprintf(
									'%1$s %2$s: %3$s.',
									__( 'Reset report settings to defaults? Logo, report provider details, appearance, and report layout will be restored.', 'core-blueprint' ),
									__( 'Blocks', 'core-blueprint' ),
									__( 'Reset to defaults', 'core-blueprint' )
								),
								'brandingConfirmResetTitle'   => __( 'Reset report settings?', 'core-blueprint' ),
								'brandingConfirmResetConfirm' => __( 'Reset to defaults', 'core-blueprint' ),
								'brandingResetting'           => __( 'Resetting…', 'core-blueprint' ),
								'brandingResetDone'           => __( 'Reset to defaults.', 'core-blueprint' ),
								'previewLoading'              => __( 'Loading…', 'core-blueprint' ),
								'previewFailed'               => __( 'An error occurred.', 'core-blueprint' ),
							]
						),
					],
				];
			},
			'@cb-core/admin-navigation' => static function (): array {
				return [
					'id'   => '@cb-core/admin-navigation',
					'src'  => 'features/admin-navigation.js',
					'deps' => [ '@cb-core/reorder' ],
				];
			},
			'@cb-core/admin-notices' => static function (): array {
				return [
					'id'   => '@cb-core/admin-notices',
					'src'  => 'features/admin-notices.js',
					'deps' => [],
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
