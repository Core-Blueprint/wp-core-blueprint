<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Additive public consumer boundary. Query/preview/instantiate authority stays
 * with the consumer. No canonical domain document ever enters Base listings.
 */
interface TemplateSourceInterface {
	public function source_id(): string;
	public function extension_id(): string;
	public function designer_type(): string;
	public function can_browse( int $user_id ): bool;
	public function can_view( string $template_id, int $user_id ): bool;
	/** Apply all TemplateQuery predicates before paging. Return stable ordering. */
	public function query( TemplateQuery $query, ?string $cursor ): TemplatePage;
}
