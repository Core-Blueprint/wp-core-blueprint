<?php
declare(strict_types=1);
/**
 * Core Setup evidence for URL Governance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\Routing\Policy;
use CoreBlueprint\Core\Routing\Preflight;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class RoutingUrlsCheck implements CheckInterface {

	public function id(): string { return 'routing-urls'; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return 'Routing & URLs'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=routing' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$enabled = Policy::enabled();

			if ( ! $enabled ) {
				return new Evidence(
					Evidence::HEALTH_OK,
					'routing.wordpress-default',
					[
						'enabled'             => false,
						'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
						'category_base'       => \CoreBlueprint\Core\Routing\CategoryRoutes::category_base_path(),
					],
					[
						'enabled'       => false,
						'blocker_count' => 0,
					]
				);
			}

			$preflight = Preflight::run();
			$attention = empty( $preflight['ready'] );

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'routing.collision-detected' : 'routing.clean-archives-enabled',
				[
					'enabled'               => true,
					'preflight_fingerprint' => (string) ( $preflight['fingerprint'] ?? '' ),
					'blocker_count'         => count( (array) ( $preflight['blockers'] ?? [] ) ),
				],
				[
					'enabled'       => true,
					'blocker_count' => count( (array) ( $preflight['blockers'] ?? [] ) ),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'routing.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['enabled'] );
	}
}
