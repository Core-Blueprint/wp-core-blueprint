<?php
declare(strict_types=1);
/** Private BASE-10E.2 module definitions: Security. */

namespace CB\Core\Admin;

use CB\Core\UI;

defined( 'ABSPATH' ) || exit;

final class AdminModuleDefinitionsSecurity {

	/** @return array<string,callable():array<string,mixed>> */
	public static function factories( string $admin_nonce, string $ajax_url, array $save_status ): array {
		return [
			'@cb-core/site-mode' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/site-mode',
					'src'  => 'features/site-mode.js',
					'deps' => [ '@cb-core/dom', '@cb-core/toast' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => [
							'saving'              => $save_status['saving'],
							'saved'               => $save_status['saved'],
							'saveFailed'          => $save_status['saveFailed'],
							'saveAccessMode'      => __( 'Save Access Mode', 'core-blueprint' ),
							'activatePublic'      => __( 'Activate Public Mode', 'core-blueprint' ),
							'activateComingSoon'  => __( 'Activate Coming Soon', 'core-blueprint' ),
							'activateMaintenance' => __( 'Activate Maintenance', 'core-blueprint' ),
							'activateAdminOnly'   => __( 'Activate Admin-Only', 'core-blueprint' ),
							'accessAdminOnly'     => __( 'Admin-Only Mode - site locked', 'core-blueprint' ),
							'accessComingSoon'    => __( 'Coming Soon - pre-launch page active', 'core-blueprint' ),
							'accessMaintenance'   => __( 'Maintenance - site temporarily unavailable', 'core-blueprint' ),
							'accessPublic'        => __( 'Public Mode - site live', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/core-shield' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/core-shield',
					'src'  => 'features/core-shield.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal', '@cb-core/toast', '@cb-core/busy', '@cb-core/icon' ],
					'data' => [
						'nonce'          => $admin_nonce,
						'privilegedMode' => \CB\Core\Permissions\PrivilegedAccessPolicy::enforcement_mode(),
						'i18n'           => [
							'networkError'                => $save_status['networkError'],
							'shieldApplyDefaultsTitle'    => __( 'Apply recommended defaults?', 'core-blueprint' ),
							'shieldApplyDefaultsBody'     => __( 'This overwrites your current module and feature toggle configuration with the recommended defaults for the current site mode.', 'core-blueprint' ),
							'shieldApplyDefaultsConfirm'  => __( 'Apply defaults', 'core-blueprint' ),
							'headerTestError'             => __( 'Header test failed:', 'core-blueprint' ),
							'headerScore'                 => __( '%1$d of %2$d security headers present - Grade %3$s', 'core-blueprint' ),
							'headerPresent'               => __( 'Present', 'core-blueprint' ),
							'headerMissing'               => __( 'Missing', 'core-blueprint' ),
							'headerGradeLabel'            => __( 'Grade', 'core-blueprint' ),
							'headerColumnHeader'          => __( 'Header', 'core-blueprint' ),
							'headerColumnValue'           => __( 'Value', 'core-blueprint' ),
							'testingHeaders'              => __( 'Running test…', 'core-blueprint' ),
							'privilegedApproveTitle'      => __( 'Approve privileged access?', 'core-blueprint' ),
							'privilegedApproveBody'       => __( 'This immediately restores the account’s administrator-level capabilities for its exact current privilege state. Approve only after verifying that the account is legitimate.', 'core-blueprint' ),
							'privilegedApproveConfirm'    => __( 'Approve access', 'core-blueprint' ),
							'privilegedApproveFailed'     => __( 'Could not approve privileged access.', 'core-blueprint' ),
							'privilegedModeSaveFailed'    => __( 'Could not update Privileged Access Protection.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/two-factor-policy' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/two-factor-policy',
					'src'  => 'features/two-factor-policy.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal', '@cb-core/toast' ],
					'data' => [
						'nonce'       => $admin_nonce,
						'currentMode' => \CB\Core\Security\TwoFactor\Policy::mode(),
						'i18n'        => [
							'networkError'       => $save_status['networkError'],
							'passwordPlaceholder'=> __( 'WordPress password', 'core-blueprint' ),
							'changeTitle'        => __( 'Change two-factor policy?', 'core-blueprint' ),
							'enforceBody'        => __( 'Enforce requires a second factor for privileged accounts unless a supported external provider already owns that user. The acting CB Operator must have Base two-factor authentication enrolled. Re-enter your WordPress password to continue.', 'core-blueprint' ),
							'optionalBody'       => __( 'Optional stops requiring new privileged accounts to enroll. Existing Base enrollments remain active and continue to protect those accounts. Re-enter your WordPress password to continue.', 'core-blueprint' ),
							'changeConfirm'      => __( 'Change policy', 'core-blueprint' ),
							'saveFailed'         => __( 'Could not update the two-factor policy.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/login-shield' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/login-shield',
					'src'  => 'features/login-shield.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => [
							'lsSaving'               => $save_status['saving'],
							'lsSaved'                => $save_status['saved'],
							'lsSavedReloading'       => __( 'Saved - reloading…', 'core-blueprint' ),
							'lsSaveFailed'           => __( 'Could not save Login Shield settings - try again.', 'core-blueprint' ),
							'lsTesting'              => __( 'Testing…', 'core-blueprint' ),
							'lsTestFailed'           => __( 'Test request failed.', 'core-blueprint' ),
							'lsConfirmStrict'        => __( "Strict mode blocks /wp-admin for guests - only your custom login URL works. If you forget the URL, you can only get back in via the Failsafe bypass (see Failsafe tab). Continue?", 'core-blueprint' ),
							'lsConfirmStrictTitle'   => __( 'Enable Strict mode?', 'core-blueprint' ),
							'lsConfirmStrictConfirm' => __( 'Enable Strict mode', 'core-blueprint' ),
							'lsSlugRequired'         => __( 'A custom login URL is required before Login Shield can be enabled.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/failsafe' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/failsafe',
					'src'  => 'features/failsafe.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal', '@cb-core/toast', '@cb-core/busy' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => [
							'networkError'                => $save_status['networkError'],
							'cancel'                      => __( 'Cancel', 'core-blueprint' ),
							'failsafePasswordPlaceholder' => __( 'WordPress password', 'core-blueprint' ),
							'failsafeRotateTitle'         => __( 'Rotate bypass token', 'core-blueprint' ),
							'failsafeRotateBody'          => __( 'This invalidates the current bypass URL immediately. The new URL will be shown only once on the redirected page.' . "\n\n" . 'Re-enter your WordPress password to continue.', 'core-blueprint' ),
							'failsafeRotateConfirm'       => __( 'Rotate token', 'core-blueprint' ),
							'failsafePanicTitle'          => __( 'Activate emergency bypass', 'core-blueprint' ),
							'failsafePanicBody'           => __( 'All restrictive security features will be deactivated until you explicitly resume enforcement.' . "\n\n" . 'Re-enter your WordPress password to confirm.', 'core-blueprint' ),
							'failsafePanicConfirm'        => __( 'Activate bypass', 'core-blueprint' ),
							'failsafeReasonTitle'         => __( 'Reason for activating', 'core-blueprint' ),
							'failsafeReasonBody'          => __( 'Optionally, log why you activated the emergency bypass. This will be visible in the audit log.', 'core-blueprint' ),
							'failsafeReasonConfirm'       => __( 'Activate', 'core-blueprint' ),
							'failsafeReasonPlaceholder'   => __( 'Reason (optional)', 'core-blueprint' ),
							'failsafeResumeTitle'         => __( 'Resume enforcement?', 'core-blueprint' ),
							'failsafeResumeBody'          => __( 'All restrictive security features will be re-enabled. Anyone currently using the bypass URL will lose access immediately.', 'core-blueprint' ),
							'failsafeResumeConfirm'       => __( 'Resume enforcement', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/logs-toggle' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/logs-toggle',
					'src'  => 'features/logs-toggle.js',
					'deps' => [ '@cb-core/mode-switcher' ],
					'data' => null,
				];
			},
			'@cb-core/log-exports' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/log-exports',
					'src'  => 'features/log-exports.js',
					'deps' => [],
					'data' => [
						'nonce'   => $admin_nonce,
						'ajaxUrl' => $ajax_url,
					],
				];
			},
			'@cb-core/reports' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/reports',
					'src'  => 'features/reports.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal', '@cb-core/toast' ],
					'data' => [
						'nonce' => $admin_nonce,
						'i18n'  => array_merge(
							$save_status,
							[
								'reportsSelectPeriod'     => __( 'Select a period.', 'core-blueprint' ),
								'reportsNonceMissing'     => __( 'Nonce missing - reload the page.', 'core-blueprint' ),
								'reportsGenerating'       => __( 'Generating…', 'core-blueprint' ),
								'reportsGenerateFailed'   => __( 'Generation failed - see audit log for details.', 'core-blueprint' ),
								'reportsGenerated'        => __( 'Report generated.', 'core-blueprint' ),
								'reportsViewOnOverview'   => __( 'View on Overview', 'core-blueprint' ),
								'reportsNoneYet'          => __( 'No reports generated yet on this site.', 'core-blueprint' ),
								'reportsDeleteOneTitle'   => __( 'Delete this report?', 'core-blueprint' ),
								'reportsDeleteOneBody'    => __( 'The stored report snapshot will be removed and this cannot be undone.', 'core-blueprint' ),
								'reportsDeleteOneConfirm' => __( 'Delete', 'core-blueprint' ),
								'reportsDeleteAllTitle'   => __( 'Delete all reports?', 'core-blueprint' ),
								'reportsDeleteAllBody'    => __( 'This permanently removes every stored Maintenance Report snapshot on this site. This action cannot be undone.', 'core-blueprint' ),
								'reportsDeleteAllConfirm' => __( 'Delete all reports', 'core-blueprint' ),
								'reportsDeleteAllHint'    => __( 'Type the phrase to confirm:', 'core-blueprint' ),
								'reportsDeleteAllDone'    => __( 'All reports deleted.', 'core-blueprint' ),
							]
						),
					],
				];
			},
			'@cb-core/description-toggle' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/description-toggle',
					'src'  => 'features/description-toggle.js',
					'deps' => [ '@cb-core/dom' ],
					'data' => [
						'descMode' => [
							'current' => class_exists( UI::class ) ? UI::current_mode() : 'plain',
						],
						'i18n' => [
							'labelTech'     => __( 'tech', 'core-blueprint' ),
							'labelPlain'    => __( 'plain', 'core-blueprint' ),
							'showTechnical' => __( 'Show technical description', 'core-blueprint' ),
							'showPlain'     => __( 'Show plain description', 'core-blueprint' ),
						],
					],
				];
			},
		];
	}
}
