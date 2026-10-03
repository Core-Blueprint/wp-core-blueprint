<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Flow;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded Flow presentation tokens.
 *
 * Presentation is deliberately not a generic style system. It exposes only
 * values the Flow renderer owns and validates itself, so consumers cannot
 * inject arbitrary CSS into canonical document output.
 */
final readonly class Presentation {
	private const DEFAULT_ACCENT = '#111111';
	private const SURFACE_STYLES = [ 'cards', 'flat' ];
	private const DENSITIES      = [ 'compact', 'comfortable' ];
	private const CORNER_STYLES  = [ 'square', 'soft', 'rounded' ];
	private const TEXT_SCALES    = [ 'compact', 'standard', 'large' ];

	private function __construct(
		private string $accent,
		private string $surface_style,
		private string $density,
		private string $corner_style,
		private string $text_scale
	) {}

	public static function defaults(): self {
		return new self( self::DEFAULT_ACCENT, 'cards', 'comfortable', 'soft', 'standard' );
	}

	public static function from_accent( string $accent ): self {
		return self::from_values( $accent );
	}

	public static function from_values(
		string $accent,
		string $surface_style = 'cards',
		string $density = 'comfortable',
		string $corner_style = 'soft',
		string $text_scale = 'standard'
	): self {
		$accent = strtolower( trim( $accent ) );
		if ( 1 === preg_match( '/^#[0-9a-f]{3}$/', $accent ) ) {
			$accent = '#' . $accent[1] . $accent[1] . $accent[2] . $accent[2] . $accent[3] . $accent[3];
		}
		if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $accent ) ) {
			throw new \InvalidArgumentException( 'Flow presentation accent must be a hexadecimal colour.' );
		}
		if ( ! in_array( $surface_style, self::SURFACE_STYLES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Flow surface style.' );
		}
		if ( ! in_array( $density, self::DENSITIES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Flow density.' );
		}
		if ( ! in_array( $corner_style, self::CORNER_STYLES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Flow corner style.' );
		}
		if ( ! in_array( $text_scale, self::TEXT_SCALES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Flow text scale.' );
		}
		return new self( $accent, $surface_style, $density, $corner_style, $text_scale );
	}

	public function accent(): string { return $this->accent; }
	public function surface_style(): string { return $this->surface_style; }
	public function density(): string { return $this->density; }
	public function corner_style(): string { return $this->corner_style; }
	public function text_scale(): string { return $this->text_scale; }
}
