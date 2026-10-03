<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Flow;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded table-column presentation metadata.
 *
 * Consumers can choose only alignment, a positive relative weight and whether
 * cell contents may wrap. No arbitrary CSS or style values cross the boundary.
 */
final readonly class TableColumn {
	private const MIN_WEIGHT = 0.1;
	private const MAX_WEIGHT = 100.0;

	private function __construct(
		private string $alignment,
		private float $weight,
		private bool $nowrap
	) {}

	public static function left( float $weight = 1.0, bool $nowrap = false ): self {
		return self::make( 'left', $weight, $nowrap );
	}

	public static function center( float $weight = 1.0, bool $nowrap = false ): self {
		return self::make( 'center', $weight, $nowrap );
	}

	public static function right( float $weight = 1.0, bool $nowrap = false ): self {
		return self::make( 'right', $weight, $nowrap );
	}

	public function alignment(): string { return $this->alignment; }
	public function weight(): float { return $this->weight; }
	public function nowrap(): bool { return $this->nowrap; }

	private static function make( string $alignment, float $weight, bool $nowrap ): self {
		if ( ! is_finite( $weight ) || $weight < self::MIN_WEIGHT || $weight > self::MAX_WEIGHT ) {
			throw new \InvalidArgumentException( 'Flow table column weight is outside the supported range.' );
		}
		return new self( $alignment, $weight, $nowrap );
	}
}
