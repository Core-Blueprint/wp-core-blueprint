<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class CapabilityRegistry {
	/** @var array<string,array<string,CapabilityClass>> */
	private array $definitions = [];

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register( string $provider, string $capability, CapabilityClass $class ): bool {
		$provider   = trim( $provider );
		$capability = trim( $capability );
		if (
			! $this->providers->has_canonical( $provider )
			|| ! Identifier::capability( $capability )
			|| isset( $this->definitions[ $provider ][ $capability ] )
		) {
			return false;
		}
		$this->definitions[ $provider ][ $capability ] = $class;
		return true;
	}

	public function class_for( string $provider, string $capability ): ?CapabilityClass {
		$canonical = $this->providers->resolve( $provider );
		return null !== $canonical ? ( $this->definitions[ $canonical ][ $capability ] ?? null ) : null;
	}
}
