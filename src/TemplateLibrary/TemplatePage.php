<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/** A bounded source page; the cursor is owned by the source until Base wraps it. */
final class TemplatePage {
	/** @var list<TemplateMetadata> */
	public readonly array $items;
	public readonly ?string $next_cursor;

	/** @param list<TemplateMetadata> $items */
	public function __construct( array $items, ?string $next_cursor = null ) {
		if ( count( $items ) > 48 || ! array_is_list( $items ) ) {
			throw new \InvalidArgumentException( 'Invalid template page size.' );
		}
		foreach ( $items as $item ) {
			if ( ! $item instanceof TemplateMetadata ) {
				throw new \InvalidArgumentException( 'Template page contains non-metadata.' );
			}
		}
		if ( null !== $next_cursor && ( '' === $next_cursor || strlen( $next_cursor ) > 2048 ) ) {
			throw new \InvalidArgumentException( 'Invalid source cursor.' );
		}
		$this->items = $items;
		$this->next_cursor = $next_cursor;
	}
}
