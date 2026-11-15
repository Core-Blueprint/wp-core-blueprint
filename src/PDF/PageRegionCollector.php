<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\PDF;

defined( 'ABSPATH' ) || exit;

/**
 * Captures bounded semantic PDF fragment geometry from the SAME Dompdf render.
 *
 * Coordinates are page-local PDF points. The frame callback is an internal
 * renderer hook, never a consumer API. Does not create a page viewer or infer
 * browser preview page boundaries.
 *
 * @internal
 */
final class PageRegionCollector {
	private const MAX_REGIONS = 500;
	private const MAX_FRAGMENTS = 5000;
	private const MAX_PAGES = 500;
	private const REGION_ID = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D';

	/** @var array<string,true> */
	private array $expected = [];

	/** @var array<string,list<array{page:int,x:float,y:float,width:float,height:float}>> */
	private array $fragments = [];

	private int $fragment_count = 0;

	/** @param list<string> $region_ids */
	public function __construct(
		array $region_ids,
		private readonly float $width,
		private readonly float $height
	) {
		if ( ! array_is_list( $region_ids ) || [] === $region_ids || count( $region_ids ) > self::MAX_REGIONS ) {
			throw new \InvalidArgumentException( 'PDF geometry requires 1–500 ordered semantic regions.' );
		}
		if ( ! is_finite( $width ) || ! is_finite( $height )
			|| $width < 1.0 || $height < 1.0 || $width > 6000.0 || $height > 6000.0 ) {
			throw new \InvalidArgumentException( 'Invalid PDF geometry page dimensions.' );
		}
		foreach ( $region_ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::REGION_ID, $id ) || isset( $this->expected[ $id ] ) ) {
				throw new \InvalidArgumentException( 'PDF geometry requires distinct bounded semantic identifiers.' );
			}
			$this->expected[ $id ] = true;
		}
	}

	/** @return list<array{event:string,f:callable}> */
	public function callbacks(): array {
		return [
			[
				'event' => 'end_frame',
				'f' => function ( $frame, $canvas ): void {
					$node = $frame->get_node();
					if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( 'data-cb-flow-preview-region' ) ) {
						return;
					}
					$id = $node->getAttribute( 'data-cb-flow-preview-region' );
					if ( ! isset( $this->expected[ $id ] ) ) {
						throw new \RuntimeException( 'Unrecognized semantic PDF region.' );
					}
					if ( ++$this->fragment_count > self::MAX_FRAGMENTS ) {
						throw new \RuntimeException( 'PDF geometry fragment budget exceeded.' );
					}
					$page = $canvas->get_page_number();
					$box = $frame->get_border_box();
					if ( ! is_int( $page ) || $page < 1 || $page > self::MAX_PAGES
						|| ! is_array( $box )
						|| array_keys( $box ) !== [ 0, 'x', 1, 'y', 2, 'w', 3, 'h' ] ) {
						throw new \RuntimeException( 'Invalid physical PDF fragment metadata.' );
					}
					foreach ( [ 0 => 'x', 1 => 'y', 2 => 'w', 3 => 'h' ] as $index => $name ) {
						if ( ! is_numeric( $box[ $index ] ) || ! is_numeric( $box[ $name ] )
							|| ! is_finite( (float) $box[ $index ] )
							|| ! is_finite( (float) $box[ $name ] )
							|| (float) $box[ $index ] !== (float) $box[ $name ] ) {
							throw new \RuntimeException( 'Inconsistent PDF fragment coordinates.' );
						}
					}
					$x = (float) $box[0];
					$y = (float) $box[1];
					$w = (float) $box[2];
					$h = (float) $box[3];
					if ( $x < -6000 || $y < -6000 || $w <= 0 || $h <= 0
						|| $x > 6000 || $y > 6000 || $w > 6000 || $h > 6000 ) {
						throw new \RuntimeException( 'Out-of-range PDF fragment coordinates.' );
					}
					$left = max( 0.0, $x );
					$top = max( 0.0, $y );
					$right = min( $this->width, $x + $w );
					$bottom = min( $this->height, $y + $h );
					if ( $right <= $left || $bottom <= $top ) {
						throw new \RuntimeException( 'PDF fragment does not intersect its physical page.' );
					}
					$this->fragments[ $id ][] = [
						'page' => $page,
						'x' => round( $left, 3 ),
						'y' => round( $top, 3 ),
						'width' => round( $right - $left, 3 ),
						'height' => round( $bottom - $top, 3 ),
					];
				},
			],
		];
	}

	/**
	 * @return array{page_width_pt:float,page_height_pt:float,regions:array<string,list<array{page:int,x:float,y:float,width:float,height:float}>>}
	 */
	public function result( int $page_count ): array {
		if ( $page_count < 1 || $page_count > self::MAX_PAGES ) {
			throw new \RuntimeException( 'Invalid PDF geometry page count.' );
		}
		foreach ( $this->expected as $id => $_ ) {
			if ( empty( $this->fragments[ $id ] ) ) {
				throw new \RuntimeException( 'PDF semantic region has no visible fragment.' );
			}
			foreach ( $this->fragments[ $id ] as $box ) {
				if ( $box['page'] > $page_count ) {
					throw new \RuntimeException( 'PDF fragment references a nonexistent page.' );
				}
			}
			usort( $this->fragments[ $id ], static fn ( array $a, array $b ): int =>
				$a['page'] <=> $b['page'] ?: $a['y'] <=> $b['y'] );
		}
		ksort( $this->fragments, SORT_STRING );
		return [
			'page_width_pt' => $this->width,
			'page_height_pt' => $this->height,
			'regions' => $this->fragments,
		];
	}
}
