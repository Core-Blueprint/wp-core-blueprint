<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

defined( 'ABSPATH' ) || exit;

final readonly class ImageStyle {
	private const FITS = [ 'stretch', 'contain', 'cover' ];

	public function __construct(
		private float $aspect_ratio,
		private string $fit = 'stretch',
	) {
		if ( ! is_finite( $this->aspect_ratio ) || $this->aspect_ratio < 0.01 || $this->aspect_ratio > 100.0 ) {
			throw new \InvalidArgumentException( 'Fixed image aspect ratio must be between 0.01 and 100.' );
		}
		if ( ! in_array( $this->fit, self::FITS, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Fixed image fit mode.' );
		}
	}

	public function aspect_ratio(): float { return $this->aspect_ratio; }
	public function fit(): string { return $this->fit; }
}
