<?php
declare(strict_types=1);
/**
 * Detector
 *
 * Detects installed security plugins that could conflict or duplicate CB
 * Security features. Every restrictive module checks the detector before
 * enforcing - if another plugin is known to already handle a given control,
 * Core Blueprint delegates to it rather than double-enforce.
 *
 * The detection list is intentionally conservative: we only delegate when
 * the other plugin reliably owns the feature. In doubt, Core Blueprint enforces
 * alongside - duplicate enforcement is usually harmless; conflicting
 * enforcement (e.g. two login rate limiters disagreeing) is not.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

defined( 'ABSPATH' ) || exit;

final class Detector {

	/**
	 * Static feature-delegation map. Key = plugin basename, value = array of
	 * feature IDs the plugin is known to handle well enough for Core Blueprint
	 * to delegate.
	 *
	 * Feature IDs are stable slugs owned by Core Blueprint. Individual modules
	 * reference them in their own conflict-check calls.
	 */
	const FEATURE_MAP = [
		'wordfence/wordfence.php' => [
			'xmlrpc_disable',
			'login_rate_limit',
			'user_enumeration_rest',
			'file_integrity',
			'login_captcha',
		],
		'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' => [
			'login_rate_limit',
		],
		'two-factor/two-factor.php' => [
			'two_factor_auth',
		],
		'sucuri-scanner/sucuri.php' => [
			'file_integrity',
			'malware_scan',
		],
		'wp-2fa/wp-2fa.php' => [
			'two_factor_auth',
		],
		'all-in-one-wp-security-and-firewall/wp-security.php' => [
			'login_rate_limit',
			'xmlrpc_disable',
		],
	];

	/** Cached result of detect_active_plugins() per request. */
	private static ?array $cache = null;

	// ─── Detection ────────────────────────────────────────────────────────────

	/**
	 * Return a list of all active plugins that appear in FEATURE_MAP.
	 *
	 * @return array<string, array> Plugin basename => list of delegated features.
	 */
	public static function detect_active_plugins(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}

		$active = (array) get_option( 'active_plugins', [] );

		// On multisite, merge network-active plugins.
		if ( is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
			$active = array_merge( $active, array_keys( $network_active ) );
		}

		$detected = [];
		foreach ( $active as $plugin_file ) {
			if ( isset( self::FEATURE_MAP[ $plugin_file ] ) ) {
				$detected[ $plugin_file ] = self::FEATURE_MAP[ $plugin_file ];
			}
		}

		self::$cache = $detected;
		return $detected;
	}

	/**
	 * Is a specific feature handled by another active plugin?
	 *
	 * @param string $feature_id Stable slug of the feature (see FEATURE_MAP).
	 * @return string|null Plugin basename that handles it, or null if none.
	 */
	public static function delegated_to( string $feature_id ): ?string {
		foreach ( self::detect_active_plugins() as $plugin_file => $features ) {
			if ( in_array( $feature_id, $features, true ) ) {
				return $plugin_file;
			}
		}
		return null;
	}

	/**
	 * Human-readable label for a detected plugin. Falls back to the basename.
	 */
	public static function plugin_label( string $plugin_file ): string {
		$labels = [
			'wordfence/wordfence.php' => 'Wordfence',
			'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' => 'Limit Login Attempts Reloaded',
			'two-factor/two-factor.php' => 'Two-Factor',
			'sucuri-scanner/sucuri.php' => 'Sucuri Security',
			'wp-2fa/wp-2fa.php' => 'WP 2FA',
			'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
		];

		return $labels[ $plugin_file ] ?? $plugin_file;
	}

	// ─── Summary for admin UI ────────────────────────────────────────────────

	/**
	 * Build a summary suitable for the Core Blueprint dashboard:
	 *   - which conflicting plugins are active
	 *   - which CB features are being delegated and to whom
	 */
	public static function summary(): array {
		$detected = self::detect_active_plugins();
		$summary  = [
			'plugins'    => [],
			'delegated'  => [],
		];

		foreach ( $detected as $plugin_file => $features ) {
			$summary['plugins'][] = [
				'file'     => $plugin_file,
				'label'    => self::plugin_label( $plugin_file ),
				'features' => $features,
			];

			foreach ( $features as $feature_id ) {
				$summary['delegated'][ $feature_id ] = self::plugin_label( $plugin_file );
			}
		}

		return $summary;
	}

	// ─── Cache invalidation ──────────────────────────────────────────────────

	/**
	 * Called when the active plugin list changes. Hooked in the core bootstrap
	 * so that activating/deactivating another security plugin updates detector
	 * results mid-request without requiring a page reload.
	 */
	public static function invalidate_cache(): void {
		self::$cache = null;
	}
}

// Invalidate the detector cache whenever plugins are activated or deactivated.
add_action( 'activated_plugin',   [ Detector::class, 'invalidate_cache' ] );
add_action( 'deactivated_plugin', [ Detector::class, 'invalidate_cache' ] );
