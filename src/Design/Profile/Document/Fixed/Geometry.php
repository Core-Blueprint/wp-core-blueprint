<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

defined( 'ABSPATH' ) || exit;

final class Geometry {
	private const EPSILON = 0.01;

	public static function number( mixed $value ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$number = (float) $value;
		return is_finite( $number ) ? $number : null;
	}

	/**
	 * @param array<string,mixed> $layout
	 * @return array{width:float,height:float}|null
	 */
	public static function page( array $layout ): ?array {
		if (
			Contract::LAYOUT_MODE !== ( $layout['mode'] ?? null )
			|| Contract::UNITS !== ( $layout['units'] ?? null )
			|| ! is_array( $layout['page'] ?? null )
		) {
			return null;
		}

		$width  = self::number( $layout['page']['width'] ?? null );
		$height = self::number( $layout['page']['height'] ?? null );
		if ( null === $width || null === $height || $width <= 0.0 || $height <= 0.0 ) {
			return null;
		}

		return [ 'width' => $width, 'height' => $height ];
	}

	/**
	 * @param array<string,mixed> $properties
	 * @return array{x:float,y:float,width:float,height:float}|null
	 */
	public static function frame( array $properties ): ?array {
		if ( ! is_array( $properties[ Contract::NODE_FRAME_KEY ] ?? null ) ) {
			return null;
		}

		$frame = $properties[ Contract::NODE_FRAME_KEY ];
		$x = self::number( $frame['x'] ?? null );
		$y = self::number( $frame['y'] ?? null );
		$width = self::number( $frame['width'] ?? null );
		$height = self::number( $frame['height'] ?? null );

		if (
			null === $x || null === $y || null === $width || null === $height
			|| $x < 0.0 || $y < 0.0 || $width <= 0.0 || $height <= 0.0
		) {
			return null;
		}

		return [
			'x' => $x,
			'y' => $y,
			'width' => $width,
			'height' => $height,
		];
	}

	/**
	 * @param array{x:float,y:float,width:float,height:float} $frame
	 * @param array{width:float,height:float} $page
	 */
	public static function within_page( array $frame, array $page ): bool {
		return $frame['x'] + $frame['width'] <= $page['width'] + self::EPSILON
			&& $frame['y'] + $frame['height'] <= $page['height'] + self::EPSILON;
	}
}
