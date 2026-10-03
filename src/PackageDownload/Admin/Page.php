<?php
declare(strict_types=1);
/**
 * Package Downloads admin overview.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\PackageDownload\Admin;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\PackageDownload\AdminIntegration;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public const SLUG = 'core-blueprint-package-downloads';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Package Downloads', 'core-blueprint' );
	}

	public function position(): ?int {
		return 29; // After Media Replace (28), before Safeguards (30).
	}

	public function render(): void {
		$this->guard();
		AdminIntegration::render_overview();
	}
}
