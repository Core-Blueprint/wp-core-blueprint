<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

/** Canonical design-type declarations; never an executable profile registry. */
final class DesignTypeRegistry {
	/** @var array<string,array{id:string,owner_provider:string,allow_site_presentational:bool}> */
	private array $definitions = [];
	private bool $sealed = false;

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register( string $id, string $owner_provider, bool $allow_site_presentational = false ): bool {
		if ( $this->sealed ) {
			return false;
		}
		$id = trim( $id );
		$owner_provider = trim( $owner_provider );
		if ( ! Identifier::design_type( $id ) || ! $this->providers->has_canonical( $owner_provider ) || isset( $this->definitions[ $id ] ) ) {
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
	public function seal(): void {
		$this->sealed = true;
	}
}
