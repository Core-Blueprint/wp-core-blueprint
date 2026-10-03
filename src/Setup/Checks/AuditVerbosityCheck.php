<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Audit Log verbosity.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\Log\Verbosity;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class AuditVerbosityCheck implements CheckInterface {

	public function id(): string { return 'audit-verbosity'; }
	public function section(): string { return 'privacy-governance'; }
	public function label(): string { return 'Audit verbosity'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=privacy' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$levels = Verbosity::all_levels();
			ksort( $levels, SORT_STRING );
			return new Evidence(
				Evidence::HEALTH_OK,
				'audit.verbosity',
				[ 'levels' => $levels ],
				[ 'levels' => $levels ]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'audit.verbosity-unavailable' );
		}
	}
}
