<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in ordered, authorized, bounded provider contract for TL-1B1.
 *
 * The provider MUST filter before paging, honor the exact TemplateSort
 * comparator (raw UTF-8 bytes, then source ID and template ID), and return
 * the first <= $query->limit entries STRICTLY after the global position.
 * No private cursor is returned: Base owns the single signed seek position.
 * Unsupported providers do not implement this interface and cause an
 * explicit sort_unavailable response (never a falsely sorted partial page).
 */
interface TemplateSortedSourceInterface extends TemplateSourceInterface {
	/**
	 * @param array{title:string,source:string,id:string}|null $after Trusted Base-decoded position.
	 */
	public function query_sorted( TemplateQuery $query, ?array $after ): TemplatePage;
}
