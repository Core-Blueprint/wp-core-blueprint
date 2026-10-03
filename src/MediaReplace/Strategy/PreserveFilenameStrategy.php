<?php
declare(strict_types=1);
/**
 * Preserve-filename media replacement strategy.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\MediaReplace\Strategy;

defined( 'ABSPATH' ) || exit;

final class PreserveFilenameStrategy implements ReplaceStrategyInterface {

	public function key(): string {
		return 'preserve_filename';
	}

	public function target_path( \WP_Post $attachment, string $current_file, string $uploaded_filename ): string {
		return $current_file;
	}

	public function requires_reference_update(): bool {
		return false;
	}
}
