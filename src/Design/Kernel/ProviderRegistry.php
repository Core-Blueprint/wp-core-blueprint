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
		if ( ! Identifier::provider( $id ) || isset( $this->providers[ $id ] ) || isset( $this->aliases[ $id ] ) ) {
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
			|| ! Identifier::provider( $canonical_provider )
			|| ! isset( $this->providers[ $canonical_provider ] )
			|| isset( $this->providers[ $alias ] )
			|| isset( $this->aliases[ $alias ] )
		) {
			return false;
		}
		$this->aliases[ $alias ] = $canonical_provider;
		return true;
	}

	/**
	 * Alias resolution is historical/explicit-migration only. Stable schema v1
	 * admits an ID exclusively through has_canonical(), never resolve().
	 */
	public function resolve( string $id ): ?string {
		if ( isset( $this->providers[ $id ] ) ) {
			return $id;
		}
		return $this->aliases[ $id ] ?? null;
	}

	public function has_canonical( string $id ): bool {
		return isset( $this->providers[ $id ] );
	}

	/** @return list<string> Defensive canonical-only sorted copy. */
	public function providers(): array {
		$ids = array_keys( $this->providers );
		sort( $ids, SORT_STRING );
		return $ids;
	}
}
