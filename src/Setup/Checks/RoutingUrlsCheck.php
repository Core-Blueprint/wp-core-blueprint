<?php
declare(strict_types=1);
/**
 * Core Setup evidence for URL Governance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Preferences;
use CB\Core\Routing\Policy;
use CB\Core\Routing\Preflight;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

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
			$enabled   = Policy::enabled();
			$preflight = Preflight::run();
			$attention = $enabled && empty( $preflight['ready'] );

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention
					? 'routing.collision-detected'
					: ( $enabled ? 'routing.clean-archives-enabled' : 'routing.wordpress-default' ),
				[
					'enabled'               => $enabled,
					'preflight_fingerprint' => (string) ( $preflight['fingerprint'] ?? '' ),
					'blocker_count'         => count( (array) ( $preflight['blockers'] ?? [] ) ),
				],
				[
					'enabled'       => $enabled,
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
