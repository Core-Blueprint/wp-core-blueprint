<?php
declare(strict_types=1);
/**
 * Contract for attachment replacement filename strategies.
 *
 * The first implementation preserves the existing filename and URL. The
 * contract deliberately separates target-name selection from file mutation so
 * a later "use uploaded filename + update references" strategy can be added
 * without rewriting the transactional replace service.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\MediaReplace\Strategy;

defined( 'ABSPATH' ) || exit;

interface ReplaceStrategyInterface {

	/** Stable strategy identifier stored in audit context. */
	public function key(): string;

	/**
	 * Resolve the final absolute attachment path.
	 *
	 * @param \WP_Post $attachment        Attachment post.
	 * @param string   $current_file      Current absolute attached-file path.
	 * @param string   $uploaded_filename Original browser filename.
	 */
	public function target_path( \WP_Post $attachment, string $current_file, string $uploaded_filename ): string;

	/**
	 * Whether this strategy changes public file references.
	 *
	 * Preserve-filename replacements return false. A future rename strategy
	 * returns true and can then be paired with a dedicated reference updater.
	 */
	public function requires_reference_update(): bool;
}
