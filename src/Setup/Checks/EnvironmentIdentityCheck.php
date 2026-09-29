<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the WordPress-native environment identity.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Environment\Governance;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class EnvironmentIdentityCheck implements CheckInterface {

	public function id(): string { return 'environment-identity'; }
	public function section(): string { return 'environment-availability'; }
	public function label(): string { return 'WordPress environment'; }
	public function kind(): string { return self::KIND_INFORMATIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=environment' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$type = Governance::current_type();
			return new Evidence(
				Evidence::HEALTH_OK,
				'environment.identity',
				[ 'environment_type' => $type ],
				[ 'environment_type' => $type ]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'environment.identity-unavailable' );
		}
	}
}
