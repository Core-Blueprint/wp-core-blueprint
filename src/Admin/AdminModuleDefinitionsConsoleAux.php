<?php
declare(strict_types=1);
/** Private BASE-10E.2 module definitions: ConsoleAux. */

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminModuleDefinitionsConsoleAux {

	/** @return array<string,callable():array<string,mixed>> */
	public static function factories( string $admin_nonce, string $ajax_url, array $save_status ): array {
		return [
			'@cb-core/console' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/console',
					'src'  => 'features/console.js',
					'deps' => [],
					'data' => [
						'restRoot' => esc_url_raw( rest_url( 'core-blueprint/v1/console/' ) ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
						'i18n'     => [
							'filterCommands'     => __( 'Filter commands…', 'core-blueprint' ),
							'selectCommand'      => __( 'Select a command', 'core-blueprint' ),
							'selectCommandHelp'  => __( 'Pick a command from the list to view its arguments.', 'core-blueprint' ),
							'noCommands'         => __( 'No commands match your filter.', 'core-blueprint' ),
							'runCommand'         => __( 'Run command', 'core-blueprint' ),
							'running'            => __( 'Running…', 'core-blueprint' ),
							'capabilityDenied'   => __( 'You do not have permission to use the Console.', 'core-blueprint' ),
							'destructiveLabel'   => __( 'Destructive', 'core-blueprint' ),
							'stateChangeLabel'   => __( 'State-change', 'core-blueprint' ),
							'readOnlyLabel'      => __( 'Read-only', 'core-blueprint' ),
							'sideEffectsNote'    => __( 'This command modifies site state.', 'core-blueprint' ),
							'destructiveBannerNote' => __( 'Destructive action - confirmation required before running.', 'core-blueprint' ),
							'destructiveNote'    => __( 'This command is irreversible.', 'core-blueprint' ),
							'required'           => __( 'required', 'core-blueprint' ),
							'fieldRequired'      => __( 'This field is required.', 'core-blueprint' ),
							'durationMs'         => __( '%d ms', 'core-blueprint' ),
							'noOutput'           => __( '(no output)', 'core-blueprint' ),
							'noOutputYet'        => __( 'Output will appear here after you run a command.', 'core-blueprint' ),
							'errorPrefix'        => __( 'Error', 'core-blueprint' ),
							'warningPrefix'      => __( 'Warning', 'core-blueprint' ),
							'transportError'     => __( 'Network or server error: %s', 'core-blueprint' ),
							'groupObserve'       => __( 'Read-only', 'core-blueprint' ),
							'groupMutate'        => __( 'State-change', 'core-blueprint' ),
							'groupDestructive'   => __( 'Destructive', 'core-blueprint' ),
							'groupOther'         => __( 'Other', 'core-blueprint' ),
							'pickerLoading'      => __( 'Loading commands…', 'core-blueprint' ),
							'argsHeading'        => __( 'Arguments', 'core-blueprint' ),
							'noArgs'             => __( 'This command takes no arguments.', 'core-blueprint' ),
							'lastRunLabel'       => __( 'Last run', 'core-blueprint' ),
							'showData'           => __( 'Show structured data', 'core-blueprint' ),
							'hideData'           => __( 'Hide structured data', 'core-blueprint' ),
							'confirmDestructiveTitle' => __( 'Destructive action', 'core-blueprint' ),
							'irreversibleNote'   => __( 'This action is irreversible.', 'core-blueprint' ),
							'cancel'             => __( 'Cancel', 'core-blueprint' ),
							'confirmRun'         => __( 'Confirm and run', 'core-blueprint' ),
							'actFailsafeDisable1'       => __( 'Activates the emergency bypass.', 'core-blueprint' ),
							'actFailsafeDisable2'       => __( 'All restrictive Core Blueprint features are disabled site-wide.', 'core-blueprint' ),
							'actFailsafeDisable3'       => __( 'Site is exposed to threats CB was configured to block until you re-enable.', 'core-blueprint' ),
							'actFailsafeRotateToken1'   => __( 'Generates a new secret bypass URL token.', 'core-blueprint' ),
							'actFailsafeRotateToken2'   => __( 'The new URL is shown ONCE in a separate dialog and cannot be recovered if not saved.', 'core-blueprint' ),
							'actFailsafeRotateToken3'   => __( 'Any previously-saved bypass URL stops working immediately.', 'core-blueprint' ),
							'actOperatorRemove1'        => __( 'Demotes the user from the cb_operator role.', 'core-blueprint' ),
							'actOperatorRemove2'        => __( 'They lose access to operator-only Core Blueprint surfaces.', 'core-blueprint' ),
							'actOperatorRemove3'        => __( 'If --force is set, this proceeds even when they would be the last operator (lockout risk).', 'core-blueprint' ),
							'actGenericIrreversible'    => __( 'This command is destructive and cannot be undone.', 'core-blueprint' ),
							'secretTokenTitle'   => __( 'Secret bypass URL - shown once', 'core-blueprint' ),
							'secretTokenWarning' => __( 'Save this URL now. It will not be shown again. If you close this dialog without saving, run rotate-token again to generate a new one.', 'core-blueprint' ),
							'secretTokenInfo1'   => __( 'Using this URL will:', 'core-blueprint' ),
							'secretTokenAction1' => __( 'Disable restrictive features for 60 minutes.', 'core-blueprint' ),
							'secretTokenAction2' => __( 'Rotate the token (single-use).', 'core-blueprint' ),
							'secretTokenAction3' => __( 'Send an email notification to %s.', 'core-blueprint' ),
							'copyToClipboard'    => __( 'Copy URL', 'core-blueprint' ),
							'copied'             => __( 'Copied!', 'core-blueprint' ),
							'copiedFallback'     => __( 'Copied (fallback)', 'core-blueprint' ),
							'iSavedIt'           => __( 'I saved it - close', 'core-blueprint' ),
							'sensitiveOutputNote' => __( 'Sensitive output rendered in a separate dialog and not stored here.', 'core-blueprint' ),
							'userSearchPlaceholder' => __( 'Search by login, email, or name…', 'core-blueprint' ),
							'searching'             => __( 'Searching…', 'core-blueprint' ),
							'noUsersFound'          => __( 'No users found.', 'core-blueprint' ),
							'searchFailed'          => __( 'Search failed: %s', 'core-blueprint' ),
							'runningTitle'         => __( 'Running…', 'core-blueprint' ),
							'asyncScheduled'       => __( 'Async job scheduled.', 'core-blueprint' ),
							'asyncPending'         => __( 'Waiting for cron to fire…', 'core-blueprint' ),
							'asyncRunning'         => __( 'live polling', 'core-blueprint' ),
							'elapsedSeconds'       => __( 'Elapsed: %ds', 'core-blueprint' ),
							'stopProgress'         => __( 'Stop showing progress', 'core-blueprint' ),
							'asyncStopped'         => __( 'Stopped tracking - the scan continues in the background. Refresh the page to resume tracking, or check Logs once it completes.', 'core-blueprint' ),
							'asyncDoneNoResult'    => __( 'Scan complete. Run `cb scan latest` to see the result.', 'core-blueprint' ),
							'asyncFailedGeneric'   => __( 'The scan failed.', 'core-blueprint' ),
							'asyncGone'            => __( 'Progress state expired. The scan may have completed - check Logs and run `cb scan latest`.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/hud' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/hud',
					'src'  => 'features/hud.js',
					'deps' => [],
					'data' => class_exists( \CB\Core\HUD\Assets::class )
						? ( \CB\Core\HUD\Assets::script_module_data( [] ) )
						: [],
				];
			},
			'@cb-core/preferences-cli' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/preferences-cli',
					'src'  => 'features/preferences-cli.js',
					'deps' => [ '@cb-core/clipboard' ],
					'data' => [
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'i18n' => [ 'copyCommand' => __( 'Copy command', 'core-blueprint' ) ],
					],
				];
			},
			'@cb-core/preferences-floating-menu' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/preferences-floating-menu',
					'src'  => 'features/preferences-floating-menu.js',
					'deps' => [],
					'data' => [
						'i18n' => [
							'labelRequired' => __( 'Enter a label for the custom link.', 'core-blueprint' ),
							'urlInvalid' => __( 'Enter a valid http or https URL.', 'core-blueprint' ),
							'sectionInvalid' => __( 'Choose an available menu section.', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/mail-settings' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [ 'id' => '@cb-core/mail-settings', 'src' => 'features/mail-settings.js', 'deps' => [], 'data' => null ];
			},
			'@cb-core/mail-test' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [ 'id' => '@cb-core/mail-test', 'src' => 'features/mail-test.js', 'deps' => [ '@cb-core/toast' ], 'data' => null ];
			},
			'@cb-core/mail-log' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [ 'id' => '@cb-core/mail-log', 'src' => 'features/mail-log.js', 'deps' => [ '@cb-core/modal' ], 'data' => null ];
			},
			'@cb-core/media-formats' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [ 'id' => '@cb-core/media-formats', 'src' => 'features/media-formats.js', 'deps' => [], 'data' => null ];
			},
			'@cb-core/content-models' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/content-models',
					'src'  => 'features/content-models.js',
					'deps' => [ '@cb-core/modal', '@cb-core/select-picker', '@cb-core/reorder' ],
					'data' => [
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'i18n' => [
							'typeChangeTitle' => __( 'Change field type?', 'core-blueprint' ),
							'typeChangeBody' => __( 'Changing a field type can change how existing WordPress metadata is interpreted. Core Blueprint will not delete or migrate existing field values. If this field is new or unused, changing the type is normally safe. If it already contains data, review affected content after saving.', 'core-blueprint' ),
							'typeChangeConfirm' => __( 'Change field type', 'core-blueprint' ),
							'typeChangeCancel' => __( 'Keep current type', 'core-blueprint' ),
							'quickSaving' => __( 'Saving…', 'core-blueprint' ),
							'quickSaved' => __( 'Field saved.', 'core-blueprint' ),
							'quickError' => __( 'The field could not be saved.', 'core-blueprint' ),
							'requiredLabel' => __( 'required', 'core-blueprint' ),
							'restEnabled' => __( 'Enabled', 'core-blueprint' ),
							'restDisabled' => __( 'Disabled', 'core-blueprint' ),
							'reorderSubfield' => __( 'Reorder', 'core-blueprint' ),
						],
					],
				];
			},
		];
	}
}
