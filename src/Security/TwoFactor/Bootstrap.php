<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		LoginFlow::boot();
		ProfileController::boot();
		add_action( 'init', [ self::class, 'register_i18n_filters' ], 1 );
	}

	public static function register_i18n_filters(): void {
		\CB\Core\Governance\EventRegistry::register_core_many(
			self::register_event_labels( [] )
		);
	}

	/** @param array<string,string> $labels
	 *  @return array<string,string>
	 */
	public static function register_event_labels( array $labels ): array {
		$labels[ Audit::EVENT_ENROLLMENT_STARTED ]   = __( 'Two-factor: enrollment started', 'core-blueprint' );
		$labels[ Audit::EVENT_ENROLLMENT_COMPLETED ] = __( 'Two-factor: enrollment completed', 'core-blueprint' );
		$labels[ Audit::EVENT_RECOVERY_CODE_USED ]   = __( 'Two-factor: recovery code used', 'core-blueprint' );
		$labels[ Audit::EVENT_AUTHENTICATED ]        = __( 'Two-factor: authentication completed', 'core-blueprint' );
		$labels[ Audit::EVENT_BYPASS_USED ]          = __( 'Two-factor: Failsafe bypass used', 'core-blueprint' );
		$labels[ Audit::EVENT_MIGRATION_RESET ]      = __( 'Two-factor: imported authentication reset', 'core-blueprint' );
		$labels[ Audit::EVENT_POLICY_CHANGED ]       = __( 'Two-factor: policy changed', 'core-blueprint' );
		$labels[ Audit::EVENT_AUTHENTICATION_RESET ] = __( 'Two-factor: authentication reset', 'core-blueprint' );
		$labels[ Audit::EVENT_REMOVED ]              = __( 'Two-factor: authentication removed', 'core-blueprint' );
		return $labels;
	}
}
