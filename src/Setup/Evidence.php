<?php
declare(strict_types=1);
/**
 * Immutable evidence snapshot for one Core Setup check.
 *
 * Evidence describes current canonical state. It deliberately does not contain
 * a Core Setup review decision. Review intent is persisted separately by the
 * ReviewRepository so Core Setup never becomes a second settings store.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;
defined( 'ABSPATH' ) || exit;

final class Evidence {

	public const HEALTH_OK          = 'ok';
	public const HEALTH_ATTENTION   = 'attention';
	public const HEALTH_UNAVAILABLE = 'unavailable';
	public const HEALTH_STATES      = [ self::HEALTH_OK, self::HEALTH_ATTENTION, self::HEALTH_UNAVAILABLE ];

	/**
	 * @param array<string,mixed> $fingerprint_data Non-secret canonical state only.
	 * @param array<string,mixed> $context          Non-persisted presentation context.
	 */
	public function __construct(
		private readonly string $health,
		private readonly string $code,
		private readonly array $fingerprint_data,
		private readonly array $context = []
	) {
		if ( ! in_array( $this->health, self::HEALTH_STATES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid Core Setup evidence health.' );
		}
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $this->code ) ) {
			throw new \InvalidArgumentException( 'Invalid Core Setup evidence code.' );
		}

		Fingerprint::hash( $this->fingerprint_data );
	}

	public static function unavailable( string $code = 'setup.evidence-unavailable' ): self {
		return new self(
			self::HEALTH_UNAVAILABLE,
			$code,
			[ 'available' => false ]
		);
	}

	public function health(): string {
		return $this->health;
	}

	public function code(): string {
		return $this->code;
	}

	/** @return array<string,mixed> */
	public function fingerprint_data(): array {
		return $this->fingerprint_data;
	}

	/** @return array<string,mixed> */
	public function context(): array {
		return $this->context;
	}

	public function fingerprint(): string {
		return Fingerprint::hash( $this->fingerprint_data );
	}
}
