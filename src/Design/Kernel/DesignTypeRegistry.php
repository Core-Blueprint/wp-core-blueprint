<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class DesignTypeRegistry {
	/** @var array<string,array{id:string,owner_provider:string,allow_site_presentational:bool}> */
	private array $definitions = [];

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register( string $id, string $owner_provider, bool $allow_site_presentational = false ): bool {
		$id             = trim( $id );
		$owner_provider = trim( $owner_provider );
		if (
			! Identifier::design_type( $id )
			|| ! $this->providers->has_canonical( $owner_provider )
			|| isset( $this->definitions[ $id ] )
		) {
			return false;
		}
		$this->definitions[ $id ] = [
			'id'                        => $id,
			'owner_provider'            => $owner_provider,
			'allow_site_presentational' => $allow_site_presentational,
		];
		return true;
	}

	/** @return array{id:string,owner_provider:string,allow_site_presentational:bool}|null */
	public function definition( string $id ): ?array {
		return $this->definitions[ $id ] ?? null;
	}
}
