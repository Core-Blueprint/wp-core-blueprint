<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Flow;

defined( 'ABSPATH' ) || exit;

final class Layout {
	private const MAX_PAGE_MM = 2000.0;

	public static function number( mixed $value, bool $allow_zero = true ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number > self::MAX_PAGE_MM || ( $allow_zero ? $number < 0.0 : $number <= 0.0 ) ) {
			return null;
		}
		return $number;
	}

	/** @param array<string,mixed> $layout @return array{width:float,height:float}|null */
	public static function page( array $layout ): ?array {
		$page = $layout['page'] ?? null;
		if ( ! is_array( $page ) ) { return null; }
		$width = self::number( $page['width'] ?? null, false );
		$height = self::number( $page['height'] ?? null, false );
		return null === $width || null === $height ? null : [ 'width' => $width, 'height' => $height ];
	}

	/** @param array<string,mixed> $layout @return array{top:float,right:float,bottom:float,left:float}|null */
	public static function margins( array $layout ): ?array {
		$raw = $layout['margins'] ?? null;
		if ( ! is_array( $raw ) ) { return null; }
		$values = [];
		foreach ( Contract::MARGIN_KEYS as $key ) {
			$value = self::number( $raw[ $key ] ?? null );
			if ( null === $value ) { return null; }
			$values[ $key ] = $value;
		}
		return $values;
	}

	/** @param array{width:float,height:float} $page @param array{top:float,right:float,bottom:float,left:float} $margins */
	public static function has_content_area( array $page, array $margins ): bool {
		return $margins['left'] + $margins['right'] < $page['width'] && $margins['top'] + $margins['bottom'] < $page['height'];
	}

	/** @param array<string,mixed> $layout */
	public static function matches_contract( array $layout ): bool {
		return self::exact_keys( $layout, Contract::LAYOUT_KEYS )
			&& Contract::LAYOUT_MODE === ( $layout['mode'] ?? null )
			&& Contract::UNITS === ( $layout['units'] ?? null )
			&& is_array( $layout['page'] ?? null )
			&& self::exact_keys( $layout['page'], Contract::PAGE_KEYS )
			&& is_array( $layout['margins'] ?? null )
			&& self::exact_keys( $layout['margins'], Contract::MARGIN_KEYS );
	}

	/** @param array<string,mixed> $value @param list<string> $allowed */
	private static function exact_keys( array $value, array $allowed ): bool {
		$keys = array_keys( $value );
		if ( count( $keys ) !== count( $allowed ) ) { return false; }
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) { return false; }
		}
		return true;
	}

	private function __construct() {}
}
