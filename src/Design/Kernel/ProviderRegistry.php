<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
	/** @var array<string,true> */
	private array $providers = [];

	/** @var array<string,string> alias => canonical provider */
	private array $aliases = [];

	public function register( string $id ): bool {
		$id = trim( $id );
		if ( ! Identifier::provider( $id ) || isset( $this->providers[ $id ], $this->aliases[ $id ] ) ) {
			return false;
		}
		$this->providers[ $id ] = true;
		return true;
	}

	public function register_alias( string $alias, string $canonical_provider ): bool {
		$alias              = trim( $alias );
		$canonical_provider = trim( $canonical_provider );
		if (
			! Identifier::provider( $alias )
			|| ! isset( $this->providers[ $canonical_provider ] )
			|| isset( $this->providers[ $alias ], $this->aliases[ $alias ] )
		) {
			return false;
		}
		$this->aliases[ $alias ] = $canonical_provider;
		return true;
	}

	public function resolve( string $id ): ?string {
		if ( isset( $this->providers[ $id ] ) ) {
			return $id;
		}
		return $this->aliases[ $id ] ?? null;
	}

	public function has_canonical( string $id ): bool {
		return isset( $this->providers[ $id ] );
	}

	/** @return list<string> */
	public function providers(): array {
		return array_keys( $this->providers );
	}
}
