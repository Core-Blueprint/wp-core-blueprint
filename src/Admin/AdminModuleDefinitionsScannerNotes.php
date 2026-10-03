<?php
declare(strict_types=1);
/** Private BASE-10E.2 module definitions: ScannerNotes. */

namespace CoreBlueprint\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminModuleDefinitionsScannerNotes {

	/** @return array<string,callable():array<string,mixed>> */
	public static function factories( string $admin_nonce, string $ajax_url, array $save_status ): array {
		return [
			'@cb-core/core-scanner' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/core-scanner',
					'src'  => 'features/core-scanner.js',
					'deps' => [ '@cb-core/dom', '@cb-core/busy', '@cb-core/modal', '@cb-core/toast' ],
					'data' => [
						'restUrl'   => esc_url_raw( rest_url( 'core-blueprint/v1/integrity/admin' ) ),
						'nonce'     => wp_create_nonce( 'wp_rest' ),
						'activeJob' => self::core_scanner_active_job_data(),
						'i18n'    => [
							'running'                   => __( 'Running Core Scanner…', 'core-blueprint' ),
							'complete'                  => __( 'Core scan completed.', 'core-blueprint' ),
							'failed'                    => __( 'Core scan failed.', 'core-blueprint' ),
							'saving'                    => __( 'Saving settings…', 'core-blueprint' ),
							'saved'                     => __( 'Settings saved.', 'core-blueprint' ),
							'clearing'                  => __( 'Clearing results…', 'core-blueprint' ),
							'cleared'                   => __( 'Results cleared.', 'core-blueprint' ),
							'clearFailed'               => __( 'Clear failed.', 'core-blueprint' ),
							'approvingBaseline'         => __( 'Approving baseline…', 'core-blueprint' ),
							'baselineApproved'          => __( 'Baseline approved.', 'core-blueprint' ),
							'baselineFailed'            => __( 'Baseline approval failed.', 'core-blueprint' ),
							'baselineReviewing'         => __( 'Saving review…', 'core-blueprint' ),
							'baselineReviewed'          => __( 'Baseline candidate marked as reviewed.', 'core-blueprint' ),
							'baselineReviewFailed'      => __( 'Could not save baseline review.', 'core-blueprint' ),
							'removingBaseline'          => __( 'Removing from baseline…', 'core-blueprint' ),
							'baselineRemoved'           => __( 'Removed from approved baseline.', 'core-blueprint' ),
							'baselineRemoveFailed'      => __( 'Removal failed.', 'core-blueprint' ),
							'clearingBaseline'          => __( 'Clearing baseline…', 'core-blueprint' ),
							'baselineCleared'           => __( 'Approved baseline cleared.', 'core-blueprint' ),
							'baselineClearFailed'       => __( 'Clear failed.', 'core-blueprint' ),
							'clearTitle'                => __( 'Clear scan results', 'core-blueprint' ),
							'confirmClear'              => __( 'Clear the stored integrity scan result? Files on this site will not be modified.', 'core-blueprint' ),
							'clearConfirm'              => __( 'Clear results', 'core-blueprint' ),
							'baselineTitle'             => __( 'Approve all local baselines', 'core-blueprint' ),
							'baselineUpdateTitle'       => __( 'Update all local baselines', 'core-blueprint' ),
							'confirmBaseline'           => __( 'Bulk-approve every eligible plugin and theme snapshot from the latest scan as a local baseline. Review the listed components first; future scans will treat these exact file states as trusted.', 'core-blueprint' ),
							'confirmBaselineUpdate'     => __( 'Bulk-update every eligible plugin and theme local baseline from the latest scan. Only continue after reviewing the affected components and confirming their current file states are expected.', 'core-blueprint' ),
							'baselineConfirm'           => __( 'Approve all eligible baselines', 'core-blueprint' ),
							'baselineUpdate'            => __( 'Update all eligible baselines', 'core-blueprint' ),
							'componentBaselineTitle'    => __( 'Approve component baseline', 'core-blueprint' ),
							'componentBaselineConfirm'  => __( 'Approve component', 'core-blueprint' ),
							'confirmComponentApprove'   => __( 'Approve the current state of {slug} as the local baseline. Future changes for this component will be flagged.', 'core-blueprint' ),
							'confirmComponentUpdate'    => __( 'Update the approved baseline for {slug}. Future scans will compare this component against its current state. Only continue after confirming this change is expected.', 'core-blueprint' ),
							'baselineRemoveTitle'       => __( 'Remove from approved baseline', 'core-blueprint' ),
							'confirmBaselineRemove'     => __( 'Remove {slug} from the approved baseline. The scanner will stop tracking this component. Use this only when the component has been intentionally removed from the site.', 'core-blueprint' ),
							'baselineRemove'            => __( 'Remove from baseline', 'core-blueprint' ),
							'baselineClearTitle'        => __( 'Clear approved baseline', 'core-blueprint' ),
							'confirmBaselineClear'      => __( 'Clear the entire approved baseline. All previously approved components will need to be re-verified - run a new scan afterwards to start fresh.', 'core-blueprint' ),
							'baselineClear'             => __( 'Clear Approved Baseline', 'core-blueprint' ),
							'redetectingLocale'         => __( 'Detecting…', 'core-blueprint' ),
							'scanAlreadyRunning'        => __( 'A scan is already running. Progress has been restored.', 'core-blueprint' ),
							'scanLost'                  => __( 'Scan progress lost. Reloading.', 'core-blueprint' ),
							'phaseStarting'             => __( 'Starting…', 'core-blueprint' ),
							'phaseCore'                 => __( 'Verifying core files', 'core-blueprint' ),
							'phasePlugins'              => __( 'Verifying plugins', 'core-blueprint' ),
							'phaseThemes'               => __( 'Verifying themes', 'core-blueprint' ),
							'phaseUploads'              => __( 'Scanning uploads', 'core-blueprint' ),
							'runningScan'               => __( 'Running Core Scanner', 'core-blueprint' ),
							'scanProgressAria'          => __( 'Scan progress', 'core-blueprint' ),
							'localeDetected'            => __( 'Distribution locale detected: {locale}', 'core-blueprint' ),
							'localeInconclusive'        => __( 'Detection inconclusive - see panel for details.', 'core-blueprint' ),
							'localeDetectFailed'        => __( 'Detection failed.', 'core-blueprint' ),
							'cancel'                    => __( 'Cancel', 'core-blueprint' ),
							'confirm'                   => __( 'Confirm', 'core-blueprint' ),
							'copyPath'                  => __( 'Copy path', 'core-blueprint' ),
							'pathCopied'                => __( 'Filesystem path copied.', 'core-blueprint' ),
							'pathCopyFailed'            => __( 'Could not copy the filesystem path.', 'core-blueprint' ),
							'quarantineFileTitle'       => __( 'Quarantine file', 'core-blueprint' ),
							'quarantineDirectoryTitle'  => __( 'Quarantine folder', 'core-blueprint' ),
							'quarantineFileBody'        => __( 'Move this exact scanned file out of the active site and into the private Quarantine Workspace? Its SHA-256 is re-verified immediately before the move.', 'core-blueprint' ),
							'quarantineDirectoryBody'   => __( 'Move this finding’s top-level uploads directory out of the active site and into the private Quarantine Workspace? Every file is re-verified first; symlinks or changed evidence abort the action.', 'core-blueprint' ),
							'quarantineConfirm'         => __( 'Quarantine', 'core-blueprint' ),
							'quarantining'              => __( 'Quarantining…', 'core-blueprint' ),
							'quarantineDone'            => __( 'Item moved to Quarantine Workspace.', 'core-blueprint' ),
							'quarantineInspectTitle'    => __( 'Inspect quarantined item', 'core-blueprint' ),
							'quarantineOriginalPath'    => __( 'Original location', 'core-blueprint' ),
							'quarantineStatus'          => __( 'Status', 'core-blueprint' ),
							'quarantineFiles'           => __( 'Files', 'core-blueprint' ),
							'quarantineNoPreview'       => __( 'No safe text preview is available for this file.', 'core-blueprint' ),
							'quarantinePreviewTruncated'=> __( 'Preview truncated.', 'core-blueprint' ),
							'quarantineRestoreTitle'    => __( 'Restore quarantined item', 'core-blueprint' ),
							'quarantineRestoreBody'     => __( 'Restore this item to its exact original location? Restore is refused if anything now exists at that path or if the quarantine payload changed.', 'core-blueprint' ),
							'quarantineRestore'         => __( 'Restore', 'core-blueprint' ),
							'quarantineRestoring'       => __( 'Restoring…', 'core-blueprint' ),
							'quarantineRestored'        => __( 'Quarantine item restored.', 'core-blueprint' ),
							'quarantineDeleteTitle'     => __( 'Permanently delete quarantined item', 'core-blueprint' ),
							'quarantineDeleteBody'      => __( 'This permanently destroys the isolated payload. The workspace record and audit trail remain, but the file cannot be restored.', 'core-blueprint' ),
							'quarantineDelete'          => __( 'Permanently delete', 'core-blueprint' ),
							'quarantineDeleteHint'      => __( 'Type to confirm:', 'core-blueprint' ),
							'quarantineDeleting'        => __( 'Deleting…', 'core-blueprint' ),
							'quarantineDeleted'         => __( 'Quarantine payload permanently deleted.', 'core-blueprint' ),
							'quarantineNoteTitle'       => __( 'Add quarantine note', 'core-blueprint' ),
							'quarantineNoteBody'        => __( 'Add investigation context for this item. Notes become part of the workspace history.', 'core-blueprint' ),
							'quarantineNoteSave'        => __( 'Save note', 'core-blueprint' ),
							'quarantineNotePlaceholder' => __( 'Investigation note…', 'core-blueprint' ),
							'quarantineNoteSaved'       => __( 'Quarantine note saved.', 'core-blueprint' ),
							'quarantineStateSaved'      => __( 'Quarantine review state updated.', 'core-blueprint' ),
							'close'                     => __( 'Close', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/notes' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/notes',
					'src'  => 'features/notes.js',
					'deps' => [ '@cb-core/dom', '@cb-core/modal', '@cb-core/toast', '@cb-core/busy' ],
					'data' => [
						'restRoot' => esc_url_raw( rest_url( 'core-blueprint/v1/notes/' ) ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
						'settings' => class_exists( '\\CoreBlueprint\\Core\\Notes\\Settings\\SettingsRepository' )
							? \CoreBlueprint\Core\Notes\Settings\SettingsRepository::all()
							: [],
						'i18n'     => [
							/* translators: %d: number of selected notes; substituted by the Notes interface. */
							'noteSelected'         => __( '%d note selected.', 'core-blueprint' ),
							/* translators: %d: number of selected notes; substituted by the Notes interface. */
							'notesSelected'        => __( '%d notes selected.', 'core-blueprint' ),
							'note'                 => __( 'Note', 'core-blueprint' ),
							'addNote'              => __( 'Add note', 'core-blueprint' ),
							'saveChanges'          => __( 'Save changes', 'core-blueprint' ),
							'cancel'               => __( 'Cancel', 'core-blueprint' ),
							'saved'                => __( 'Saved', 'core-blueprint' ),
							'requestFailed'        => __( 'Request failed', 'core-blueprint' ),
							'importNotes'          => __( 'Import Notes', 'core-blueprint' ),
							'importNotesAction'    => __( 'Import notes', 'core-blueprint' ),
							'importReview'         => __( 'Review the imported notes before saving them. Existing notes are detected by context or title.', 'core-blueprint' ),
							'noImportableNotes'    => __( 'No importable notes found.', 'core-blueprint' ),
							'untitledNote'         => __( 'Untitled note', 'core-blueprint' ),
							'noChanges'            => __( 'No changes', 'core-blueprint' ),
							'importDecision'       => __( 'Import decision', 'core-blueprint' ),
							'skip'                 => __( 'Skip', 'core-blueprint' ),
							'importAsNew'          => __( 'Import as new', 'core-blueprint' ),
							'importAsCopy'         => __( 'Import as copy', 'core-blueprint' ),
							'overwriteExisting'    => __( 'Overwrite existing', 'core-blueprint' ),
							'notesImported'        => __( 'Notes imported.', 'core-blueprint' ),
							'importStateNew'       => __( 'New', 'core-blueprint' ),
							'importStateChanged'   => __( 'Changed', 'core-blueprint' ),
							'importStateIdentical' => __( 'Identical', 'core-blueprint' ),
						],
					],
				];
			},
			'@cb-core/user-roles' => static function () use ( $admin_nonce, $ajax_url, $save_status ): array {
				return [
					'id'   => '@cb-core/user-roles',
					'src'  => 'features/user-roles.js',
					'deps' => [ '@cb-core/modal', '@cb-core/toast', '@cb-core/icon' ],
					'data' => [
						'restRoot' => esc_url_raw( rest_url( 'core-blueprint/v1/roles' ) ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
						'i18n'     => [
							'requestFailed'                 => __( 'Could not load or update user roles.', 'core-blueprint' ),
							'noRoles'                       => __( 'No roles found.', 'core-blueprint' ),
							'selectRole'                    => __( 'Select a role', 'core-blueprint' ),
							'system'                        => __( 'System', 'core-blueprint' ),
							'systemRole'                    => __( 'System role', 'core-blueprint' ),
							'wordpress'                     => __( 'WordPress', 'core-blueprint' ),
							'defaultRole'                   => __( 'Default', 'core-blueprint' ),
							/* translators: %d: number of users assigned to the role; substituted by the User Roles interface. */
							'oneUser'                       => __( '%d user', 'core-blueprint' ),
							/* translators: %d: number of users assigned to the role; substituted by the User Roles interface. */
							'manyUsers'                     => __( '%d users', 'core-blueprint' ),
							'protectedBecause'              => __( 'Protected', 'core-blueprint' ),
							'roleName'                      => __( 'Role name', 'core-blueprint' ),
							'roleSlug'                      => __( 'Role slug', 'core-blueprint' ),
							'slugHelp'                      => __( 'Permanent machine name. It cannot be changed later.', 'core-blueprint' ),
							'saveName'                      => __( 'Save name', 'core-blueprint' ),
							'duplicate'                     => __( 'Duplicate', 'core-blueprint' ),
							'duplicateRole'                 => __( 'Duplicate role', 'core-blueprint' ),
							'deleteRole'                    => __( 'Delete role', 'core-blueprint' ),
							'capabilities'                  => __( 'Capabilities', 'core-blueprint' ),
							'capabilityHelp'                => __( 'Primitive WordPress permissions assigned to this role.', 'core-blueprint' ),
							'saveCapabilities'              => __( 'Save capabilities', 'core-blueprint' ),
							'searchCapabilities'            => __( 'Search capabilities', 'core-blueprint' ),
							'searchCapabilitiesPlaceholder' => __( 'Search by name or capability…', 'core-blueprint' ),
							'source'                        => __( 'Source', 'core-blueprint' ),
							'allSources'                    => __( 'All sources', 'core-blueprint' ),
							'noCapabilities'                => __( 'No capabilities match the current filters.', 'core-blueprint' ),
							'required'                      => __( 'Required', 'core-blueprint' ),
							'policyGrant'                   => __( 'Granted by policy', 'core-blueprint' ),
							'outsideAuthority'              => __( 'Outside your authority', 'core-blueprint' ),
							'createRole'                    => __( 'Add role', 'core-blueprint' ),
							'create'                        => __( 'Create role', 'core-blueprint' ),
							'cancel'                        => __( 'Cancel', 'core-blueprint' ),
							'nameSlugRequired'              => __( 'Role name and slug are required.', 'core-blueprint' ),
							'startEmpty'                    => __( 'Start with no capabilities', 'core-blueprint' ),
							'copyFrom'                      => __( 'Copy from', 'core-blueprint' ),
							'capabilityTemplate'            => __( 'Capability template', 'core-blueprint' ),
							'deleteWarning'                 => __( 'This permanently removes the role definition. Users must be reassigned before a role can be deleted.', 'core-blueprint' ),
							'typeSlug'                      => __( 'Type the role slug to confirm:', 'core-blueprint' ),
							'saved'                         => __( 'Saved.', 'core-blueprint' ),
						],
					],
				];
			},
		];
	}

	/** Read-only scanner state provider used only by the Scanner module. */
	private static function core_scanner_active_job_data(): ?array {
		if ( ! class_exists( \CoreBlueprint\Core\Integrity\Scanner\ScanJobStatus::class ) ) {
			return null;
		}
		$job = \CoreBlueprint\Core\Integrity\Scanner\ScanJobStatus::active_job();
		if ( ! is_array( $job ) ) {
			return null;
		}
		return [
			'jobId'     => (string) ( $job['job_id'] ?? '' ),
			'startedAt' => (float) ( $job['started_at_micro'] ?? microtime( true ) ),
		];
	}
}
