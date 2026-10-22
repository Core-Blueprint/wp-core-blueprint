<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

/** Canonical design-type declarations; never an executable profile registry. */
final class DesignTypeRegistry {
	/** @var array<string,array{id:string,owner_provider:string,allow_site_presentational:bool}> */
	private array $definitions = [];
	private bool $sealed = false;
	/** @var list<string> */
	private array $failures = [];

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register( string $id, string $owner_provider, bool $allow_site_presentational = false ): bool {
		$id = trim( $id );
		$owner_provider = trim( $owner_provider );
		if ( $this->sealed || ! Identifier::design_type( $id ) || ! $this->providers->has_canonical( $owner_provider ) ) {
			$this->failures[] = 'REGISTRY_INVALID';
			return false;
		}
		if ( isset( $this->definitions[ $id ] ) ) {
			$this->failures[] = 'REGISTRY_COLLISION';
			return false;
		}
		$this->definitions[ $id ] = [
			'id' => $id,
			'owner_provider' => $owner_provider,
			'allow_site_presentational' => $allow_site_presentational,
		];
		return true;
	}

	/** @return array{id:string,owner_provider:string,allow_site_presentational:bool}|null */
	public function definition( string $id ): ?array {
		return $this->definitions[ $id ] ?? null;
	}

	/** @return list<array{id:string,owner_provider:string,allow_site_presentational:bool}> */
	public function definitions(): array {
		$all = $this->definitions;
		ksort( $all, SORT_STRING );
		return array_values( $all );
	}

	/** Registration closes before graph publication; copied data are immutable by value. */
	/** @return list<string> */
	public function failures(): array {
		return $this->failures;
	}

	public function seal(): void {
		$this->sealed = true;
	}
}
