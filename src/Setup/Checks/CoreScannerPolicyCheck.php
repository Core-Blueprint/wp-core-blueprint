<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Core Scanner policy.
 *
 * Scanner activation is an explicit operator choice. A disabled Scanner can
 * therefore be reviewed as Not applicable rather than treated as a failure.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Integrity\State;
use CB\Core\Integrity\Storage\ResultRepository;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class CoreScannerPolicyCheck implements CheckInterface {

	public function id(): string { return 'core-scanner-policy'; }
	public function section(): string { return 'safeguards'; }
	public function label(): string { return 'Core Scanner policy'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'cb_manage_integrity_policy'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=core-scanner' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$settings = ResultRepository::settings();
			$enabled = State::is_enabled();

			return new Evidence(
				Evidence::HEALTH_OK,
				$enabled ? 'core-scanner.policy-enabled' : 'core-scanner.policy-disabled',
				[
					'enabled'              => $enabled,
					'schedule'             => (string) ( $settings['schedule'] ?? 'disabled' ),
					'plugin_checksums'     => ! empty( $settings['plugin_checksums'] ),
					'theme_checksums'      => ! empty( $settings['theme_checksums'] ),
					'uploads_scan'         => ! empty( $settings['uploads_scan'] ),
					'max_visible_findings' => (int) ( $settings['max_visible_findings'] ?? 50 ),
					'admin_can_run'        => ! empty( $settings['admin_can_run'] ),
				],
				[
					'enabled'  => $enabled,
					'schedule' => (string) ( $settings['schedule'] ?? 'disabled' ),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'core-scanner.policy-unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['enabled'] );
	}
}
