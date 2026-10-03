<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Core Shield master hardening readiness.
 *
 * Privileged identity review is deliberately excluded here because it has its
 * own Setup check. This prevents one incident from appearing as two unrelated
 * setup failures.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Safeguards;
use CoreBlueprint\Core\Security\ModuleRegistry;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class CoreShieldCheck implements CheckInterface {

	public function id(): string { return 'core-shield'; }
	public function section(): string { return 'safeguards'; }
	public function label(): string { return 'Core Shield'; }
	public function kind(): string { return self::KIND_REQUIRED; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=core-shield' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$shield = Settings::shield_enabled();
			$settings = Settings::get();
			$stored_modules = is_array( $settings['modules'] ?? null ) ? $settings['modules'] : [];
			$modules = [];
			$enabled = 0;

			foreach ( ModuleRegistry::all() as $module ) {
				$slug = (string) $module->slug();
				$is_enabled = ! empty( $stored_modules[ $slug ]['enabled'] );
				$modules[ $slug ] = $is_enabled;
				$enabled += $is_enabled ? 1 : 0;
			}
			ksort( $modules, SORT_STRING );

			$total = count( $modules );
			$attention = ! $shield || 0 === $total || 0 === $enabled;

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'core-shield.attention' : 'core-shield.ready',
				[
					'shield_enabled' => $shield,
					'modules'        => $modules,
				],
				[
					'shield_enabled' => $shield,
					'enabled'        => $enabled,
					'total'          => $total,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'core-shield.unavailable' );
		}
	}
}
