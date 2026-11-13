<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * TL-1B1 exact, locale-independent UTF-8 byte ordering.
 * Not a natural-language or case-insensitive collation.
 */
final class TemplateSort {
	public const TITLE_ASC = 'title-asc';
	public const MAX_SOURCES = 12;

	public static function compare( TemplateMetadata $left, TemplateMetadata $right ): int {
		return strcmp( $left->title, $right->title )
			?: ( strcmp( $left->source, $right->source )
				?: strcmp( $left->id, $right->id ) );
	}

	/** @param array{title:string,source:string,id:string} $after */
	public static function after( TemplateMetadata $item, array $after ): bool {
		return strcmp( $item->title, $after['title'] )
			?: ( strcmp( $item->source, $after['source'] )
				?: strcmp( $item->id, $after['id'] ) ) > 0;
	}

	/** @return array{title:string,source:string,id:string} */
	public static function position( TemplateMetadata $item ): array {
		return [ 'title' => $item->title, 'source' => $item->source, 'id' => $item->id ];
	}

	/** @param mixed $position */
	public static function valid_position( mixed $position ): bool {
		return is_array( $position )
			&& array_keys( $position ) === [ 'title', 'source', 'id' ]
			&& is_string( $position['title'] ) && '' !== $position['title']
			&& strlen( $position['title'] ) <= 191 && 1 === preg_match( '//u', $position['title'] )
			&& is_string( $position['source'] ) && TemplateQuery::valid_id( $position['source'] )
			&& is_string( $position['id'] ) && TemplateQuery::valid_id( $position['id'] );
	}
}
