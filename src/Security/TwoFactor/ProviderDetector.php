<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use Throwable;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Exact per-user ownership checks for supported external 2FA providers.
 *
 * Plugin activation alone never counts as protection. Base stands down only
 * when a loaded provider reports that this exact WordPress user actively uses
 * two-factor authentication.
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

		if ( self::wordfence_active_for_user( $user ) ) {
			$providers[] = self::WORDFENCE;
		}
		if ( self::two_factor_active_for_user( $user ) ) {
			$providers[] = self::TWO_FACTOR;
		}
		if ( self::wp_2fa_active_for_user( $user ) ) {
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

	private static function wordfence_active_for_user( WP_User $user ): bool {
		$class = '\\WordfenceLS\\Controller_Users';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'shared' ) ) {
			return false;
		}

		try {
			$controller = $class::shared();
			return is_object( $controller )
				&& method_exists( $controller, 'has_2fa_active' )
				&& (bool) $controller->has_2fa_active( $user );
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

	private static function wp_2fa_active_for_user( WP_User $user ): bool {
		$class = '\\WP2FA\\Admin\\Helpers\\User_Helper';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'is_user_using_two_factor' ) ) {
			return false;
		}

		try {
			return (bool) $class::is_user_using_two_factor( (int) $user->ID );
		} catch ( Throwable ) {
			return false;
		}
	}
}
