<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Login Shield policy and effective health.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Safeguards;
use CoreBlueprint\Core\Modules\Status as ModuleStatus;
use CoreBlueprint\Core\Security\LoginShield;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class LoginShieldCheck implements CheckInterface {

	public function id(): string { return 'login-shield'; }
	public function section(): string { return 'safeguards'; }
	public function label(): string { return 'Login Shield'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=login-shield' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return true; }

	public function evidence(): Evidence {
		try {
			$config = LoginShield::config();
			$status = ModuleStatus::get( 'login-shield' );
			if ( ! is_array( $status ) ) {
				return Evidence::unavailable( 'login-shield.status-unavailable' );
			}

			$status_state = (string) ( $status['state'] ?? '' );
			$enabled      = ! empty( $config['enabled'] );
			$attention    = in_array( $status_state, [ 'warn', 'err' ], true ) || ( 'off' === $status_state && $enabled );

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'login-shield.attention' : ( $enabled ? 'login-shield.enabled' : 'login-shield.disabled' ),
				[
					'enabled'                  => $enabled,
					'slug_hash'                => Fingerprint::hash( [ 'value' => (string) ( $config['slug'] ?? '' ) ] ),
					'mode'                     => (string) ( $config['mode'] ?? '' ),
					'redirect_after_login'     => (string) ( $config['redirect_after_login'] ?? '' ),
					'redirect_custom_url_hash' => Fingerprint::hash( [ 'value' => (string) ( $config['redirect_custom_url'] ?? '' ) ] ),
					'block_response_code'      => (int) ( $config['block_response_code'] ?? 0 ),
					'core_shield_enabled'      => Settings::shield_enabled(),
				],
				[
					'enabled'             => $enabled,
					'mode'                => (string) ( $config['mode'] ?? '' ),
					'module_status'       => $status_state,
					'core_shield_enabled' => Settings::shield_enabled(),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'login-shield.unavailable' );
		}
	}
}
