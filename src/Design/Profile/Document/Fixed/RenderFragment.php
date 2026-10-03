<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

use CoreBlueprint\Core\Design\Profile\Document\Render\ImageDataUri;

defined( 'ABSPATH' ) || exit;

final readonly class RenderFragment {
	private const MAX_TEXT_BYTES = 65536;

	/** @param array{x:float,y:float,width:float,height:float} $frame */
	private function __construct(
		private string $type,
		private array $frame,
		private string $payload,
		private TextStyle|BoxStyle|ImageStyle|null $style = null,
	) {}

	/** @param array<string,mixed> $frame */
	public static function text( array $frame, string $text, ?TextStyle $style = null ): self {
		$normalized = self::normalize_frame( $frame );
		if ( strlen( $text ) > self::MAX_TEXT_BYTES ) {
			throw new \InvalidArgumentException( 'Fixed text fragment exceeds the supported size.' );
		}
		return new self( 'text', $normalized, $text, $style );
	}

	/** @param array<string,mixed> $frame */
	public static function image( array $frame, string $data_uri, ?ImageStyle $style = null ): self {
		return new self( 'image', self::normalize_frame( $frame ), ImageDataUri::assert_valid( $data_uri ), $style );
	}

	/** @param array<string,mixed> $frame */
	public static function box( array $frame, BoxStyle $style ): self {
		return new self( 'box', self::normalize_frame( $frame ), '', $style );
	}

	public function type(): string { return $this->type; }
	/** @return array{x:float,y:float,width:float,height:float} */
	public function frame(): array { return $this->frame; }
	public function payload(): string { return $this->payload; }
	public function style(): TextStyle|BoxStyle|ImageStyle|null { return $this->style; }

	/** @param array<string,mixed> $frame @return array{x:float,y:float,width:float,height:float} */
	private static function normalize_frame( array $frame ): array {
		$normalized = Geometry::frame( [ Contract::NODE_FRAME_KEY => $frame ] );
		if ( null === $normalized ) {
			throw new \InvalidArgumentException( 'Invalid Fixed render fragment frame.' );
		}
		foreach ( array_keys( $frame ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, Contract::FRAME_KEYS, true ) ) {
				throw new \InvalidArgumentException( 'Unknown Fixed render fragment frame key.' );
			}
		}
		return $normalized;
	}
}
