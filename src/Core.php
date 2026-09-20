<?php
declare(strict_types=1);
/**
 * Core
 *
 * Singleton bootstrap. Wires top-level WordPress hooks for every Core Blueprint
 * subsystem. Class loading is handled by the PSR-4 autoloader registered in
 * core-blueprint.php - nothing in this class requires or includes source files.
 *
 * The failsafe-first rule (set in core-blueprint.php) is preserved: Failsafe is
 * always touched before this class so the bypass mechanism remains operational
 * even if a module crashes during bootstrap.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core;

use CB\Core\Admin\Admin;
use CB\Core\Admin\PageRegistry;
use CB\Core\Ajax\Router;
use CB\Core\Ajax\SecurityRouter;
use CB\Core\Log\AuditLog;
use CB\Core\Log\Retention;
use CB\Core\Log\SystemLog;
use CB\Core\Security\AccessMode;
use CB\Core\Security\Failsafe;
use CB\Core\Security\LoginShield;
use CB\Core\Security\ModuleRegistry;
use CB\Core\Security\Modules\Fingerprint;
use CB\Core\Security\Modules\Headers;
use CB\Core\Integrity\Scheduler\Cron as IntegrityCron;

defined( 'ABSPATH' ) || exit;

final class Core {

	private static ?Core $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function __clone() {}

	public function __wakeup() {
		throw new \LogicException( 'Core cannot be unserialized.' );
	}

	// ─── Hooks ────────────────────────────────────────────────────────────────

	private function init_hooks(): void {
		// WP 6.7+ requires translations to load on `init` or later.
		add_action( 'init', [ $this, 'load_textdomain' ], 0 );

		// Request-hot option policy, then DB/settings migrations.
		add_action( 'plugins_loaded', [ OptionPolicy::class, 'maybe_sync' ], 3 );
		add_action( 'plugins_loaded', [ DB::class,               'maybe_upgrade' ], 5 );
		add_action( 'plugins_loaded', [ SettingsMigrator::class, 'maybe_migrate' ], 6 );
		add_action( 'plugins_loaded', [ Retention::class,        'init' ],          7 );
		add_action( 'plugins_loaded', [ EmailAlerts::class,      'init' ],          8 );
		add_action( 'plugins_loaded', [ AccessMode::class,       'boot' ],          9 );
		add_action( 'plugins_loaded', [ LoginShield::class,      'boot' ],          9 );
		add_action( 'plugins_loaded', [ SystemLog::class,        'boot' ],          9 );

		// Audit Log - register the shutdown-flush for queued events once per
		// request. Queue fills when callers use AuditLog::queue() instead of
		// ::log() for high-volume events.
		AuditLog::init_queue();


		// Built-in module registration filter.
		add_filter( 'cb_core_modules', [ $this, 'register_builtin_modules' ] );

		// Module registry boots at priority 20, after all CB plugins had a
		// chance to register.
		add_action( 'plugins_loaded', [ ModuleRegistry::class, 'boot' ], 20 );

		// Locale integration - priority 20 so multilingual plugins (typically
		// priority 10) remain authoritative.
		add_filter( 'locale', [ Locale::class, 'filter_wp_locale' ], 20 );

		// Fingerprint Core Blueprint-owned assets independently from the plugin
		// release version. RC builds may intentionally share a semantic version;
		// without a per-file fingerprint browsers/proxies can mix new PHP with
		// stale CSS/JS from an earlier package.
		add_filter( 'style_loader_src',  [ $this, 'fingerprint_asset_src' ], 20, 2 );
		add_filter( 'script_loader_src', [ $this, 'fingerprint_asset_src' ], 20, 2 );

		// Public extension registration is collected once on init after every
		// plugin had a chance to attach its declarative registration callback.
		ExtensionRegistry::init();

		// Normal wp-admin HTML screens own menus, registered Core Admin pages
		// and their visual shell. admin-ajax.php and admin-post.php are request
		// endpoints, not screens, and must not boot browser presentation.
		if ( RequestContext::is_admin_screen() ) {
			PageRegistry::init();
			Admin::init();
			Extensions::init();
		}

		// AJAX routers exist only for actual admin-ajax.php requests. Their
		// handlers are still registered before WordPress dispatches the action.
		if ( RequestContext::is_ajax() ) {
			Router::init();
			SecurityRouter::init();
		}

		// Theme-attribute pre-paint injection belongs to normal admin HTML only.
		if ( RequestContext::is_admin_screen() ) {
			add_action( 'admin_head',       [ Themes::class, 'emit_prepaint_hooks' ], 1 );
			add_filter( 'admin_body_class', [ Themes::class, 'filter_admin_body_class' ] );
		}

		// Reports subsystem - registers the cb_maintenance_reports schema
		// with the central DB registry on plugins_loaded priority 4 so the
		// migration sweep at priority 5 picks it up alongside every other
		// CB-owned table. Admin-page wiring lands in subsequent milestones.
		\CB\Core\Reports\Bootstrap::boot();

		// Permissions subsystem - registers the user_has_cap filter (admin-
		// toggle for cb_manage_reports) and the OperatorGuard role-change
		// listeners that auto-disable hide_from_admins when the operator
		// count drops to zero.
		\CB\Core\Permissions\Bootstrap::boot();

		// Media Replace subsystem - native attachment replacement with a
		// transactional rollback path. v1 preserves attachment ID, filename
		// and URL; the filename strategy boundary is ready for a later
		// rename + reference-update mode.
		\CB\Core\MediaReplace\Bootstrap::boot();

		// Media Formats subsystem - optional modern image upload policy,
		// SVG sanitization, capability-aware MIME support and WordPress-native
		// generated image format mapping. Disabled means WordPress remains
		// completely untouched by this subsystem.
		\CB\Core\MediaFormats\Bootstrap::boot();

		// Package Downloads subsystem - export installed plugins and themes as
		// installable ZIP archives from their native WordPress admin screens.
		// Archives are built in temporary storage; package source directories
		// are never mutated as part of the download flow.
		\CB\Core\PackageDownload\Bootstrap::boot();

		// Mail subsystem - privacy-first outbound delivery with a dedicated
		// delivery log. The admin/configuration layer is always available;
		// Runtime itself registers transport hooks only when explicitly enabled
		// and no known SMTP/mail plugin conflict is active.
		\CB\Core\Mail\Bootstrap::boot();

		// Content Models subsystem - governed custom post types and taxonomies.
		// The subsystem remains fully deactivatable: disabling registration
		// preserves definitions and WordPress content for later recovery.
		\CB\Core\ContentModels\Bootstrap::boot();

		// Snippets subsystem - managed PHP/CSS/JavaScript/HTML snippets.
		// Boot synchronously so enabled PHP snippets can intentionally target
		// plugins_loaded; Runtime itself remains gated by State + SafeMode.
		\CB\Core\Snippets\Bootstrap::boot();

		// Core Scanner subsystem - file integrity verification (WP core
		// checksums, supported plugin/theme checksums, uploads executable
		// scan). Tab-rendered inside Safeguards; no separate top-level
		// page. Cron handler stays dormant unless schedule != 'disabled'.
		\CB\Core\Integrity\Bootstrap::boot();


		// Notes subsystem - site-specific notes for maintenance, security
		// context, and operational handover. Top-level page (position 22,
		// between Logs and Reports), settings as a Preferences tab.
		\CB\Core\Notes\Bootstrap::boot();

		// Profiles subsystem - portable, versioned Base configuration with a
		// mandatory preview/diff and transactional apply/rollback boundary.
		// Profiles deliberately exclude secrets, identities and runtime evidence.
		\CB\Core\Profiles\Bootstrap::boot();


		// HUD subsystem - the floating "front door" launcher. Renders on
		// admin AND frontend for capable logged-in users; honours the
		// cb_core_hud_enabled filter + Preferences › Appearance toggle as
		// a kill-switch. Brand abstraction (BrandRegistry, BrandInterface)
		// provides the supported white-label extension boundary.
		\CB\Core\HUD\Bootstrap::boot();

		// Per-subsystem HUD-item registration. Each Bootstrap below only
		// hooks the cb_hud_register_items action - no other side effects.
		// Conditional registrations (Notes/Reports check their master
		// switch) live inside the per-subsystem callback. Boot order is
		// alphabetical and inconsequential - items sort by their `order`
		// key inside HUD\Registry.
		\CB\Core\Log\Bootstrap::boot();
		\CB\Core\Safeguards\Bootstrap::boot();
		\CB\Core\Preferences\Bootstrap::boot();

		// CLI subsystem - registers the HUD documentation item linking
		// to Preferences › CLI. Actual `wp cb …` command registration
		// happens in core-blueprint.php on plugin load (must run before
		// WP-CLI's command-resolution pass) - this boot() call only
		// wires the in-admin/frontend HUD entry.
		\CB\Core\CLI\Bootstrap::boot();

		// Console subsystem - operator-only browser surface for governed
		// CB CLI commands. Read-only, state-changing and destructive
		// commands share the same capability boundary; destructive actions
		// additionally require explicit consequence confirmation.
		\CB\Core\Console\Bootstrap::boot();

		// Signal that Core Blueprint is fully booted. Extension plugins may hook here.
		add_action( 'plugins_loaded', static function () {
			do_action( 'cb_core_booted' );
		}, 25 );
	}

	/**
	 * Collect built-in Core Shield modules (Fingerprint, Headers).
	 *
	 * Registered directly here rather than via the cb_core_modules filter
	 * because Core owns its own built-ins - no filter roundtrip needed.
	 *
	 * Core Scanner is intentionally not a Core Shield module. It owns its
	 * Safeguards surface and runs through its Scanner-specific Admin, REST
	 * and Cron boundaries rather than a decorative Core Shield toggle.
	 */
	public function register_builtin_modules( array $modules ): array {
		$modules[] = new Fingerprint();
		$modules[] = new Headers();
		return $modules;
	}


	/**
	 * Append a per-file cache fingerprint to local Core Blueprint assets.
	 *
	 * CB_CORE_VERSION remains the release version passed at enqueue time. The
	 * additional `cbv` query value solves the separate cache problem created by
	 * repeated staging packages that deliberately keep the same release version.
	 *
	 * @param string $src    Enqueued asset URL.
	 * @param string $handle WordPress asset handle (unused; kept for filter contract).
	 */
	public function fingerprint_asset_src( string $src, string $handle = '' ): string {
		unset( $handle );

		if ( '' === $src || ! str_starts_with( $src, CB_CORE_URL ) ) {
			return $src;
		}

		$asset_url_path = wp_parse_url( $src, PHP_URL_PATH );
		$core_url_path  = wp_parse_url( CB_CORE_URL, PHP_URL_PATH );
		if ( ! is_string( $asset_url_path ) || ! is_string( $core_url_path ) || ! str_starts_with( $asset_url_path, $core_url_path ) ) {
			return $src;
		}

		$relative = ltrim( substr( $asset_url_path, strlen( $core_url_path ) ), '/' );
		if ( '' === $relative || str_contains( $relative, '..' ) ) {
			return $src;
		}

		$file = CB_CORE_DIR . $relative;
		if ( ! is_file( $file ) ) {
			return $src;
		}

		$modified = filemtime( $file );
		if ( false === $modified ) {
			return $src;
		}

		return add_query_arg( 'cbv', (string) $modified, $src );
	}

	// ─── i18n ─────────────────────────────────────────────────────────────────

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'core-blueprint',
			false,
			dirname( plugin_basename( CB_CORE_FILE ) ) . '/languages'
		);
	}

	// ─── Activation / Deactivation ────────────────────────────────────────────

	public static function activate(): void {
		// Legacy detection happens automatically on next plugins_loaded.
		// Capture first-install state before writing the marker. Only a genuine
		// first activation may bootstrap the activating user as trust root; a
		// later deactivate/reactivate cycle must never mint a new CB Operator.
		$is_first_activation = ! get_option( 'cb_core_first_activated_at', false );
		if ( $is_first_activation ) {
			update_option( 'cb_core_first_activated_at', current_time( 'mysql' ), false );
		}

		// Ensure defaults are written so subsequent reads are deterministic.
		if ( ! get_option( 'cb_core_theme_default', false ) ) {
			update_option( 'cb_core_theme_default', 'auto', false );
		}
		if ( ! get_option( 'cb_locale_default', false ) ) {
			update_option( 'cb_locale_default', 'auto', false );
		}

		// Reconcile Base's registered schemas through the same canonical lifecycle
		// used by extensions. On first activation this creates the audit table;
		// marker ownership remains inside the migration controller.
		DB::maybe_upgrade();

		Failsafe::ensure_token();

		if ( ! get_option( CB_CORE_SETTINGS, false ) ) {
			update_option( CB_CORE_SETTINGS, Settings::defaults(), true );
		}

		OptionPolicy::sync_active();

		Retention::schedule();

		// Privileged Access Guard unattended reconciliation. The guard also
		// self-heals this schedule on normal plugin load after ZIP updates.
		\CB\Core\Permissions\PrivilegedAccessGuard::ensure_schedule();

		// Core Scanner cron - sync the optional scheduled scan based on
		// the current `cb_core_settings['integrity'].schedule` value. If
		// schedule is 'disabled', sync_schedule() clears any orphaned
		// hook; if 'daily' or 'weekly' it (re)schedules.
		IntegrityCron::sync_schedule();

		// Apply any explicitly defined public trust-schema migration before the
		// protected role is reconciled. Public v1 starts at trust schema 1; there
		// are no historical public migration steps.
		if ( ! $is_first_activation ) {
			\CB\Core\Permissions\TrustSchemaMigrator::maybe_migrate();
		}

		// Role Policy is initialized only on a genuine first installation. A
		// missing/corrupt marker on an established site is drift and never grants
		// authority to repair roles during activation or normal runtime. Future
		// known schema upgrades are handled by RolePolicySchema's explicit migrator.
		if ( $is_first_activation ) {
			\CB\Core\Permissions\RolePolicySchema::initialize_first_install();
		} else {
			\CB\Core\Permissions\RolePolicySchema::maybe_migrate();
		}

		$current_user = wp_get_current_user();
		if ( $is_first_activation && $current_user instanceof \WP_User && $current_user->ID ) {
			if ( ! in_array( \CB\Core\Permissions\Roles::OPERATOR_ROLE, (array) $current_user->roles, true ) ) {
				\CB\Core\Permissions\PrivilegedAccessGuard::trusted_mutation( static function () use ( $current_user ): void {
					$current_user->add_role( \CB\Core\Permissions\Roles::OPERATOR_ROLE );
				} );
				AuditLog::log( 'permissions.first_operator_assigned', 'notice', [
					'user_id'    => $current_user->ID,
					'user_login' => $current_user->user_login,
				] );
			}

			// Bind the first-install trust root to its exact current privilege
			// fingerprint. Subsequent activations never write approvals here.
			if ( \CB\Core\Permissions\PrivilegedAccessPolicy::is_privileged( $current_user ) ) {
				\CB\Core\Permissions\PrivilegedAccessRegistry::approve( $current_user, (int) $current_user->ID, 'first_activation' );
			}

			\CB\Core\Permissions\PrivilegedAccessGuard::complete_first_activation();
		}

		// A genuine first install is born on the current public trust schema.
		if ( $is_first_activation ) {
			\CB\Core\Permissions\TrustSchemaMigrator::mark_current();
		}

		AuditLog::log( 'plugin.activated', 'notice', [
			'version' => CB_CORE_VERSION,
		] );
	}

	public static function deactivate(): void {
		AuditLog::log( 'plugin.deactivated', 'warning', [
			'version' => CB_CORE_VERSION,
		] );

		// Clear bypass transient window. Persistent emergency-bypass option is
		// intentionally left alone - deactivation must never silently re-enable
		// restrictions.
		delete_transient( Failsafe::BYPASS_TRANSIENT );

		Retention::unschedule();

		// Clear Privileged Access Guard cron so deactivation leaves no orphan.
		\CB\Core\Permissions\PrivilegedAccessGuard::clear_schedule();

		// Cancel an in-flight resumable Scanner job before clearing its cron.
		// This releases the cross-entrypoint lock and removes chunked job state,
		// so reactivation cannot inherit a stale running scan.
		\CB\Core\Integrity\Scanner\ScanJobRunner::cancel_active();
		\CB\Core\Integrity\Scanner\ScanSliceLock::clear();

		// Clear Core Scanner cron - unconditional, deactivation should
		// never leave orphaned scheduled hooks behind.
		IntegrityCron::clear_schedule();

		// A request cannot legitimately keep owning a Profile apply lease once
		// Base is deactivated. Preview transients may simply expire naturally.
		\CB\Core\Profiles\ApplyLock::clear();

		// Base is no longer executing on normal requests; keep its small runtime
		// state out of WordPress alloptions until reactivation.
		OptionPolicy::mark_inactive();
	}
}
