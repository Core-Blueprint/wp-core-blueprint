<?php
/**
 * Uninstall handler for Core Blueprint.
 *
 * Called by WordPress when the operator chooses "Delete" on the plugin row.
 * Tears down Core Blueprint Base-owned state. Optional extensions own their
 * own uninstall lifecycle and are never deleted from Base.
 *
 * @package Core_Blueprint
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;


global $wpdb;

// ─── Roles + capabilities ───────────────────────────────────────────────────
// Core Blueprint owns the cb_operator role and its cb_* primitive
// capabilities. Remove assignments and strip Core-owned capabilities from
// every role so deleting the plugin does not leave dead permission flags.

$cb_core_capabilities = [
	'cb_view_reports',
	'cb_view_permissions',
	'cb_manage_reports',
	'cb_manage_branding',
	'cb_manage_permissions',
	'cb_manage_roles',
	'cb_manage_content_models',
	'cb_manage_media_replace',
	'cb_replace_media',
	'cb_upload_svg',
	'cb_manage_integrity',
	'cb_manage_integrity_policy',
	'cb_manage_snippets',
	'cb_manage_notes',
	'cb_use_cli',
	'cb_core_hud_use',
];

$operator_users = get_users( [
	'role'   => 'cb_operator',
	'fields' => 'ID',
] );
foreach ( $operator_users as $user_id ) {
	$user = get_user_by( 'id', (int) $user_id );
	if ( $user instanceof WP_User ) {
		$user->remove_role( 'cb_operator' );
	}
}

$wp_roles = wp_roles();
foreach ( array_keys( (array) $wp_roles->roles ) as $role_slug ) {
	$role = get_role( (string) $role_slug );
	if ( ! $role ) {
		continue;
	}
	foreach ( $cb_core_capabilities as $cap ) {
		if ( $role->has_cap( $cap ) ) {
			$role->remove_cap( $cap );
		}
	}
}

remove_role( 'cb_operator' );

// ─── Options owned by Core Blueprint Base ────────────────────────────────────
// Keep this list explicit: sibling extensions (notably Beacon) also use the
// cb_core_* naming family and own their own uninstall lifecycle.

$foundation_options = [
	'cb_core_settings',
	'cb_core_db_version',
	'cb_core_ai_activity_db_version',
	'cb_core_ai_activity_retention_days',
	'cb_core_db_health_checked_at',
	'cb_core_option_policy_version',
	'cb_core_theme_default',
	'cb_locale_default',
	'cb_core_bypass_active',
	'cb_core_bypass_token',
	'cb_core_description_mode_default',
	'cb_core_first_activated_at',
	'cb_core_last_version',
	'cb_core_retention',
	'cb_core_access_mode',
	'cb_core_access_mode_config',
	'cb_core_mail_settings',
	'cb_core_mail_enabled',
	'cb_core_mail_log_db_version',
	'cb_core_mail_template_overrides',
	'cb_core_snippets_settings',
	'cb_core_media_replace_enabled',
	'cb_core_media_formats',
	'cb_core_package_download_enabled',
	'cb_core_user_roles_enabled',
	'cb_core_content_models_enabled',
	'cb_core_content_models_schema',
	'cb_core_content_models_rewrite_dirty',
	'cb_core_hud_disabled',
	'cb_core_hud_menu_preferences',
	'cb_core_integrity_latest',
	'cb_core_integrity_baseline',
	'cb_core_integrity_history',
	'cb_core_integrity_scan_job',
	'cb_core_integrity_scan_lock',
	'cb_core_integrity_scan_slice_lock',
	'cb_core_notes_db_version',
	'cb_core_reports_db_version',
	'cb_core_privacy',
	'cb_core_privacy_active_preset',
	'cb_core_verbosity',
	'cb_core_privileged_guard_bootstrapped',
	'cb_core_trust_schema_version',
	'cb_core_role_policy_schema_version',
	'cb_core_role_policy_drift',
	'cb_core_profiles_apply_lock',
];

foreach ( $foundation_options as $opt ) {
	delete_option( $opt );
}

// Large/generation-based state is intentionally namespace-cleaned. These
// prefixes are Base-owned and deliberately exclude extension namespaces such
// as cb_core_beacon_*.
$cb_base_option_prefixes = [
	'cb_core_integrity_',
	'cb_core_quarantine_mutation_lock_',
	'cb_core_schema_lock_',
];
foreach ( $cb_base_option_prefixes as $prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
}

// Quarantine evidence intentionally survives uninstall. The index points to
// evidence in the private per-installation vault and must not be silently
// destroyed merely because Base is removed.
// Preserved: cb_core_quarantine_workspace.

// ─── Transients ──────────────────────────────────────────────────────────────

$cb_base_transient_prefixes = [
	'cb_core_bypass_window',
	'cb_core_failsafe_rejected_audit_gate',
	'cb_core_failsafe_t_',
	'cb_core_failsafe_test_',
	'cb_core_integrity_scan_progress_',
	'cb_core_mail_result_',
	'cb_core_snippets_result_',
	'cb_core_snippets_draft_',
	'cb_core_new_token_',
	'cb_core_privileged_guard_sweep',
	'cb_core_media_replace_notice_',
	'cb_core_alert_',
	'cb_core_profile_',
	'cb_cm_',
];

foreach ( $cb_base_transient_prefixes as $prefix ) {
	$like = $wpdb->esc_like( $prefix ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $like,
			'_transient_timeout_' . $like
		)
	);
}

// ─── User meta ───────────────────────────────────────────────────────────────

$cb_base_user_meta = [
	'cb_core_theme',
	'cb_locale',
	'cb_core_description_mode',
	'cb_core_base_role',
	'_cb_core_privileged_approval',
	'_cb_core_privileged_review',
	'cb_core_hud_position',
	'cb_core_hud_ghost',
	'cb_core_active_brand',
	'cb_core_integrity_baseline_review',
];

if ( isset( $wpdb->usermeta ) ) {
	foreach ( $cb_base_user_meta as $meta_key ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);
	}
}

// Content Models may have written user-defined field values into ordinary
// post/term/user metadata. Those content values are deliberately preserved for
// portability/exit freedom; deleting Base removes the schema, not site content.

// ─── Media Replace attachment metadata ───────────────────────────────────────

if ( isset( $wpdb->postmeta ) ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
			'_cb_media_replaced_at',
			'_cb_media_replaced_by',
			'_cb_media_replace_revision'
		)
	);
}

$cb_uploads = wp_get_upload_dir();
if ( ! empty( $cb_uploads['basedir'] ) ) {
	$cb_media_replace_lock = trailingslashit( (string) $cb_uploads['basedir'] ) . '.core-blueprint-media-replace.lock';
	if ( is_file( $cb_media_replace_lock ) ) {
		@unlink( $cb_media_replace_lock );
	}
}

// ─── Snippets storage ownership ──────────────────────────────────────────────
// Managed snippet source is operator-authored content and intentionally survives
// Base deletion for portability/exit freedom. Only generated runtime state in
// the canonical Base-owned storage directory is neutralised. Do not resolve the
// storage-dir filter during uninstall: a filtered/custom path is externally
// configured and must never become an arbitrary deletion target.

$cb_snippets_default_dir = untrailingslashit( trailingslashit( WP_CONTENT_DIR ) . 'cb-snippets' );
$cb_snippets_runtime_files = [
	$cb_snippets_default_dir . '/runtime-index.php',
	$cb_snippets_default_dir . '/.lock',
];

foreach ( $cb_snippets_runtime_files as $runtime_file ) {
	if ( is_file( $runtime_file ) ) {
		@unlink( $runtime_file );
	}
}

// Preserved deliberately: registry.php, code/* and direct-access guard files.

// ─── Base-owned database tables ──────────────────────────────────────────────
// Identifiers cannot be parameterised with $wpdb->prepare(). Validate the
// complete generated identifier and quote it as an identifier instead.

$cb_base_tables = [
	$wpdb->prefix . 'cb_core_audit_log',
	$wpdb->prefix . 'cb_core_ai_activity',
	$wpdb->prefix . 'cb_core_mail_log',
	$wpdb->prefix . 'cb_core_notes',
	$wpdb->prefix . 'cb_maintenance_reports',
];

foreach ( $cb_base_tables as $table ) {
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
		continue;
	}
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated SQL identifier; values are not interpolated.
}

// ─── Cron ────────────────────────────────────────────────────────────────────
// wp_unschedule_hook() removes every occurrence, including async Scanner events
// whose argument list would not match wp_clear_scheduled_hook( $hook ) defaults.

$cb_base_cron_hooks = [
	'cb_core_daily_prune',
	'cb_core_privileged_guard_cron_sweep',
	'cb_core_integrity_scan_run',
	'cb_core_integrity_run_scan_async',
];
foreach ( $cb_base_cron_hooks as $hook ) {
	wp_unschedule_hook( $hook );
}
