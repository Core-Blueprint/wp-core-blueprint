<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

defined( 'ABSPATH' ) || exit;

final readonly class TextStyle {
	private const FAMILIES = [ 'DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono' ];
	private const ALIGNMENTS = [ 'left', 'center', 'right' ];
	private const OVERFLOWS = [ 'wrap', 'clip' ];
	private const TRANSFORMS = [ 'none', 'uppercase', 'lowercase' ];

	public function __construct(
		private string $font_family = 'DejaVu Sans',
		private float $font_size_pt = 10.0,
		private int $font_weight = 400,
		private float $line_height = 1.25,
		private float $letter_spacing_em = 0.0,
		private string $alignment = 'left',
		private string $color = '#111111',
		private string $overflow = 'wrap',
		private string $text_transform = 'none',
	) {
		if ( ! in_array( $this->font_family, self::FAMILIES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Fixed text font family.' );
		}
		if ( ! is_finite( $this->font_size_pt ) || $this->font_size_pt < 1.0 || $this->font_size_pt > 200.0 ) {
			throw new \InvalidArgumentException( 'Fixed text font size must be between 1 and 200 points.' );
		}
		if ( $this->font_weight < 100 || $this->font_weight > 900 ) {
			throw new \InvalidArgumentException( 'Fixed text font weight must be between 100 and 900.' );
		}
		if ( ! is_finite( $this->line_height ) || $this->line_height < 0.8 || $this->line_height > 3.0 ) {
			throw new \InvalidArgumentException( 'Fixed text line height must be between 0.8 and 3.0.' );
		}
		if ( ! is_finite( $this->letter_spacing_em ) || $this->letter_spacing_em < -0.2 || $this->letter_spacing_em > 1.0 ) {
			throw new \InvalidArgumentException( 'Fixed text letter spacing must be between -0.2 and 1.0 em.' );
		}
		if ( ! in_array( $this->alignment, self::ALIGNMENTS, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Fixed text alignment.' );
		}
		if ( 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $this->color ) ) {
			throw new \InvalidArgumentException( 'Fixed text color must be a six-digit hexadecimal color.' );
		}
		if ( ! in_array( $this->overflow, self::OVERFLOWS, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Fixed text overflow mode.' );
		}
		if ( ! in_array( $this->text_transform, self::TRANSFORMS, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Fixed text transform.' );
		}
	}

	public function font_family(): string { return $this->font_family; }
	public function font_size_pt(): float { return $this->font_size_pt; }
	public function font_weight(): int { return $this->font_weight; }
	public function line_height(): float { return $this->line_height; }
	public function letter_spacing_em(): float { return $this->letter_spacing_em; }
	public function alignment(): string { return $this->alignment; }
	public function color(): string { return strtolower( $this->color ); }
	public function overflow(): string { return $this->overflow; }
	public function text_transform(): string { return $this->text_transform; }
}
