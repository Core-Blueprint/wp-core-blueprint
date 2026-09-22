<?php
declare(strict_types=1);
/**
 * PrivilegedAccessGuard
 *
 * Approval boundary for administrator and administrator-like users. Unapproved
 * privileged identities are always detected and recorded for review. In the
 * recommended enforce mode their effective capabilities are reduced to a
 * minimal read-only account until a trusted CB Operator approves the exact
 * current privilege fingerprint; monitor mode keeps detection/review active
 * without restricting the WordPress capabilities.
 *
 * Normal WordPress mutations are reconciled immediately. A runtime
 * user_has_cap gate independently protects against direct database writes that
 * bypass WordPress hooks. Current-user reconciliation runs on `init` before
 * wp-admin authorization/redirect handling so a fail-closed runtime block is
 * persisted to pending review immediately. A dedicated ten-minute WP-Cron sweep
 * detects inactive identities changed outside WordPress APIs; admin traffic
 * retains the same sweep as an additional fallback.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Permissions;

use CB\Core\Log\AuditLog;
use CB\Core\Security\Failsafe;

defined( 'ABSPATH' ) || exit;

final class PrivilegedAccessGuard {

	private const BOOTSTRAP_OPTION         = 'cb_core_privileged_guard_bootstrapped';
	private const SWEEP_TRANSIENT = 'cb_core_privileged_guard_sweep';
	private const SWEEP_INTERVAL  = 10 * MINUTE_IN_SECONDS;
	private const CRON_HOOK       = 'cb_core_privileged_guard_cron_sweep';
	private const CRON_SCHEDULE   = 'cb_core_every_ten_minutes';

	/** Minimal capabilities retained while Enforce approval restricts a user. */
	private const RESTRICTED_ALLOW = [ 'read', 'exist', 'level_0' ];

	/** Core Blueprint trust-authority capabilities never available to an unapproved identity. */
	private const TRUST_AUTHORITY_CAPS = [
		'cb_manage_permissions',
		'cb_manage_roles',
		'cb_manage_integrity_policy',
		'cb_manage_snippets',
		'cb_use_cli',
	];

	private static bool $bootstrapped = false;
	private static bool $reconciling  = false;
	private static int $suspended     = 0;

	public static function init(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		add_filter( 'user_has_cap', [ __CLASS__, 'filter_user_has_cap' ], 1, 4 );
		add_filter( 'user_has_cap', [ __CLASS__, 'enforce_trust_authority_boundary' ], PHP_INT_MAX, 4 );
		add_action( 'plugins_loaded', [ __CLASS__, 'bootstrap_trust_root' ], 10 );
		add_action( 'user_register',    [ __CLASS__, 'on_user_register' ], 100, 1 );
		add_action( 'set_user_role',    [ __CLASS__, 'on_set_user_role' ], 100, 3 );
		add_action( 'add_user_role',    [ __CLASS__, 'on_add_user_role' ], 100, 2 );
		add_action( 'remove_user_role', [ __CLASS__, 'on_remove_user_role' ], 100, 2 );
		add_action( 'added_user_meta',   [ __CLASS__, 'on_user_meta_changed' ], 100, 4 );
		add_action( 'updated_user_meta', [ __CLASS__, 'on_user_meta_changed' ], 100, 4 );
		add_action( 'deleted_user_meta', [ __CLASS__, 'on_user_meta_changed' ], 100, 4 );
		add_action( 'updated_option',    [ __CLASS__, 'on_option_changed' ], 100, 3 );
		add_action( 'wp_login',   [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'init',       [ __CLASS__, 'reconcile_current_user' ], 1 );
		add_filter( 'cron_schedules', [ __CLASS__, 'register_cron_schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_sweep' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'ensure_schedule' ], 11 );
		add_action( 'admin_init', [ __CLASS__, 'periodic_sweep' ], 20 );
		add_action( 'admin_notices', [ __CLASS__, 'review_notice' ] );
	}

	/**
	 * Runtime fail-closed capability gate.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array              $args
	 * @param \WP_User           $user
	 * @return array<string,bool>
	 */
	public static function filter_user_has_cap( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! get_option( self::BOOTSTRAP_OPTION, false ) ) {
			return $allcaps;
		}
		if ( ! ( $user instanceof \WP_User ) || ! $user->ID ) {
			return $allcaps;
		}
		if ( class_exists( Failsafe::class ) && Failsafe::is_bypassed() ) {
			return $allcaps;
		}
		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
			return $allcaps;
		}
		if ( PrivilegedAccessRegistry::is_approved( $user ) ) {
			return $allcaps;
		}

		if ( ! PrivilegedAccessPolicy::enforces_approval() ) {
			foreach ( self::TRUST_AUTHORITY_CAPS as $cap ) {
				if ( array_key_exists( $cap, $allcaps ) ) {
					$allcaps[ $cap ] = false;
				}
			}
			return $allcaps;
		}

		foreach ( array_keys( $allcaps ) as $cap ) {
			if ( in_array( (string) $cap, self::RESTRICTED_ALLOW, true ) ) {
				continue;
			}
			$allcaps[ $cap ] = false;
		}

		return $allcaps;
	}

	/**
	 * Final trust-authority gate.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array              $args
	 * @param \WP_User           $user
	 * @return array<string,bool>
	 */
	public static function enforce_trust_authority_boundary( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! get_option( self::BOOTSTRAP_OPTION, false ) ) {
			return $allcaps;
		}
		if ( ! ( $user instanceof \WP_User ) || ! $user->ID ) {
			return $allcaps;
		}
		if ( class_exists( Failsafe::class ) && Failsafe::is_bypassed() ) {
			return $allcaps;
		}
		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) || PrivilegedAccessRegistry::is_approved( $user ) ) {
			return $allcaps;
		}

		foreach ( self::TRUST_AUTHORITY_CAPS as $cap ) {
			$allcaps[ $cap ] = false;
		}

		return $allcaps;
	}

	/** Repair a missing persistent guard marker without creating trust. */
	public static function bootstrap_trust_root(): void {
		if ( get_option( self::BOOTSTRAP_OPTION, false ) ) {
			return;
		}
		update_option( self::BOOTSTRAP_OPTION, time(), false );
		$review_required = self::reconcile_all( 'guard_marker_recovered', 'fail_closed_recovery' );
		AuditLog::log( 'permissions.privileged_guard_bootstrapped', 'critical', [
			'mode'            => 'fail_closed_recovery',
			'review_required' => $review_required,
		] );
	}

	/**
	 * Establish the guard marker for a newly created migration trust domain.
	 *
	 * This does not approve users and does not infer trust from imported state.
	 * Migration Recovery force-reviews privileged identities separately after
	 * canonical role reconciliation.
	 */
	public static function establish_migration_trust_root(): void {
		update_option( self::BOOTSTRAP_OPTION, time(), false );
		AuditLog::log( 'permissions.privileged_guard_migration_root_established', 'warning', [
			'source' => 'site_migration',
		] );
	}

	/** Complete first-install trust-root bootstrap. */
	public static function complete_first_activation(): int {
		update_option( self::BOOTSTRAP_OPTION, time(), false );
		return self::reconcile_all( 'guard_bootstrap', 'first_activation' );
	}

	public static function on_user_register( $user_id ): void {
		self::reconcile_user( (int) $user_id, 'privileged_account_created', 'user_register' );
	}

	public static function on_set_user_role( $user_id, $role, $old_roles ): void {
		self::reconcile_user( (int) $user_id, 'privilege_state_changed', 'set_user_role' );
	}

	public static function on_add_user_role( $user_id, $role ): void {
		self::reconcile_user( (int) $user_id, 'privilege_state_changed', 'add_user_role' );
	}

	public static function on_remove_user_role( $user_id, $role ): void {
		self::reconcile_user( (int) $user_id, 'privilege_state_changed', 'remove_user_role' );
	}

	public static function on_user_meta_changed( $meta_id, $user_id, $meta_key, $meta_value ): void {
		global $wpdb;
		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
		if ( (string) $meta_key !== $cap_key ) {
			return;
		}
		self::reconcile_user( (int) $user_id, 'privilege_state_changed', 'capabilities_meta' );
	}

	public static function on_option_changed( $option, $old_value, $value ): void {
		$wp_roles = wp_roles();
		if ( (string) $option !== (string) $wp_roles->role_key ) {
			return;
		}
		self::reconcile_all( 'role_definition_changed', 'role_option' );
	}

	public static function on_login( string $user_login, $user ): void {
		if ( $user instanceof \WP_User ) {
			self::reconcile_user( (int) $user->ID, 'runtime_reconciliation', 'wp_login' );
		}
	}

	public static function reconcile_current_user(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			self::reconcile_user( $user_id, 'runtime_reconciliation', 'init' );
		}
	}

	/** @param array<string,array{interval:int,display:string}> $schedules */
	public static function register_cron_schedule( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = [
			'interval' => self::SWEEP_INTERVAL,
			'display'  => 'Core Blueprint privileged access guard (10 minutes)',
		];
		return $schedules;
	}

	public static function ensure_schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + self::SWEEP_INTERVAL, self::CRON_SCHEDULE, self::CRON_HOOK );
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function cron_sweep(): void {
		self::reconcile_all( 'runtime_reconciliation', 'cron_sweep' );
		RolePolicySchema::inspect( true, 'cron_sweep' );
	}

	public static function periodic_sweep(): void {
		if ( get_transient( self::SWEEP_TRANSIENT ) ) {
			return;
		}
		set_transient( self::SWEEP_TRANSIENT, 1, self::SWEEP_INTERVAL );
		self::reconcile_all( 'runtime_reconciliation', 'periodic_sweep' );
		RolePolicySchema::inspect( true, 'periodic_sweep' );
	}

	/** Whether this exact current identity is a signed, approved CB Operator. */
	public static function is_trusted_operator( \WP_User $user ): bool {
		return in_array( Roles::OPERATOR_ROLE, (array) $user->roles, true )
			&& PrivilegedAccessRegistry::is_approved( $user );
	}

	/** Run a known-trusted role mutation; caller must approve/reconcile final state. */
	public static function trusted_mutation( callable $callback ) {
		self::$suspended++;
		try {
			return $callback();
		} finally {
			self::$suspended = max( 0, self::$suspended - 1 );
		}
	}

	/** Reconcile one identity against the signed approval registry. */
	public static function reconcile_user( int $user_id, string $reason, string $source ): bool {
		if ( self::$suspended > 0 || self::$reconciling || $user_id <= 0 || ! get_option( self::BOOTSTRAP_OPTION, false ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) ) {
			return false;
		}
		self::$reconciling = true;
		try {
			if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
				PrivilegedAccessRegistry::clear( $user );
				return false;
			}
			if ( PrivilegedAccessRegistry::is_approved( $user ) ) {
				return false;
			}
			return PrivilegedAccessRegistry::flag_for_review( $user, $reason, $source );
		} finally {
			self::$reconciling = false;
		}
	}

	/** @return int number of identities newly/updated into pending review */
	public static function reconcile_all( string $reason, string $source ): int {
		if ( self::$reconciling || ! get_option( self::BOOTSTRAP_OPTION, false ) ) {
			return 0;
		}
		$count = 0;
		foreach ( get_users() as $user ) {
			if ( $user instanceof \WP_User && self::reconcile_user( (int) $user->ID, $reason, $source ) ) {
				$count++;
			}
		}
		return $count;
	}

	/** Explain pending review without exposing browser self-approval. */
	public static function review_notice(): void {
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || ! $user->ID ) {
			return;
		}
		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) || PrivilegedAccessRegistry::is_approved( $user ) ) {
			return;
		}

		$approved_operators = PrivilegedAccessRegistry::approved_operator_count();
		if ( 0 === $approved_operators ) {
			echo '<div class="notice notice-error"><p><strong>';
			esc_html_e( 'Core Blueprint: privileged access recovery required.', 'core-blueprint' );
			echo '</strong> ';
			if ( PrivilegedAccessPolicy::enforces_approval() ) {
				esc_html_e( 'This account is restricted and no approved CB Operator is currently available to approve it.', 'core-blueprint' );
			} else {
				esc_html_e( 'No approved CB Operator is currently available to review this privileged identity.', 'core-blueprint' );
			}
			echo ' ';
			echo esc_html( sprintf(
				/* translators: %d: WordPress user ID */
				__( 'Use trusted server-side WP-CLI to verify and recover a known management identity: `wp cb operator status %d`, then `wp cb operator recover %d`.', 'core-blueprint' ),
				(int) $user->ID,
				(int) $user->ID
			) );
			echo '</p></div>';
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		esc_html_e( 'Core Blueprint: privileged access requires review.', 'core-blueprint' );
		echo '</strong> ';
		if ( PrivilegedAccessPolicy::enforces_approval() ) {
			esc_html_e( 'This account’s administrator-level capabilities are temporarily restricted until an approved CB Operator approves its current privilege state.', 'core-blueprint' );
		} else {
			esc_html_e( 'Monitor only is active, so this account keeps its existing WordPress permissions while an approved CB Operator reviews its current privilege state. Core Blueprint trust-authority controls remain unavailable until approval.', 'core-blueprint' );
		}
		echo '</p></div>';
	}
}
