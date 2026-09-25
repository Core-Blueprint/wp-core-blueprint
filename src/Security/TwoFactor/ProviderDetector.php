<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use CB\Core\Detector;
use Throwable;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve external ownership of second-factor enforcement.
 *
 * Wordfence and the official WordPress Two-Factor plugin expose user-level
 * state that Core Blueprint can verify directly. WP 2FA does not currently
 * expose a stable public per-user contract that Base can safely depend on, so
 * active WP 2FA remains a conservative plugin-level delegation through the
 * canonical Detector until such a contract is available.
 */
final class ProviderDetector {

	public const WORDFENCE  = 'wordfence';
	public const TWO_FACTOR = 'two-factor';
	public const WP_2FA     = 'wp-2fa';

	/** @return string[] */
	public static function providers_for_user( WP_User $user ): array {
		if ( $user->ID <= 0 ) {
			return [];
		}

		$providers = [];

		if ( self::wordfence_owns_user( $user ) ) {
			$providers[] = self::WORDFENCE;
		}
		if ( self::two_factor_active_for_user( $user ) ) {
			$providers[] = self::TWO_FACTOR;
		}
		if ( self::wp_2fa_plugin_owns_feature() ) {
			$providers[] = self::WP_2FA;
		}

		return $providers;
	}

	public static function external_provider_owns_user( WP_User $user ): bool {
		return [] !== self::providers_for_user( $user );
	}

	public static function primary_provider( WP_User $user ): ?string {
		$providers = self::providers_for_user( $user );
		return $providers[0] ?? null;
	}

	private static function wordfence_owns_user( WP_User $user ): bool {
		$class = '\\WordfenceLS\\Controller_Users';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'shared' ) ) {
			return false;
		}

		try {
			$controller = $class::shared();
			if ( ! is_object( $controller ) ) {
				return false;
			}

			$active = method_exists( $controller, 'has_2fa_active' )
				&& (bool) $controller->has_2fa_active( $user );
			$required = method_exists( $controller, 'requires_2fa' )
				&& (bool) $controller->requires_2fa( $user );

			return $active || $required;
		} catch ( Throwable ) {
			return false;
		}
	}

	private static function two_factor_active_for_user( WP_User $user ): bool {
		$class = '\\Two_Factor_Core';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'is_user_using_two_factor' ) ) {
			return false;
		}

		try {
			return (bool) $class::is_user_using_two_factor( $user );
		} catch ( Throwable ) {
			return false;
		}
	}

	private static function wp_2fa_plugin_owns_feature(): bool {
		$detected = Detector::detect_active_plugins();
		$features = $detected['wp-2fa/wp-2fa.php'] ?? [];

		return is_array( $features ) && in_array( 'two_factor_auth', $features, true );
	}
}
