<?php
declare(strict_types=1);
/**
 * Core Setup evidence for site availability through Access Mode.
 *
 * Intentional Coming Soon, Maintenance, and Admin-only modes are decisions,
 * not warnings by themselves. Attention is reserved for an invalid active
 * configuration.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Security\AccessMode;
use CB\Core\Security\AccessModeState;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class AccessModeCheck implements CheckInterface {

	public function id(): string { return 'access-mode'; }
	public function section(): string { return 'environment-availability'; }
	public function label(): string { return 'Access Mode'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=access-mode' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$mode   = AccessMode::current();
			$config = AccessMode::config();
			$invalid = '' !== AccessModeState::validate_config_for_mode( $mode, $config );

			return new Evidence(
				$invalid ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$invalid ? 'access-mode.invalid-configuration' : 'access-mode.valid',
				[
					'mode'   => $mode,
					'config' => [
						'schema_version'         => (int) ( $config['schema_version'] ?? 0 ),
						'coming_soon_page_id'    => (int) ( $config['coming_soon_page_id'] ?? 0 ),
						'coming_soon_indexable'  => ! empty( $config['coming_soon_indexable'] ),
						'maintenance_page_id'    => (int) ( $config['maintenance_page_id'] ?? 0 ),
						'maintenance_until_date' => (string) ( $config['maintenance_until_date'] ?? '' ),
						'maintenance_until_time' => (string) ( $config['maintenance_until_time'] ?? '' ),
					],
					'valid' => ! $invalid,
				],
				[
					'mode'  => $mode,
					'valid' => ! $invalid,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'access-mode.unavailable' );
		}
	}
}
