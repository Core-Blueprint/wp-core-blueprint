<?php
declare(strict_types=1);
/**
 * Access Mode runtime policy and stable public facade.
 *
 * State/persistence lives in AccessModeState; wp-admin/AJAX presentation lives
 * in AccessModeAdmin. This class intentionally owns only request bypass policy,
 * front-end HTTP enforcement, and the public AccessMode API used by Base and
 * extensions.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

use WP_Query;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class AccessMode {

	public const OPTION_KEY        = 'cb_core_access_mode';
	public const CONFIG_OPTION_KEY = 'cb_core_access_mode_config';
	public const CONFIG_SCHEMA     = 1;

	public const MODE_PUBLIC       = 'public';
	public const MODE_COMING_SOON  = 'coming_soon';
	public const MODE_MAINTENANCE  = 'maintenance';
	public const MODE_ADMIN_ONLY   = 'admin_only';

	/** @var array<string,callable(string):bool> */
	private static array $bypass_callbacks = [];

	/** True once the maintenance request's main query was switched to its page. */
	private static bool $maintenance_query_prepared = false;

	public static function boot(): void {
		AccessModeAdmin::boot();

		// AJAX management is owned by AccessModeAdmin; front-end enforcement must
		// never register on admin-ajax.php.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		if ( self::is_public() ) {
			return;
		}

		add_action( 'pre_get_posts', [ __CLASS__, 'maybe_prepare_maintenance_query' ], 0 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'maybe_disable_maintenance_canonical' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_enforce' ], 0 );
	}

	/**
	 * Register a request-level bypass callback.
	 *
	 * The callback receives the effective access mode and must return true only
	 * for the current request that should remain reachable.
	 *
	 * @param string                $id       Stable extension-owned identifier.
	 * @param callable(string):bool $callback Request predicate.
	 */
	public static function register_bypass( string $id, callable $callback ): void {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return;
		}
		self::$bypass_callbacks[ $id ] = $callback;
	}

	/** @return string[] */
	public static function modes(): array {
		return [
			self::MODE_PUBLIC,
			self::MODE_COMING_SOON,
			self::MODE_MAINTENANCE,
			self::MODE_ADMIN_ONLY,
		];
	}

	// Stable public state facade.
	public static function current(): string {
		return AccessModeState::current();
	}

	public static function is_public(): bool {
		return self::MODE_PUBLIC === self::current();
	}

	public static function is_coming_soon(): bool {
		return self::MODE_COMING_SOON === self::current();
	}

	public static function is_maintenance(): bool {
		return self::MODE_MAINTENANCE === self::current();
	}

	public static function is_admin_only(): bool {
		return self::MODE_ADMIN_ONLY === self::current();
	}

	/** @return array{schema_version:int,coming_soon_page_id:int,coming_soon_indexable:bool,maintenance_page_id:int,maintenance_until_date:string,maintenance_until_time:string} */
	public static function config(): array {
		return AccessModeState::config();
	}

	// Stable public presentation facade used by existing Base surfaces.
	public static function mode_label( ?string $mode = null ): string {
		return AccessModeAdmin::mode_label( $mode );
	}

	public static function status_label( ?string $mode = null ): string {
		return AccessModeAdmin::status_label( $mode );
	}

	/** @return array<int,array{id:int,label:string,meta:string}> */
	public static function picker_selected_page( int $page_id ): array {
		return AccessModeAdmin::picker_selected_page( $page_id );
	}

	// ─── Request bypass boundary ──────────────────────────────────────────

	public static function should_bypass_request( ?string $mode = null ): bool {
		$mode = $mode ?? self::current();

		if ( self::MODE_PUBLIC === $mode ) {
			return true;
		}
		if ( is_user_logged_in() || is_admin() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}
		// Standard WooCommerce machine callbacks must remain reachable while a
		// public access policy is active.
		if ( isset( $_GET['wc-api'] ) || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing context.
			return true;
		}
		if ( class_exists( Failsafe::class ) && Failsafe::is_bypassed() ) {
			return true;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] )
			? basename( sanitize_text_field( wp_unslash( (string) $_SERVER['SCRIPT_NAME'] ) ) )
			: '';
		if ( 'wp-login.php' === $script ) {
			return true;
		}

		foreach ( self::$bypass_callbacks as $callback ) {
			try {
				if ( true === $callback( $mode ) ) {
					return true;
				}
			} catch ( Throwable $e ) {
				// A faulty extension bypass must never disable Access Mode globally.
				continue;
			}
		}

		/**
		 * Filters whether Access Mode should stand down for the current request.
		 *
		 * Prefer register_bypass() for new integrations.
		 *
		 * @param bool   $bypass Current decision.
		 * @param string $mode   Effective Access Mode.
		 */
		return (bool) apply_filters( 'cb_core_access_mode_bypass_request', false, $mode );
	}

	// ─── Front-end enforcement ────────────────────────────────────────────

	public static function maybe_prepare_maintenance_query( WP_Query $query ): void {
		if ( ! $query->is_main_query() || is_admin() ) {
			return;
		}

		$mode = self::current();
		if ( self::MODE_MAINTENANCE !== $mode || self::should_bypass_request( $mode ) ) {
			return;
		}

		$config = self::config();
		$page   = AccessModeState::valid_landing_page( (int) $config['maintenance_page_id'] );
		if ( ! $page ) {
			return;
		}

		$query->set( 'page_id', (int) $page->ID );
		$query->set( 'p', 0 );
		$query->set( 'name', '' );
		$query->set( 'pagename', '' );
		$query->set( 'post_type', 'page' );
		$query->set( 'error', '' );

		$query->is_404               = false;
		$query->is_home              = false;
		$query->is_archive           = false;
		$query->is_search            = false;
		$query->is_feed              = false;
		$query->is_date              = false;
		$query->is_year              = false;
		$query->is_month             = false;
		$query->is_day               = false;
		$query->is_time              = false;
		$query->is_author            = false;
		$query->is_category          = false;
		$query->is_tag               = false;
		$query->is_tax               = false;
		$query->is_post_type_archive = false;
		$query->is_attachment        = false;
		$query->is_privacy_policy    = false;
		$query->is_singular          = true;
		$query->is_page              = true;
		$query->is_single            = false;
		$query->queried_object       = null;
		$query->queried_object_id    = 0;

		self::$maintenance_query_prepared = true;
	}

	/** Prevent WordPress from canonical-redirecting the maintenance page URL. */
	public static function maybe_disable_maintenance_canonical( $redirect_url, $requested_url ) {
		unset( $requested_url );
		$mode = self::current();
		if ( self::MODE_MAINTENANCE === $mode && ! self::should_bypass_request( $mode ) ) {
			return false;
		}
		return $redirect_url;
	}

	public static function maybe_enforce(): void {
		$mode = self::current();
		if ( self::MODE_PUBLIC === $mode || self::should_bypass_request( $mode ) ) {
			return;
		}

		self::mark_request_uncacheable();

		switch ( $mode ) {
			case self::MODE_COMING_SOON:
				self::enforce_coming_soon();
				return;
			case self::MODE_MAINTENANCE:
				self::enforce_maintenance();
				return;
			case self::MODE_ADMIN_ONLY:
				self::enforce_admin_only();
				return;
		}
	}

	private static function enforce_coming_soon(): void {
		$config = self::config();
		$page   = AccessModeState::valid_landing_page( (int) $config['coming_soon_page_id'] );
		if ( ! $page ) {
			self::render_service_unavailable_fallback( __( 'Coming soon.', 'core-blueprint' ) );
		}

		if ( (int) get_queried_object_id() === (int) $page->ID ) {
			status_header( 200 );
			if ( empty( $config['coming_soon_indexable'] ) ) {
				header( 'X-Robots-Tag: noindex, follow' );
			}
			return;
		}

		$url = get_permalink( $page );
		if ( ! is_string( $url ) || '' === $url ) {
			self::render_service_unavailable_fallback( __( 'Coming soon.', 'core-blueprint' ) );
		}

		wp_safe_redirect( $url, 302, 'Core Blueprint Access Mode' );
		exit;
	}

	private static function enforce_maintenance(): void {
		$config = self::config();
		$page   = AccessModeState::valid_landing_page( (int) $config['maintenance_page_id'] );
		if ( ! $page || ! self::$maintenance_query_prepared ) {
			self::render_service_unavailable_fallback( __( 'Temporarily unavailable for maintenance.', 'core-blueprint' ) );
		}

		status_header( 503 );
		$retry_after = self::retry_after_header( $config );
		if ( '' !== $retry_after ) {
			header( 'Retry-After: ' . $retry_after );
		}
	}

	private static function enforce_admin_only(): void {
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo "Forbidden.\n";
		exit;
	}

	private static function render_service_unavailable_fallback( string $message ): void {
		status_header( 503 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		$title = __( 'Service unavailable', 'core-blueprint' );
		echo '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head><body><main><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p></main></body></html>';
		exit;
	}

	private static function mark_request_uncacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/** @param array<string,mixed>|null $config */
	private static function retry_after_header( ?array $config = null ): string {
		$config = $config ?? self::config();
		$date   = (string) ( $config['maintenance_until_date'] ?? '' );
		$time   = (string) ( $config['maintenance_until_time'] ?? '' );
		$at     = AccessModeState::maintenance_until_datetime( $date, $time );
		if ( ! $at || $at->getTimestamp() <= time() ) {
			return '';
		}

		return $at->setTimezone( new \DateTimeZone( 'GMT' ) )->format( 'D, d M Y H:i:s \G\M\T' );
	}
}
