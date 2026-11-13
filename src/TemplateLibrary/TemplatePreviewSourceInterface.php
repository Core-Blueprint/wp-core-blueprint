<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * TL-1C v1 optional capability. Existing metadata sources remain unchanged.
 *
 * Only the provider may load the document or choose synthetic sample data.
 * Providers MUST re-read the current record/version, enforce per-record ACL,
 * avoid archived/production personal data and perform no outbound HTTP.
 * Returning null for stale, revoked or unsafe previews is mandatory.
 */
interface TemplatePreviewSourceInterface extends TemplateSourceInterface {
	public const API_VERSION = 1;

	/** Distinct per-item read authority; can_view alone is insufficient. */
	public function can_preview( string $template_id, int $actor_id ): bool;

	/**
	 * Read-only on-demand render. Never use GET, a metadata listing or a
	 * generic Base renderer to obtain a preview. Recheck expected version
	 * against authoritative provider storage before using document content.
	 */
	public function render_preview( string $template_id, string $expected_version, int $actor_id ): ?TemplatePreview;
}
