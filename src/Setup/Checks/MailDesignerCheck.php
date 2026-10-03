<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the independent Mail Designer choice.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Mail\Admin\Page;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class MailDesignerCheck implements CheckInterface {

	public function id(): string { return 'mail-designer'; }
	public function section(): string { return 'mail'; }
	public function label(): string { return 'Mail Designer'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Page::SLUG . '&tab=templates' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$enabled = DesignerState::is_enabled();
			return new Evidence(
				Evidence::HEALTH_OK,
				$enabled ? 'mail.designer-enabled' : 'mail.designer-disabled',
				[ 'designer_enabled' => $enabled ],
				[ 'designer_enabled' => $enabled ]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'mail.designer-unavailable' );
		}
	}
}
