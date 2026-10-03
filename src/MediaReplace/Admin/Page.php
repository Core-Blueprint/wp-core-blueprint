<?php
declare(strict_types=1);
/**
 * Media Replace admin page.
 *
 * The page doubles as a lightweight module overview and, when an
 * attachment_id is supplied, as the replacement workflow for that item.
 * Registration goes through the central PageRegistry so Core Blueprint's
 * standard title, theme, asset, and submenu contracts apply.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\MediaReplace\Admin;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\MediaReplace\AdminIntegration;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public const SLUG = 'core-blueprint-media-replace';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Media Replace', 'core-blueprint' );
	}

	public function position(): ?int {
		return 28; // After User Roles (27), before Safeguards (30).
	}

	public function capability(): string {
		// Page access is the content workflow boundary. Site-wide module state is
		// governed separately by MANAGE_MEDIA_REPLACE in ActivationRegistry.
		return 'upload_files';
	}

	public function render(): void {
		$this->guard();
		AdminIntegration::render_page();
	}
}
