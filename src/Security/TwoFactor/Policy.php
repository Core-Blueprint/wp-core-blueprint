<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use CB\Core\Permissions\PrivilegedAccessPolicy;
use CB\Core\Settings;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical site-wide two-factor policy.
 *
 * Policy remains reproducible site configuration. User enrollment state and
 * authentication material live in the credential store and are never copied
 * into the Base settings document.
 */
final class Policy {

	public const SETTINGS_KEY = 'two_factor';

	public const MODE_OPTIONAL = 'optional';
	public const MODE_ENFORCE  = 'enforce';

	public const SCOPE_PRIVILEGED = 'privileged';

	/** @return array{mode:string,scope:string} */
	public static function default_config(): array {
		return [
			'mode'  => self::MODE_OPTIONAL,
			'scope' => self::SCOPE_PRIVILEGED,
		];
	}

	/** @return array{mode:string,scope:string} */
	public static function config(): array {
		$settings = Settings::get();
		$stored   = is_array( $settings[ self::SETTINGS_KEY ] ?? null )
			? $settings[ self::SETTINGS_KEY ]
			: [];

		$mode = sanitize_key( (string) ( $stored['mode'] ?? self::MODE_OPTIONAL ) );
		if ( ! self::is_valid_mode( $mode ) ) {
			$mode = self::MODE_OPTIONAL;
		}

		$scope = sanitize_key( (string) ( $stored['scope'] ?? self::SCOPE_PRIVILEGED ) );
		if ( self::SCOPE_PRIVILEGED !== $scope ) {
			$scope = self::SCOPE_PRIVILEGED;
		}

		return [
			'mode'  => $mode,
			'scope' => $scope,
		];
	}

	public static function mode(): string {
		return self::config()['mode'];
	}

	public static function is_valid_mode( string $mode ): bool {
		return in_array( $mode, [ self::MODE_OPTIONAL, self::MODE_ENFORCE ], true );
	}

	public static function is_in_scope( WP_User $user ): bool {
		return PrivilegedAccessPolicy::is_privileged( $user );
	}

	/**
	 * Whether policy requires this identity to establish a second factor.
	 *
	 * Optional mode never removes an existing enrollment. It only means an
	 * unenrolled privileged identity is not forced to enroll yet.
	 */
	public static function requires_enrollment( WP_User $user ): bool {
		return self::MODE_ENFORCE === self::mode() && self::is_in_scope( $user );
	}
}
