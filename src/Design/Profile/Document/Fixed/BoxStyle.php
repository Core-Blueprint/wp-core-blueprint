<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

defined( 'ABSPATH' ) || exit;

final readonly class BoxStyle {
	private const BORDER_STYLES = [ 'solid', 'dashed', 'dotted' ];

	public function __construct(
		private bool $fill_enabled = false,
		private string $fill_color = '#ffffff',
		private bool $border_enabled = false,
		private float $border_width_mm = 0.2,
		private string $border_color = '#111111',
		private string $border_style = 'solid',
	) {
		if ( 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $this->fill_color ) ) {
			throw new \InvalidArgumentException( 'Fixed box fill color must be a six-digit hexadecimal color.' );
		}
		if ( ! is_finite( $this->border_width_mm ) || $this->border_width_mm < 0.0 || $this->border_width_mm > 20.0 ) {
			throw new \InvalidArgumentException( 'Fixed box border width must be between 0 and 20 millimetres.' );
		}
		if ( 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $this->border_color ) ) {
			throw new \InvalidArgumentException( 'Fixed box border color must be a six-digit hexadecimal color.' );
		}
		if ( ! in_array( $this->border_style, self::BORDER_STYLES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Fixed box border style.' );
		}
	}

	public function fill_enabled(): bool { return $this->fill_enabled; }
	public function fill_color(): string { return strtolower( $this->fill_color ); }
	public function border_enabled(): bool { return $this->border_enabled; }
	public function border_width_mm(): float { return $this->border_width_mm; }
	public function border_color(): string { return strtolower( $this->border_color ); }
	public function border_style(): string { return $this->border_style; }
}
