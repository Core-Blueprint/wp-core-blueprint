<?php
declare(strict_types=1);
/**
 * Core Blueprint - System event logger.
 *
 * Hooks into WordPress core events and records them in the audit_log table
 * under the `system.*` prefix. Core Blueprint's System Log tab reads this
 * prefix; the existing Audit Log tab reads everything NOT prefixed with
 * `system.` (i.e. Core Blueprint's own internal events).
 *
 * Scope v1 - the maintenance kern:
 *   - Plugin lifecycle (activate, deactivate, install, update, delete)
 *   - Theme lifecycle  (switch, install, update, delete)
 *   - Core updates
 *   - User management  (create, delete, role change)
 *
 * Explicitly NOT logged in v1 - normal CMS activity (post publishes,
 * media uploads, user logins). Those are user-facing content actions,
 * not maintenance actions. If needed later, they can be added via an
 * allowlist-based settings toggle.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Log;

defined( 'ABSPATH' ) || exit;

class SystemLog {

	/**
	 * Registered event types keyed by slug.
	 *
	 * Each entry: [
	 *   'description' => 'Plain-language template with {placeholders}',
	 *   'category'    => (optional) maintenance category slug - used by
	 *                    Maintenance Report. Inferred from the slug prefix
	 *                    when absent ('system.plugin_*' → 'plugin', etc.).
	 *   'severity'    => (optional) default severity if caller doesn't
	 *                    pass one. Defaults to 'info'.
	 * ]
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $event_types = [];

	/**
	 * True once the built-in types + the cb_core_system_log_event_types
	 * filter have run. Lazy - we only pay the cost on first access.
	 */
	private static bool $types_initialized = false;

	/**
	 * Pre-upgrade plugin versions, keyed by plugin file (e.g. 'wordpress-seo/wp-seo.php').
	 * Captured by on_upgrader_pre_install just before WP overwrites the files,
	 * read by on_upgrader_complete to populate the version_from context field.
	 *
	 * Cleared at the end of each upgrade batch so a stale cache cannot leak
	 * into a later request.
	 *
	 * @var array<string, string>
	 */
	private static array $pre_plugin_versions = [];

	/**
	 * Pre-upgrade theme versions, keyed by stylesheet slug. Same lifecycle as
	 * $pre_plugin_versions.
	 *
	 * @var array<string, string>
	 */
	private static array $pre_theme_versions = [];

	/**
	 * Pre-upgrade WordPress core version, captured at boot - i.e. before any
	 * core upgrade has touched $GLOBALS['wp_version']. This is the version
	 * the site WAS on when the request started; on_core_updated reads it as
	 * version_from when the new version takes its place.
	 */
	private static string $pre_core_version = '';

	/**
	 * The 13 built-in event types Core Blueprint ships with. Kept as a const
	 * for readability + so tests can inspect the baseline.
	 */
	const BUILTIN_TYPES = [
		'system.plugin_activated'    => [ 'description' => 'Plugin activated: {plugin}',            'category' => 'plugin' ],
		'system.plugin_deactivated'  => [ 'description' => 'Plugin deactivated: {plugin}',          'category' => 'plugin' ],
		'system.plugin_installed'    => [ 'description' => 'Plugin installed: {plugin}',            'category' => 'plugin' ],
		'system.plugin_updated'      => [ 'description' => 'Plugin updated: {plugin}',              'category' => 'plugin' ],
		'system.plugin_deleted'      => [ 'description' => 'Plugin deleted: {plugin}',              'category' => 'plugin' ],
		'system.theme_switched'      => [ 'description' => 'Theme switched from {from} to {to}',    'category' => 'theme'  ],
		'system.theme_installed'     => [ 'description' => 'Theme installed: {theme}',              'category' => 'theme'  ],
		'system.theme_updated'       => [ 'description' => 'Theme updated: {theme}',                'category' => 'theme'  ],
		'system.theme_deleted'       => [ 'description' => 'Theme deleted: {theme}',                'category' => 'theme'  ],
		'system.core_updated'        => [ 'description' => 'WordPress core updated to {version}',   'category' => 'core'   ],
		'system.user_created'        => [ 'description' => 'User created: {user_login}',            'category' => 'user'   ],
		'system.user_deleted'        => [ 'description' => 'User deleted: {user_login}',            'category' => 'user'   ],
		'system.user_role_changed'   => [ 'description' => 'Role changed for {user_login}: {from} → {to}', 'category' => 'user' ],
	];

	/**
	 * Register a new system event type. Safe to call multiple times -
	 * later calls with the same slug replace earlier ones.
	 *
	 * Third-party CB plugins use this to contribute their own events
	 * to the System Log + Maintenance Report.
	 *
	 * Example:
	 *   SystemLog::register_event_type( 'system.backup_completed', [
	 *     'description' => 'Backup completed: {archive_name} ({size_mb}MB)',
	 *     'category'    => 'backup',
	 *     'severity'    => 'info',
	 *   ] );
	 *
	 * @param string $slug   Must start with 'system.' by convention.
	 * @param array  $config Must contain 'description'. 'category' and
	 *                       'severity' optional.
	 */
	public static function register_event_type( string $slug, array $config ): void {
		if ( '' === $slug || empty( $config['description'] ) ) {
			_doing_it_wrong( __METHOD__, 'Event type requires a non-empty slug and description.', '1.0.0' );
			return;
		}
		self::ensure_types_initialized();
		self::$event_types[ $slug ] = array_merge( [
			'severity' => 'info',
			'category' => self::infer_category( $slug ),
		], $config );
	}

	/**
	 * Look up the configuration for an event type. Returns null when
	 * the type is unknown - callers should fall back to a generic
	 * render and the literal event-type slug.
	 */
	public static function event_type( string $slug ): ?array {
		self::ensure_types_initialized();
		return self::$event_types[ $slug ] ?? null;
	}

	/**
	 * All registered event types, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all_event_types(): array {
		self::ensure_types_initialized();
		return self::$event_types;
	}

	/**
	 * BC shim - preserves the old `SystemLog::DESCRIPTIONS`
	 * access pattern callers may still use. Returns just the
	 * description templates as a flat slug → string map.
	 *
	 * @return array<string, string>
	 */
	public static function descriptions(): array {
		$out = [];
		foreach ( self::all_event_types() as $slug => $config ) {
			$out[ $slug ] = (string) ( $config['description'] ?? $slug );
		}
		return $out;
	}

	/**
	 * Register the built-in types + fire the filter that lets third-party
	 * CB plugins hook their own types in. Lazy - runs on first access.
	 */
	private static function ensure_types_initialized(): void {
		if ( self::$types_initialized ) {
			return;
		}
		self::$types_initialized = true;

		// Seed with built-ins. Caller-registered types added via subsequent
		// register_event_type() calls will merge on top / override.
		foreach ( self::BUILTIN_TYPES as $slug => $config ) {
			self::$event_types[ $slug ] = array_merge( [
				'severity' => 'info',
				'category' => self::infer_category( $slug ),
			], $config );
		}

		/**
		 * Filter: cb_core_system_log_event_types
		 *
		 * Third-party plugins can contribute event types here. The returned
		 * array is keyed by slug. Each value must contain at least a
		 * 'description' field with {placeholder} tokens.
		 *
		 * @param array<string, array<string, mixed>> $types
		 */
		$filtered = apply_filters( 'cb_core_system_log_event_types', self::$event_types );
		if ( is_array( $filtered ) ) {
			self::$event_types = $filtered;
		}
	}

	/**
	 * Infer a maintenance-report category from an event slug. Looks at
	 * the second segment ("system.plugin_*" → "plugin"). Falls back to
	 * 'other' when the slug doesn't match the expected pattern.
	 */
	private static function infer_category( string $slug ): string {
		if ( 1 === preg_match( '/^system\.([a-z]+)/', $slug, $m ) ) {
			$known = [ 'plugin', 'theme', 'core', 'user', 'backup', 'update' ];
			$bucket = $m[1];
			foreach ( $known as $k ) {
				if ( 0 === strpos( $bucket, $k ) ) {
					return $k;
				}
			}
		}
		return 'other';
	}

	/**
	 * Register all hooks on plugins_loaded. Idempotent - boot once.
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}

		// Register the built-in event types that need explicit registration
		// beyond the BUILTIN_TYPES const (login, options, foundation lifecycle).
		self::register_extended_event_types();

		// ─── Plugin lifecycle ─────────────────────────────────────────────
		add_action( 'activated_plugin',            [ __CLASS__, 'on_plugin_activated' ],   10, 2 );
		add_action( 'deactivated_plugin',          [ __CLASS__, 'on_plugin_deactivated' ], 10, 2 );
		// Pre-install fires BEFORE WP overwrites plugin/theme files. We use it
		// to snapshot pre-upgrade versions so on_upgrader_complete can record
		// version_from in the audit log context.
		add_action( 'upgrader_pre_install',        [ __CLASS__, 'on_upgrader_pre_install' ], 10, 2 );
		add_action( 'upgrader_process_complete',   [ __CLASS__, 'on_upgrader_complete' ],  10, 2 );
		add_action( 'deleted_plugin',              [ __CLASS__, 'on_plugin_deleted' ],     10, 2 );

		// ─── Theme lifecycle ──────────────────────────────────────────────
		add_action( 'switch_theme',                [ __CLASS__, 'on_theme_switched' ],     10, 3 );
		add_action( 'deleted_theme',               [ __CLASS__, 'on_theme_deleted' ],      10, 2 );
		// Note: theme_updated + theme_installed share the upgrader_process_complete hook above.

		// ─── WP Core ──────────────────────────────────────────────────────
		// Capture the pre-upgrade core version NOW, before any core-update logic
		// runs on this request. _core_updated_successfully fires AFTER the new
		// version files are loaded - at that point $GLOBALS['wp_version'] is
		// the new version. Stashing it here gives us the from→to pair.
		if ( '' === self::$pre_core_version && isset( $GLOBALS['wp_version'] ) ) {
			self::$pre_core_version = (string) $GLOBALS['wp_version'];
		}
		add_action( '_core_updated_successfully',  [ __CLASS__, 'on_core_updated' ],       10, 1 );

		// ─── User management ──────────────────────────────────────────────
		add_action( 'user_register',               [ __CLASS__, 'on_user_created' ],       10, 1 );
		add_action( 'deleted_user',                [ __CLASS__, 'on_user_deleted' ],       10, 3 );
		add_action( 'set_user_role',               [ __CLASS__, 'on_user_role_changed' ],  10, 3 );

		// ─── Login events (new in m4.12.2) ────────────────────────────────
		// Successful logins - queued, non-critical, high-volume.
		add_action( 'wp_login',                    [ __CLASS__, 'on_login' ],              10, 2 );
		add_action( 'cb_core_two_factor_authenticated', [ __CLASS__, 'on_two_factor_authenticated' ], 10, 2 );
		// Failed logins - logged directly, security-relevant. warning severity
		// so they bypass dedup and always reach the audit log.
		add_action( 'wp_login_failed',             [ __CLASS__, 'on_login_failed' ],       10, 1 );

		// ─── Option changes (allowlist-only, excludes cb_* options) ──────
		add_action( 'updated_option',              [ __CLASS__, 'on_option_updated' ],     10, 3 );

		// ─── Core Blueprint lifecycle ─────────────────────────────────────────
		self::maybe_log_foundation_lifecycle();
	}

	/**
	 * Register event types that aren't in BUILTIN_TYPES (which is a const
	 * and thus can't reference the new login/option/foundation events
	 * without becoming static-only). Calls into the registry API.
	 */
	private static function register_extended_event_types(): void {
		$extensions = [
			'system.login'                 => [
				'description' => 'User logged in: {user_login}',
				'category'    => 'user',
				'severity'    => 'info',
			],
			'system.login_failed'          => [
				'description' => 'Failed login attempt for {user_login} from {ip}',
				'category'    => 'user',
				'severity'    => 'warning',
			],
			'system.option_changed'        => [
				'description' => 'Setting changed: {option_name}',
				'category'    => 'core',
				'severity'    => 'info',
			],
			'system.foundation_installed'  => [
				'description' => 'Core Blueprint activated on this site',
				'category'    => 'core',
				'severity'    => 'notice',
			],
			'system.foundation_upgraded'   => [
				'description' => 'Core Blueprint upgraded: {from} → {to}',
				'category'    => 'core',
				'severity'    => 'info',
			],
		];
		foreach ( $extensions as $slug => $config ) {
			self::register_event_type( $slug, $config );
		}
	}

	/**
	 * Core Blueprint install / upgrade detection. Runs on every boot but only
	 * logs once per actual version change - tracked via a wp_option that
	 * stores the last-seen version.
	 */
	private static function maybe_log_foundation_lifecycle(): void {
		if ( ! defined( 'CB_CORE_VERSION' ) ) {
			return;
		}
		$current    = (string) CB_CORE_VERSION;
		$last_known = (string) get_option( 'cb_core_last_version', '' );

		if ( $current === $last_known ) {
			return;
		}

		if ( '' === $last_known ) {
			// First time Core Blueprint has booted on this site.
			AuditLog::log( 'system.foundation_installed', 'notice', [
				'version' => $current,
			] );
		} else {
			AuditLog::log( 'system.foundation_upgraded', 'info', [
				'from' => $last_known,
				'to'   => $current,
			] );
		}
		update_option( 'cb_core_last_version', $current, false );
	}

	// ─── Plugin handlers ──────────────────────────────────────────────────

	public static function on_plugin_activated( string $plugin, bool $network_wide ): void {
		$name = self::plugin_name( $plugin );
		AuditLog::log( 'system.plugin_activated', 'info', [
			'plugin'       => $name,
			'file'         => $plugin,
			'network_wide' => $network_wide,
		] );
	}

	public static function on_plugin_deactivated( string $plugin, bool $network_wide ): void {
		$name = self::plugin_name( $plugin );
		AuditLog::log( 'system.plugin_deactivated', 'info', [
			'plugin'       => $name,
			'file'         => $plugin,
			'network_wide' => $network_wide,
		] );
	}

	public static function on_plugin_deleted( string $plugin_file, bool $deleted ): void {
		if ( ! $deleted ) {
			return;
		}
		AuditLog::log( 'system.plugin_deleted', 'info', [
			'plugin' => self::plugin_name( $plugin_file ),
			'file'   => $plugin_file,
		] );
	}

	/**
	 * Snapshot pre-upgrade plugin/theme versions just before WP overwrites
	 * the files. Reads installed Version metadata from the live install and
	 * stashes it in the static cache; on_upgrader_complete pairs the cache
	 * with the post-upgrade version to record version_from.
	 *
	 * Runs only for 'update' action - 'install' has no pre-version (the
	 * package didn't exist yet on disk).
	 *
	 * Returning $return unmodified is mandatory: this is a filter, not an
	 * action - WP feeds the return value back into the upgrade pipeline.
	 *
	 * @param mixed $return     The current pre-install bool/wp-error from prior filters.
	 * @param array $hook_extra Provided by WP, same shape as upgrader_process_complete's.
	 * @return mixed The unmodified $return value.
	 */
	public static function on_upgrader_pre_install( $return, array $hook_extra ) {
		// Errors from earlier filters short-circuit the upgrade - no point capturing.
		if ( is_wp_error( $return ) || true === $return ) {
			return $return;
		}

		$type   = $hook_extra['type']   ?? '';
		$action = $hook_extra['action'] ?? '';

		// Skip installs (no pre-version exists) and skip non-plugin/theme types.
		if ( 'update' !== $action ) {
			return $return;
		}

		if ( 'plugin' === $type ) {
			$plugins = $hook_extra['plugins'] ?? ( $hook_extra['plugin'] ?? [] );
			$plugins = is_array( $plugins ) ? $plugins : [ $plugins ];
			foreach ( $plugins as $plugin_file ) {
				if ( ! $plugin_file ) {
					continue;
				}
				$version = self::plugin_version( (string) $plugin_file );
				if ( '' !== $version ) {
					self::$pre_plugin_versions[ (string) $plugin_file ] = $version;
				}
			}
		} elseif ( 'theme' === $type ) {
			$themes = $hook_extra['themes'] ?? ( $hook_extra['theme'] ?? [] );
			$themes = is_array( $themes ) ? $themes : [ $themes ];
			foreach ( $themes as $theme_slug ) {
				if ( ! $theme_slug ) {
					continue;
				}
				$theme_obj = wp_get_theme( (string) $theme_slug );
				if ( $theme_obj->exists() ) {
					$v = (string) $theme_obj->get( 'Version' );
					if ( '' !== $v ) {
						self::$pre_theme_versions[ (string) $theme_slug ] = $v;
					}
				}
			}
		}

		return $return;
	}

	/**
	 * Unified hook for install + update events on plugins AND themes.
	 * WP passes a $hook_extra with 'type' (plugin|theme|core) and
	 * 'action' (install|update) that we route to the correct event.
	 *
	 * For updates, version_from is filled in from the cache populated by
	 * on_upgrader_pre_install. For fresh installs, version_from stays null
	 * - the package didn't exist before so there's nothing to compare.
	 */
	public static function on_upgrader_complete( $upgrader, array $hook_extra ): void {
		$type   = $hook_extra['type']   ?? '';
		$action = $hook_extra['action'] ?? '';

		if ( ! in_array( $action, [ 'install', 'update' ], true ) ) {
			return;
		}

		if ( 'plugin' === $type ) {
			$plugins = $hook_extra['plugins'] ?? ( $hook_extra['plugin'] ?? [] );
			$plugins = is_array( $plugins ) ? $plugins : [ $plugins ];
			foreach ( $plugins as $plugin_file ) {
				if ( ! $plugin_file ) {
					continue;
				}
				$event   = 'update' === $action ? 'system.plugin_updated' : 'system.plugin_installed';
				$context = [
					'plugin'  => self::plugin_name( (string) $plugin_file ),
					'file'    => $plugin_file,
					'version' => self::plugin_version( (string) $plugin_file ),
				];
				// Only updates carry version_from. Pop the cache entry as we
				// consume it so a follow-up upgrade in the same request gets
				// fresh data on its own pre-install pass.
				if ( 'update' === $action && isset( self::$pre_plugin_versions[ (string) $plugin_file ] ) ) {
					$context['version_from'] = self::$pre_plugin_versions[ (string) $plugin_file ];
					unset( self::$pre_plugin_versions[ (string) $plugin_file ] );
				}
				AuditLog::log( $event, 'info', $context );
			}
			return;
		}

		if ( 'theme' === $type ) {
			$themes = $hook_extra['themes'] ?? ( $hook_extra['theme'] ?? [] );
			$themes = is_array( $themes ) ? $themes : [ $themes ];
			foreach ( $themes as $theme_slug ) {
				if ( ! $theme_slug ) {
					continue;
				}
				$event     = 'update' === $action ? 'system.theme_updated' : 'system.theme_installed';
				$theme_obj = wp_get_theme( (string) $theme_slug );
				$context   = [
					'theme'   => $theme_obj->exists() ? (string) $theme_obj->get( 'Name' ) : $theme_slug,
					'slug'    => $theme_slug,
					'version' => $theme_obj->exists() ? (string) $theme_obj->get( 'Version' ) : '',
				];
				if ( 'update' === $action && isset( self::$pre_theme_versions[ (string) $theme_slug ] ) ) {
					$context['version_from'] = self::$pre_theme_versions[ (string) $theme_slug ];
					unset( self::$pre_theme_versions[ (string) $theme_slug ] );
				}
				AuditLog::log( $event, 'info', $context );
			}
			return;
		}

		// Core upgrades come through _core_updated_successfully instead.
	}

	// ─── Theme handlers ───────────────────────────────────────────────────

	public static function on_theme_switched( string $new_name, $new_theme, $old_theme ): void {
		$from = '';
		if ( is_object( $old_theme ) && method_exists( $old_theme, 'get' ) ) {
			$from = (string) $old_theme->get( 'Name' );
		}
		AuditLog::log( 'system.theme_switched', 'info', [
			'from' => $from,
			'to'   => $new_name,
		] );
	}

	public static function on_theme_deleted( string $stylesheet, bool $deleted ): void {
		if ( ! $deleted ) {
			return;
		}
		AuditLog::log( 'system.theme_deleted', 'info', [
			'theme' => $stylesheet,
			'slug'  => $stylesheet,
		] );
	}

	// ─── Core handler ─────────────────────────────────────────────────────

	public static function on_core_updated( string $wp_version ): void {
		$context = [
			'version' => $wp_version,
		];
		// Pre-version captured in register_hooks at request boot, before any
		// upgrade logic ran. Skip when not captured (rare - would only happen
		// if SystemLog booted after the upgrade flow had already started).
		if ( '' !== self::$pre_core_version && self::$pre_core_version !== $wp_version ) {
			$context['version_from'] = self::$pre_core_version;
		}
		AuditLog::log( 'system.core_updated', 'info', $context );
	}

	// ─── User handlers ────────────────────────────────────────────────────

	public static function on_user_created( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		$role = is_array( $user->roles ) && ! empty( $user->roles ) ? (string) $user->roles[0] : '';
		AuditLog::log( 'system.user_created', 'info', [
			'user_login'   => $user->user_login,
			'display_name' => $user->display_name,
			'role'         => $role,
			'new_user_id'  => $user_id,
		] );
	}

	public static function on_user_deleted( int $id, $reassign, $user ): void {
		$login = '';
		if ( is_object( $user ) ) {
			$login = isset( $user->user_login ) ? (string) $user->user_login : '';
		}
		AuditLog::log( 'system.user_deleted', 'warning', [
			'user_login'     => $login,
			'deleted_user_id' => $id,
			'reassigned_to'  => $reassign ? (int) $reassign : null,
		] );
	}

	public static function on_user_role_changed( int $user_id, string $new_role, $old_roles ): void {
		// set_user_role fires during user creation too (first role assigned).
		// Filter that case out - we already log user creation separately.
		if ( empty( $old_roles ) ) {
			return;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		$from = is_array( $old_roles ) && ! empty( $old_roles ) ? (string) $old_roles[0] : '';
		if ( $from === $new_role ) {
			return; // No-op role set.
		}
		AuditLog::log( 'system.user_role_changed', 'info', [
			'user_login' => $user->user_login,
			'target_id'  => $user_id,
			'from'       => $from,
			'to'         => $new_role,
		] );
	}

	// ─── Login handlers (m4.12.2) ─────────────────────────────────────────

	/**
	 * Successful login - queued for batch-write at shutdown. High-volume
	 * event, OK to lose on PHP fatal (not security-critical).
	 */
	public static function on_login( string $user_login, $user ): void {
		if (
			$user instanceof \WP_User
			&& \CB\Core\Security\TwoFactor\LoginFlow::is_password_stage_pending( (int) $user->ID )
		) {
			return;
		}

		self::queue_login_success( $user_login, $user );
	}

	/**
	 * A Base-owned 2FA login becomes a successful login only after the second
	 * factor has issued the final WordPress authentication session.
	 */
	public static function on_two_factor_authenticated( \WP_User $user, string $method ): void {
		unset( $method );
		self::queue_login_success( (string) $user->user_login, $user );
	}

	private static function queue_login_success( string $user_login, $user ): void {
		$role = '';
		if ( is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) && ! empty( $user->roles ) ) {
			$role = (string) $user->roles[0];
		}
		AuditLog::queue( 'system.login', 'info', [
			'user_login' => $user_login,
			'role'       => $role,
		] );
	}

	/**
	 * Failed login attempt - logged synchronously at warning severity.
	 * Bypasses dedup (warning+ always dedup-exempt) so repeated attempts
	 * from the same IP each leave a trail - that's the point.
	 */
	public static function on_login_failed( string $user_login ): void {
		AuditLog::log( 'system.login_failed', 'warning', [
			'user_login' => $user_login,
		] );
	}

	// ─── Option changes (m4.12.2) ─────────────────────────────────────────

	/**
	 * Allowlist of WordPress core options that are worth logging. Keeping
	 * this narrow is important - logging every option change would spam
	 * the audit log with transients, plugin settings, and CB-internal
	 * writes (which have their own logging).
	 */
	const WATCHED_OPTIONS = [
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'admin_email',
		'template',
		'stylesheet',
		'permalink_structure',
		'users_can_register',
		'default_role',
		'blog_public',
		'WPLANG',
	];

	/**
	 * Handler for updated_option. Only acts on allowlisted options - all
	 * others pass through silently so this hook doesn't drown the log in
	 * transient / plugin-internal writes.
	 *
	 * We don't record the actual values to avoid storing what might be
	 * sensitive (admin_email!). Only the option name + "changed" fact.
	 */
	public static function on_option_updated( string $option_name, $old_value, $new_value ): void {
		if ( ! in_array( $option_name, self::WATCHED_OPTIONS, true ) ) {
			return;
		}
		// Belt-and-braces - exclude any CB option that might somehow land
		// in the allowlist by accident. Core Blueprint logs its own settings
		// changes via settings.changed etc.
		if ( 0 === strpos( $option_name, 'cb_' ) || 0 === strpos( $option_name, '_transient_' ) ) {
			return;
		}

		AuditLog::log( 'system.option_changed', 'info', [
			'option_name' => $option_name,
		] );
	}

	// ─── Rendering helpers ────────────────────────────────────────────────

	/**
	 * Turn an event_type + context pair into a plain-language sentence.
	 * Used by the template when rendering rows. Missing placeholders
	 * are rendered as literal '-'.
	 */
	public static function describe( string $event_type, array $context ): string {
		$config   = self::event_type( $event_type );
		$template = $config['description'] ?? $event_type;
		// Translate the template via WP's i18n system. The template strings
		// are registered with xgettext below via _register_translatable_strings()
		// so translators can pick them up.
		$translated = ( did_action( 'init' ) > 0 || doing_action( 'init' ) )
			? __( $template, 'core-blueprint' )
			: $template;

		return preg_replace_callback(
			'/\{([a-z_]+)\}/i',
			static function ( $m ) use ( $context ) {
				$key = $m[1];
				$val = $context[ $key ] ?? null;
				if ( null === $val || '' === $val ) {
					return '-';
				}
				return (string) $val;
			},
			$translated
		);
	}

	/**
	 * Resolve a plugin slug (e.g. "core-blueprint/core-blueprint.php") into its
	 * human-readable name. Falls back to slug if the plugin header
	 * can't be read (e.g. plugin already deleted).
	 */
	private static function plugin_name( string $plugin_file ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( file_exists( $path ) ) {
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return (string) $data['Name'];
			}
		}
		// Fallback - strip the filename part: "core-blueprint/core-blueprint.php" → "core-blueprint"
		$parts = explode( '/', $plugin_file );
		return $parts[0] ?? $plugin_file;
	}

	private static function plugin_version( string $plugin_file ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( file_exists( $path ) ) {
			$data = get_plugin_data( $path, false, false );
			return (string) ( $data['Version'] ?? '' );
		}
		return '';
	}

	/**
	 * Register DESCRIPTIONS template strings with xgettext.
	 *
	 * @internal This method is never called at runtime. It exists purely
	 * so xgettext's static-analysis parser can discover every template
	 * string and include it in the POT file for translation. The actual
	 * translation happens in describe() via __( $template, 'core-blueprint' )
	 * at render time - that call uses a variable as first arg, which
	 * xgettext cannot extract.
	 *
	 * Keep this in sync with DESCRIPTIONS whenever a new event is added.
	 */
	private static function _register_translatable_strings(): void {
		__( 'Plugin activated: {plugin}',          'core-blueprint' );
		__( 'Plugin deactivated: {plugin}',        'core-blueprint' );
		__( 'Plugin installed: {plugin}',          'core-blueprint' );
		__( 'Plugin updated: {plugin}',            'core-blueprint' );
		__( 'Plugin deleted: {plugin}',            'core-blueprint' );
		__( 'Theme switched from {from} to {to}',  'core-blueprint' );
		__( 'Theme installed: {theme}',            'core-blueprint' );
		__( 'Theme updated: {theme}',              'core-blueprint' );
		__( 'Theme deleted: {theme}',              'core-blueprint' );
		__( 'WordPress core updated to {version}', 'core-blueprint' );
		__( 'User created: {user_login}',          'core-blueprint' );
		__( 'User deleted: {user_login}',          'core-blueprint' );
		__( 'Role changed for {user_login}: {from} → {to}', 'core-blueprint' );
	}
}
