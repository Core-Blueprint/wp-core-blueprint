<?php
declare(strict_types=1);
/**
 * Admin transport for URL Governance.
 *
 * Activation is intentionally a two-step process: a user runs a preflight,
 * then explicitly acknowledges and enables a matching preflight snapshot.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Routing;

use CoreBlueprint\Core\Admin\MutationAcknowledgement;
use CoreBlueprint\Core\Admin\Pages\Preferences;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PREFLIGHT_ACTION = 'cb_core_routing_preflight';
	public const ENABLE_ACTION    = 'cb_core_routing_enable';
	public const DISABLE_ACTION   = 'cb_core_routing_disable';

	public const PREFLIGHT_NONCE = 'cb_core_routing_preflight';
	public const ENABLE_NONCE    = 'cb_core_routing_enable';
	public const DISABLE_NONCE   = 'cb_core_routing_disable';

	private const ACK_FIELD     = 'cb_core_routing_acknowledge';
	private const TRANSIENT_TTL = 1800;

	public static function boot(): void {
		add_action( 'admin_post_' . self::PREFLIGHT_ACTION, [ self::class, 'run_preflight' ] );
		add_action( 'admin_post_' . self::ENABLE_ACTION, [ self::class, 'enable' ] );
		add_action( 'admin_post_' . self::DISABLE_ACTION, [ self::class, 'disable' ] );
	}

	public static function acknowledgement_field(): string {
		return self::ACK_FIELD;
	}

	/** @return array<string,mixed>|null */
	public static function stored_preflight(): ?array {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return null;
		}

		$value = get_transient( self::transient_key( $user_id ) );
		if ( ! is_array( $value ) || ! isset( $value['fingerprint'], $value['ready'] ) ) {
			return null;
		}

		return $value;
	}

	public static function run_preflight(): void {
		self::authorize( self::PREFLIGHT_NONCE );

		$result = Preflight::run();
		set_transient(
			self::transient_key( get_current_user_id() ),
			$result,
			self::TRANSIENT_TTL
		);

		self::redirect( ! empty( $result['ready'] ) ? 'preflight-ready' : 'preflight-blocked' );
	}

	public static function enable(): void {
		self::authorize( self::ENABLE_NONCE );

		$ack = isset( $_POST[ self::ACK_FIELD ] )
			? sanitize_key( wp_unslash( (string) $_POST[ self::ACK_FIELD ] ) )
			: '';

		if ( ! MutationAcknowledgement::confirmed( $ack ) ) {
			self::redirect( 'ack-required' );
		}

		$stored = self::stored_preflight();
		$posted = isset( $_POST['preflight_fingerprint'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['preflight_fingerprint'] ) )
			: '';

		if ( null === $stored || '' === $posted || ! hash_equals( (string) $stored['fingerprint'], $posted ) ) {
			self::redirect( 'preflight-required' );
		}

		$current = Preflight::run();
		set_transient(
			self::transient_key( get_current_user_id() ),
			$current,
			self::TRANSIENT_TTL
		);

		if ( ! hash_equals( (string) $stored['fingerprint'], (string) $current['fingerprint'] ) ) {
			self::redirect( 'preflight-stale' );
		}
		if ( empty( $current['ready'] ) ) {
			self::redirect( 'preflight-blocked' );
		}

		$saved = Policy::set_enabled( true, self::actor() );
		if ( $saved ) {
			Runtime::mark_rewrite_dirty();
			delete_transient( self::transient_key( get_current_user_id() ) );
		}

		self::redirect( $saved ? 'enabled' : 'error' );
	}

	public static function disable(): void {
		self::authorize( self::DISABLE_NONCE );

		$ack = isset( $_POST[ self::ACK_FIELD ] )
			? sanitize_key( wp_unslash( (string) $_POST[ self::ACK_FIELD ] ) )
			: '';

		if ( ! MutationAcknowledgement::confirmed( $ack ) ) {
			self::redirect( 'disable-ack-required' );
		}

		$saved = Policy::set_enabled( false, self::actor() );
		if ( $saved ) {
			Runtime::mark_rewrite_dirty();
			delete_transient( self::transient_key( get_current_user_id() ) );
		}

		self::redirect( $saved ? 'disabled' : 'error' );
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Routing & URLs.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( $nonce_action );
	}

	private static function actor(): string {
		$user = wp_get_current_user();
		return $user instanceof \WP_User && $user->exists()
			? 'admin:' . $user->user_login
			: 'admin:unknown';
	}

	private static function redirect( string $state ): never {
		$url = add_query_arg(
			[
				'page'          => Preferences::SLUG,
				'tab'           => 'routing',
				'routing_state' => sanitize_key( $state ),
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private static function transient_key( int $user_id ): string {
		return 'cb_core_routing_preflight_' . max( 0, $user_id );
	}

	private function __construct() {}
}
