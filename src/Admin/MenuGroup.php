<?php
declare(strict_types=1);
/**
 * MenuGroup - immutable declaration for an extension-owned top-level product menu.
 *
 * Base owns WordPress menu wiring. Extensions own the group identity, labels,
 * visibility capability and the pages registered inside the group.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class MenuGroup {

	public function __construct(
		private string $slug,
		private string $title,
		private string $menu_title,
		private string $capability,
		private string $icon = '',
		private ?int $position = null
	) {}

	public function slug(): string {
		return $this->slug;
	}

	public function title(): string {
		return $this->title;
	}

	public function menu_title(): string {
		return $this->menu_title;
	}

	public function capability(): string {
		return $this->capability;
	}

	public function icon(): string {
		return $this->icon;
	}

	public function position(): ?int {
		return $this->position;
	}
}
