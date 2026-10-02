<?php
declare(strict_types=1);
/** Runtime hot/cold option policy for Core Blueprint Base. */

namespace CB\Core;

defined( 'ABSPATH' ) || exit;

final class OptionPolicy {
	private const VERSION = 1;
	private const VERSION_OPTION = 'cb_core_option_policy_version';

	/** Small values read on normal requests and safe for WordPress alloptions. */
	private const HOT_OPTIONS = [
		'cb_core_settings',
		'cb_core_access_mode',
		'cb_core_db_version',
		'cb_core_mail_log_db_version',
		'cb_core_notes_db_version',
		'cb_core_reports_db_version',
		'cb_core_db_health_checked_at',
	];

	/**
	 * Small request-hot option keys that may intentionally be absent or
	 * non-autoloaded. Priming them batches the first lookup without persisting
	 * a default value or widening the alloptions payload.
	 */
	private const REQUEST_CACHE_OPTIONS = [
		'cb_core_user_roles_enabled',
		'cb_core_media_replace_enabled',
		'cb_core_media_formats',
		'cb_core_package_download_enabled',
		'cb_core_content_models_enabled',
		'cb_core_snippets_settings',
		'cb_core_hud_disabled',
		'cb_core_trust_schema_version',
		'cb_core_role_policy_schema_version',
		'cb_core_access_mode',
		'cb_core_bypass_active',
		'_transient_cb_core_bypass_window',
		'_transient_timeout_cb_core_bypass_window',
		'cb_core_migration_recovery_state',
		'cb_core_last_version',
		'cb_core_privileged_guard_bootstrapped',
		'cb_core_mail_settings',
		'cb_locale_default',
		'cb_core_ai_activity_retention_days',
		'cb_core_routing_runtime_suspended',
		'cb_core_routing_rewrite_dirty',
		'cb_core_content_models_rewrite_dirty',
	];

	/** Admin-screen-only request-hot option keys. */
	private const ADMIN_CACHE_OPTIONS = [
		'cb_core_setup_first_run_redirect',
		'cb_core_bypass_token',
		'_transient_cb_core_privileged_guard_sweep',
		'_transient_timeout_cb_core_privileged_guard_sweep',
		'cb_core_role_policy_drift',
		'cb_core_admin_navigation_policy',
		'cb_core_theme_default',
	];

	public static function prime_request_cache( bool $admin_screen = false ): void {
		$options = self::REQUEST_CACHE_OPTIONS;
		if ( $admin_screen ) {
			$options = array_merge( $options, self::ADMIN_CACHE_OPTIONS );
		}

		wp_prime_option_caches( $options );
	}

	public static function maybe_sync(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::VERSION ) {
			return;
		}
		self::sync_active();
	}

	public static function sync_active(): void {
		$values = [];
		foreach ( self::HOT_OPTIONS as $option ) {
			$values[ $option ] = true;
		}
		wp_set_option_autoload_values( $values );
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	public static function mark_inactive(): void {
		$values = [];
		foreach ( self::HOT_OPTIONS as $option ) {
			$values[ $option ] = false;
		}
		wp_set_option_autoload_values( $values );
		delete_option( self::VERSION_OPTION );
	}
}
