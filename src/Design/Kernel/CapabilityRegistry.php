<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class CapabilityRegistry {
	/** @var array<string,array<string,CapabilityClass>> */
	private array $definitions = [];
	private bool $sealed = false;
	/** @var list<string> */
	private array $failures = [];

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register( string $provider, string $capability, CapabilityClass $class ): bool {
		$provider = trim( $provider );
		$capability = trim( $capability );
		if ( $this->sealed || ! $this->providers->has_canonical( $provider ) || ! Identifier::capability( $capability ) ) {
			$this->failures[] = 'REGISTRY_INVALID';
			return false;
		}
		if ( isset( $this->definitions[ $provider ][ $capability ] ) ) {
			$this->failures[] = 'REGISTRY_COLLISION';
			return false;
		}
		$this->definitions[ $provider ][ $capability ] = $class;
		return true;
	}

	public function class_for( string $provider, string $capability ): ?CapabilityClass {
		$canonical = $this->providers->resolve( $provider );
		return null !== $canonical ? ( $this->definitions[ $canonical ][ $capability ] ?? null ) : null;
	}

	/** Canonical-only read for strict generation 1; legacy class_for() retains alias semantics. */
	public function canonical_class_for( string $provider, string $capability ): ?CapabilityClass {
		return $this->providers->has_canonical( $provider ) ? ( $this->definitions[ $provider ][ $capability ] ?? null ) : null;
	}

	/** @return list<array{provider:string,id:string,class:string}> */
	public function definitions(): array {
		$result = [];
		foreach ( $this->definitions as $provider => $entries ) {
			foreach ( $entries as $id => $class ) {
				$result[] = [ 'provider' => $provider, 'id' => $id, 'class' => $class->value ];
			}
		}
		usort( $result, static fn( array $a, array $b ): int => strcmp( $a['provider'] . "\0" . $a['id'], $b['provider'] . "\0" . $b['id'] ) );
		return $result;
	}

	/** @return list<string> */
	public function failures(): array {
		return $this->failures;
	}

	public function seal(): void {
		$this->sealed = true;
	}
}
