<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Audit Log IP handling.
 *
 * All supported modes are explicit governance choices. Setup reviews the
 * decision without declaring a legal/compliance winner.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\Privacy\Anonymizer;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class PrivacyIpHandlingCheck implements CheckInterface {

	public function id(): string { return 'privacy-ip-handling'; }
	public function section(): string { return 'privacy-governance'; }
	public function label(): string { return 'IP handling'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=privacy' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$mode = Anonymizer::ip_mode();
			return new Evidence(
				Evidence::HEALTH_OK,
				'privacy.ip-handling',
				[ 'ip_mode' => $mode ],
				[ 'ip_mode' => $mode ]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'privacy.ip-handling-unavailable' );
		}
	}
}
