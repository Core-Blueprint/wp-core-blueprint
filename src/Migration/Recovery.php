<?php
declare(strict_types=1);
/**
 * Migration Recovery
 *
 * Destination-owned recovery authority for governed cross-site migrations.
 * Tickets are stateless and HMAC-bound to the destination auth salt so they
 * survive a database replacement without transferring source-site trust.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Migration;

use CB\Core\Log\AuditLog;
use CB\Core\Settings;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessPolicy;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\Roles;
use CB\Core\Permissions\TrustSchemaMigrator;
use CB\Core\Security\Failsafe;
use CB\Core\Security\LoginShield;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\EnrollmentStore;
use CB\Core\Security\TwoFactor\Policy as TwoFactorPolicy;
use RuntimeException;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class Recovery {
	public const API_VERSION = '1.0';
	public const PARAM = 'cb_core_migration_recovery';

	private const OPTION = 'cb_core_migration_recovery_state';
	private const PURPOSE = 'core-blueprint-site-migration';
	private const TICKET_VERSION = 1;
	private const DEFAULT_TTL = 43200;
	private const MIN_TTL = 300;
	private const MAX_TTL = 86400;

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'cb_core_failsafe_is_bypassed', [ self::class, 'filter_failsafe_bypass' ], 10, 1 );
		add_action( 'init', [ self::class, 'maybe_reconcile_login_request' ], -100 );
		add_action( 'login_form', [ self::class, 'render_login_ticket_field' ] );
		add_filter( 'wp_authenticate_user', [ self::class, 'require_management_identity' ], 100, 2 );
		add_action( 'wp_login', [ self::class, 'complete_authenticated_login' ], 100, 2 );
		add_filter( 'login_message', [ self::class, 'login_message' ] );
	}

	public static function api_version(): string {
		return self::API_VERSION;
	}

	/**
	 * Issue a stateless destination-bound recovery ticket before destructive
	 * migration starts.
	 *
	 * @return array{ticket:string,recovery_id:string,issued_at:int,expires_at:int,target_site_url:string}
	 */
	public static function issue_ticket( string $recovery_id, string $target_site_url, int $ttl = self::DEFAULT_TTL ): array {
		if ( get_current_user_id() < 1 || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'cb_manage_permissions' ) ) ) {
			throw new RuntimeException( 'Migration recovery ticket issuance requires governed site-management authority.' );
		}

		$recovery_id = sanitize_key( $recovery_id );
		if ( '' === $recovery_id ) {
			throw new RuntimeException( 'Migration recovery ID is required.' );
		}

		$target = self::normalize_site_url( $target_site_url );
		$current = self::normalize_site_url( (string) get_option( 'siteurl', '' ) );
		if ( '' === $target || '' === $current || ! hash_equals( $current, $target ) ) {
			throw new RuntimeException( 'Migration recovery target does not match the current WordPress installation.' );
		}

		$ttl = max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );
		$issued_at = time();
		$payload = [
			'v'          => self::TICKET_VERSION,
			'purpose'    => self::PURPOSE,
			'recovery'   => $recovery_id,
			'target'     => $target,
			'issued_at'  => $issued_at,
			'expires_at' => $issued_at + $ttl,
			'nonce'      => bin2hex( random_bytes( 16 ) ),
			'actor'      => get_current_user_id(),
		];

		$encoded = self::base64url_encode( (string) wp_json_encode( $payload ) );
		$signature = self::sign( $encoded );
		if ( '' === $signature ) {
			throw new RuntimeException( 'Migration recovery signing key is unavailable.' );
		}
		$ticket = $encoded . '.' . $signature;

		AuditLog::log( 'migration.recovery.ticket_issued', 'warning', [
			'recovery_id' => $recovery_id,
			'actor'       => get_current_user_id(),
			'expires_at'  => $payload['expires_at'],
			'target'      => $target,
		] );

		return [
			'ticket'          => $ticket,
			'recovery_id'     => $recovery_id,
			'issued_at'       => $issued_at,
			'expires_at'      => (int) $payload['expires_at'],
			'target_site_url' => $target,
		];
	}

	/**
	 * Activate recovery immediately after the migrated database becomes live.
	 *
	 * This phase deliberately avoids WordPress role/option caches because the
	 * caller can still be running in the pre-migration PHP request.
	 *
	 * @return array<string,mixed>
	 */
	public static function activate_destination( string $ticket ): array {
		$payload = self::validate_ticket( $ticket, false );

		// The database may already have been atomically replaced in this request.
		// Never trust pre-switch WordPress option/object caches at this boundary.
		$existing = self::database_state();
		if ( $existing ) {
			if ( self::state_matches_ticket( $existing, $ticket, $payload ) ) {
				return $existing;
			}
			throw new RuntimeException( 'Another migration recovery context is already active.' );
		}

		$current = self::normalize_site_url( self::database_option( 'siteurl' ) );
		if ( '' === $current || ! hash_equals( $current, (string) $payload['target'] ) ) {
			throw new RuntimeException( 'Migration recovery ticket does not match the restored destination database.' );
		}

		$role_schema = (int) self::database_option( 'cb_core_role_policy_schema_version' );
		if ( $role_schema > RolePolicySchema::current_schema() ) {
			throw new RuntimeException( 'Imported Core Blueprint Role Policy is newer than the destination Base runtime.' );
		}
		$trust_schema = (int) self::database_option( 'cb_core_trust_schema_version' );
		if ( $trust_schema > TrustSchemaMigrator::current_schema() ) {
			throw new RuntimeException( 'Imported Core Blueprint Trust Schema is newer than the destination Base runtime.' );
		}

		$state = [
			'version'          => self::TICKET_VERSION,
			'recovery_id'      => (string) $payload['recovery'],
			'ticket_hash'      => hash( 'sha256', $ticket ),
			'target_site_url'  => (string) $payload['target'],
			'issued_at'        => (int) $payload['issued_at'],
			'expires_at'       => (int) $payload['expires_at'],
			'status'           => 'pending_reconcile',
			'reviewed_users'   => 0,
			'approved_user_id' => 0,
			'authenticated_at' => 0,
		];
		self::clear_state_option_cache();
		update_option( self::OPTION, $state, false );

		AuditLog::log( 'migration.recovery.destination_activated', 'warning', [
			'recovery_id' => $state['recovery_id'],
			'target'      => $state['target_site_url'],
		] );

		return $state;
	}

	/**
	 * Establish the destination trust domain after the migrated database is
	 * live. Imported privileged approvals are never trusted on the destination.
	 *
	 * @return array<string,mixed>
	 */
	public static function reconcile_destination( string $ticket ): array {
		$payload = self::validate_ticket( $ticket );
		$existing = self::state();
		if ( ! $existing ) {
			$existing = self::activate_destination( $ticket );
		}
		if ( ! self::state_matches_ticket( $existing, $ticket, $payload ) ) {
			throw new RuntimeException( 'Another migration recovery context is already active.' );
		}
		if ( in_array( (string) ( $existing['status'] ?? '' ), [ 'pending_auth', 'authenticated' ], true ) ) {
			return $existing;
		}
		if ( 'pending_reconcile' !== (string) ( $existing['status'] ?? '' ) ) {
			throw new RuntimeException( 'Migration recovery context is not available for reconciliation.' );
		}

		$before = RolePolicySchema::inspect( false, 'site_migration_preflight' );
		if ( in_array( 'schema_newer_than_runtime', (array) ( $before['issues'] ?? [] ), true ) ) {
			throw new RuntimeException( 'Imported Core Blueprint Role Policy is newer than the destination Base runtime.' );
		}
		if ( TrustSchemaMigrator::stored_schema() > TrustSchemaMigrator::current_schema() ) {
			throw new RuntimeException( 'Imported Core Blueprint Trust Schema is newer than the destination Base runtime.' );
		}

		PrivilegedAccessGuard::establish_migration_trust_root();

		$reviewed_ids = [];
		foreach ( get_users() as $user ) {
			if ( ! ( $user instanceof WP_User ) || ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
				continue;
			}
			PrivilegedAccessRegistry::require_review( $user, 'site_migration', 'migration_recovery' );
			CredentialStore::clear( (int) $user->ID );
			EnrollmentStore::clear( (int) $user->ID );
			$reviewed_ids[ (int) $user->ID ] = true;
		}

		$role_policy = RolePolicySchema::repair();
		if ( empty( $role_policy['canonical'] ) ) {
			throw new RuntimeException( 'Core Blueprint Role Policy could not be reconciled for the migration destination.' );
		}

		// Role Policy repair can legitimately change the privilege fingerprint.
		// Re-sign the review state, never the approval, against the canonical
		// destination policy after the repair is complete.
		foreach ( get_users() as $user ) {
			if ( ! ( $user instanceof WP_User ) || ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
				continue;
			}
			PrivilegedAccessRegistry::require_review( $user, 'site_migration', 'migration_recovery' );
			$reviewed_ids[ (int) $user->ID ] = true;
		}
		$reviewed = count( $reviewed_ids );

		TrustSchemaMigrator::reset_for_new_trust_domain( 'site_migration' );

		$state = $existing;
		$state['status'] = 'pending_auth';
		$state['reviewed_users'] = $reviewed;
		$state['reconciled_at'] = time();
		update_option( self::OPTION, $state, false );

		AuditLog::log( 'migration.recovery.destination_reconciled', 'warning', [
			'recovery_id'    => $state['recovery_id'],
			'reviewed_users' => $reviewed,
			'role_policy'    => [
				'changed'   => ! empty( $role_policy['changed'] ),
				'canonical' => ! empty( $role_policy['canonical'] ),
			],
		] );

		return $state;
	}

	public static function requires_pretty_routing(): bool {
		if ( '' !== trim( (string) get_option( 'permalink_structure', '' ) ) ) {
			return true;
		}

		if ( class_exists( Settings::class ) && class_exists( LoginShield::class ) && Settings::shield_enabled() ) {
			$config = LoginShield::config();
			return ! empty( $config['enabled'] ) && '' !== (string) ( $config['slug'] ?? '' );
		}

		return false;
	}

	public static function login_url( string $ticket, string $redirect_to = '' ): string {
		$payload = self::validate_ticket( $ticket, false );
		$base = untrailingslashit( (string) $payload['target'] ) . '/wp-login.php';
		$args = [ self::PARAM => $ticket ];
		if ( '' !== $redirect_to ) {
			$args['redirect_to'] = $redirect_to;
		}
		return add_query_arg( $args, $base );
	}

	/** @return array<string,mixed> */
	public static function status( string $ticket ): array {
		$payload = self::validate_ticket( $ticket, false );
		$state = self::state();
		if ( ! $state || ! self::state_matches_ticket( $state, $ticket, $payload ) ) {
			return [];
		}
		return $state;
	}

	public static function finalize( string $ticket ): bool {
		$payload = self::validate_ticket( $ticket, false );
		$state = self::state();
		if ( ! $state || ! self::state_matches_ticket( $state, $ticket, $payload ) ) {
			return false;
		}
		if ( 'pending_two_factor' === (string) ( $state['status'] ?? '' ) ) {
			$user_id = get_current_user_id();
			$user = $user_id > 0 ? get_userdata( $user_id ) : false;
			if ( ! ( $user instanceof WP_User ) || $user_id !== (int) ( $state['approved_user_id'] ?? 0 ) || ! CredentialStore::is_enrolled( $user_id ) ) {
				return false;
			}
			if ( ! PrivilegedAccessRegistry::approve( $user, 0, 'migration_recovery' ) ) {
				return false;
			}
			$state['status'] = 'authenticated';
			update_option( self::OPTION, $state, false );
		}
		if ( 'authenticated' !== (string) ( $state['status'] ?? '' ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 || $user_id !== (int) ( $state['approved_user_id'] ?? 0 ) ) {
			return false;
		}

		delete_option( self::OPTION );
		AuditLog::log( 'migration.recovery.completed', 'notice', [
			'recovery_id' => (string) $state['recovery_id'],
			'user_id'     => $user_id,
		] );
		return true;
	}

	public static function maybe_reconcile_login_request(): void {
		if ( ! self::is_login_request() ) {
			return;
		}
		$ticket = self::request_ticket();
		$state = self::state();
		if ( '' === $ticket || ! $state || 'pending_reconcile' !== (string) ( $state['status'] ?? '' ) ) {
			return;
		}

		try {
			$payload = self::validate_ticket( $ticket );
			if ( self::state_matches_ticket( $state, $ticket, $payload ) ) {
				self::reconcile_destination( $ticket );
			}
		} catch ( \Throwable $e ) {
			$state['status'] = 'failed';
			$state['error'] = sanitize_text_field( $e->getMessage() );
			update_option( self::OPTION, $state, false );
			AuditLog::log( 'migration.recovery.reconcile_failed', 'critical', [
				'recovery_id' => (string) ( $state['recovery_id'] ?? '' ),
				'error'       => $e->getMessage(),
			] );
		}
	}

	public static function filter_failsafe_bypass( bool $bypassed ): bool {
		if ( $bypassed ) {
			return true;
		}

		// Keep the global Failsafe capability path zero-query unless this request
		// can actually participate in migration recovery. Recovery never opens
		// arbitrary frontend requests or a normal wp-login.php request.
		$is_login = self::is_login_request();
		$is_admin = self::is_admin_request();
		if ( ! $is_login && ! $is_admin ) {
			return false;
		}

		$ticket = '';
		if ( $is_login ) {
			$ticket = self::request_ticket();
			if ( '' === $ticket ) {
				return false;
			}
		}

		$state = self::state();
		if ( ! $state || self::state_expired( $state ) ) {
			return false;
		}

		if ( $is_login ) {
			try {
				$payload = self::validate_ticket( $ticket, false );
			} catch ( \Throwable ) {
				return false;
			}
			return in_array( (string) ( $state['status'] ?? '' ), [ 'pending_reconcile', 'pending_auth', 'pending_two_factor', 'authenticated' ], true )
				&& self::state_matches_ticket( $state, $ticket, $payload );
		}

		if ( self::is_admin_request() && 'authenticated' === (string) ( $state['status'] ?? '' ) ) {
			$user_id = get_current_user_id();
			return $user_id > 0 && $user_id === (int) ( $state['approved_user_id'] ?? 0 );
		}

		return false;
	}

	public static function render_login_ticket_field(): void {
		$ticket = self::request_ticket();
		if ( '' === $ticket || ! self::ticket_is_active( $ticket ) ) {
			return;
		}
		printf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( self::PARAM ),
			esc_attr( $ticket )
		);
	}

	public static function require_management_identity( WP_User|WP_Error $user, string $password ): WP_User|WP_Error {
		unset( $password );
		$ticket = self::request_ticket();
		if ( '' === $ticket || ! self::ticket_is_active( $ticket ) || $user instanceof WP_Error ) {
			return $user;
		}
		if ( self::is_management_identity( $user ) ) {
			return $user;
		}
		return new WP_Error( 'cb_core_migration_recovery_identity', __( 'Sorry, you are not allowed to access this page.' ) );
	}

	public static function complete_authenticated_login( string $user_login, WP_User $user ): void {
		unset( $user_login );
		$ticket = self::request_ticket();
		if ( '' === $ticket || ! self::ticket_is_active( $ticket ) || ! self::is_management_identity( $user ) ) {
			return;
		}
		$state = self::state();
		if ( 'pending_auth' !== (string) ( $state['status'] ?? '' ) ) {
			return;
		}

		if ( TwoFactorPolicy::requires_enrollment( $user ) && ! CredentialStore::is_enrolled( (int) $user->ID ) ) {
			$state['status'] = 'pending_two_factor';
			$state['approved_user_id'] = (int) $user->ID;
			$state['authenticated_at'] = time();
			update_option( self::OPTION, $state, false );
			return;
		}

		if ( ! PrivilegedAccessRegistry::approve( $user, 0, 'migration_recovery' ) ) {
			return;
		}

		$state = self::state();
		if ( ! $state ) {
			return;
		}
		$state['status'] = 'authenticated';
		$state['approved_user_id'] = (int) $user->ID;
		$state['authenticated_at'] = time();
		update_option( self::OPTION, $state, false );

		AuditLog::log( 'migration.recovery.identity_approved', 'warning', [
			'recovery_id' => (string) $state['recovery_id'],
			'user_id'     => (int) $user->ID,
			'user_login'  => (string) $user->user_login,
		] );
	}

	public static function login_message( string $message ): string {
		$ticket = self::request_ticket();
		if ( '' === $ticket || ! self::ticket_is_active( $ticket ) ) {
			return $message;
		}
		$notice = '<p class="message">' . esc_html__(
			'Core Blueprint migration recovery is active. Sign in with an Administrator or CB Operator account from the migrated website to continue.',
			'core-blueprint'
		) . '</p>';
		return $notice . $message;
	}

	private static function ticket_is_active( string $ticket ): bool {
		try {
			$payload = self::validate_ticket( $ticket, false );
		} catch ( \Throwable ) {
			return false;
		}
		$state = self::state();
		return $state
			&& in_array( (string) ( $state['status'] ?? '' ), [ 'pending_reconcile', 'pending_auth', 'authenticated' ], true )
			&& self::state_matches_ticket( $state, $ticket, $payload );
	}

	/** @return array<string,mixed> */
	private static function validate_ticket( string $ticket, bool $require_current_target = true ): array {
		$parts = explode( '.', $ticket, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || ! preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) {
			throw new RuntimeException( 'Migration recovery ticket is malformed.' );
		}
		$expected = self::sign( $parts[0] );
		if ( '' === $expected || ! hash_equals( $expected, $parts[1] ) ) {
			throw new RuntimeException( 'Migration recovery ticket signature is invalid.' );
		}

		$json = self::base64url_decode( $parts[0] );
		$payload = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $payload )
			|| self::TICKET_VERSION !== (int) ( $payload['v'] ?? 0 )
			|| self::PURPOSE !== (string) ( $payload['purpose'] ?? '' )
			|| '' === sanitize_key( (string) ( $payload['recovery'] ?? '' ) )
			|| '' === (string) ( $payload['target'] ?? '' )
		) {
			throw new RuntimeException( 'Migration recovery ticket payload is invalid.' );
		}
		if ( time() > (int) ( $payload['expires_at'] ?? 0 ) ) {
			throw new RuntimeException( 'Migration recovery ticket has expired.' );
		}
		if ( $require_current_target ) {
			$current = self::normalize_site_url( (string) get_option( 'siteurl', '' ) );
			if ( '' === $current || ! hash_equals( $current, (string) $payload['target'] ) ) {
				throw new RuntimeException( 'Migration recovery ticket belongs to another WordPress destination.' );
			}
		}
		return $payload;
	}

	/** @return array<string,mixed> */
	/**
	 * Read recovery state directly from the live options table.
	 *
	 * Used only at the atomic migration switch where the request can still
	 * carry option/object-cache values from the database that was just replaced.
	 *
	 * @return array<string,mixed>
	 */
	private static function database_state(): array {
		$raw = self::database_option( self::OPTION );
		if ( '' === $raw ) {
			return [];
		}
		$state = maybe_unserialize( $raw );
		if ( ! is_array( $state ) || self::state_expired( $state ) ) {
			return [];
		}
		return $state;
	}

	private static function clear_state_option_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function state(): array {
		$state = get_option( self::OPTION, [] );
		if ( ! is_array( $state ) ) {
			return [];
		}
		if ( self::state_expired( $state ) ) {
			delete_option( self::OPTION );
			return [];
		}
		return $state;
	}

	private static function state_expired( array $state ): bool {
		return time() > (int) ( $state['expires_at'] ?? 0 );
	}

	private static function state_matches_ticket( array $state, string $ticket, array $payload ): bool {
		$hash = (string) ( $state['ticket_hash'] ?? '' );
		return 64 === strlen( $hash )
			&& hash_equals( $hash, hash( 'sha256', $ticket ) )
			&& hash_equals( (string) ( $state['recovery_id'] ?? '' ), (string) $payload['recovery'] )
			&& hash_equals( (string) ( $state['target_site_url'] ?? '' ), (string) $payload['target'] );
	}

	private static function request_ticket(): string {
		$raw = $_REQUEST[ self::PARAM ] ?? '';
		return is_scalar( $raw ) ? sanitize_text_field( wp_unslash( (string) $raw ) ) : '';
	}

	private static function is_management_identity( WP_User $user ): bool {
		$roles = array_values( array_map( 'strval', (array) $user->roles ) );
		return in_array( 'administrator', $roles, true ) || in_array( Roles::OPERATOR_ROLE, $roles, true );
	}

	private static function is_login_request(): bool {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		return 'wp-login.php' === $script;
	}

	private static function is_admin_request(): bool {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
		return false !== strpos( wp_normalize_path( $script ), '/wp-admin/' );
	}

	private static function database_option( string $name ): string {
		global $wpdb;
		$table = (string) $wpdb->options;
		if ( '' === $table ) {
			return '';
		}

		$sql = $wpdb->prepare(
			'SELECT option_value FROM ' . $table . ' WHERE option_name = %s LIMIT 1',
			$name
		);
		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function normalize_site_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? '/' . trim( (string) $parts['path'], '/' ) : '';
		if ( '/' === $path ) {
			$path = '';
		}
		return $scheme . '://' . $host . $port . $path;
	}

	private static function sign( string $encoded_payload ): string {
		$key = wp_salt( 'auth' );
		return '' !== $key ? hash_hmac( 'sha256', $encoded_payload, $key ) : '';
	}

	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $value ): string|false {
		$padding = strlen( $value ) % 4;
		if ( $padding > 0 ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}
}
