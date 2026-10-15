<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/** Optional, per-actor category metadata without template bodies or counts. */
interface TemplateCategorySourceInterface {
	/** @return array<string,string> Category slug => display label (max 48). */
	public function categories( int $user_id ): array;
}
