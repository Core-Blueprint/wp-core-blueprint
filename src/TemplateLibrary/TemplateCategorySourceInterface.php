<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Optional per-actor Template Library category metadata.
 *
 * Additive public interface: existing TemplateSourceInterface implementations
 * keep working without implementing categories. No template bodies, counts,
 * credentials or private per-template information may be returned.
 */
interface TemplateCategorySourceInterface {
	/** @return array<string,string> Category ID => localized display label (max 48). */
	public function categories( int $user_id ): array;
}
