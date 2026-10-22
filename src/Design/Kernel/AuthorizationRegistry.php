<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * Trusted site/consumer grants, NOT a replacement for current actor/resource
 * checks in WordPress. No registry grant is ever a browser authorization token.
 */
final class AuthorizationRegistry {
	/** @var array<string,array<string,array<string,true>>> */
	private array $consumer_grants = [];
	/** @var array<string,array<string,array<string,true>>> */
	private array $site_grants = [];
	private bool $sealed = false;

	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly DesignTypeRegistry $design_types,
		private readonly CapabilityRegistry $capabilities
	) {}

	public function grant_consumer( string $design_type, string $provider, string $capability ): bool {
		$canonical = $this->grant_target( $design_type, $provider, $capability );
		if ( $this->sealed || null === $canonical ) {
			return false;
		}
		$this->consumer_grants[ $design_type ][ $canonical ][ $capability ] = true;
		return true;
	}

	public function grant_site( string $design_type, string $provider, string $capability ): bool {
		$definition = $this->design_types->definition( $design_type );
		$canonical = $this->grant_target( $design_type, $provider, $capability );
		if ( $this->sealed || null === $definition || null === $canonical || ! $definition['allow_site_presentational'] ) {
			return false;
		}
		if ( CapabilityClass::Presentation !== $this->capabilities->class_for( $canonical, $capability ) ) {
			return false;
		}
		$this->site_grants[ $design_type ][ $canonical ][ $capability ] = true;
		return true;
	}

	public function is_authorized( string $design_type, string $provider, string $capability ): bool {
		$canonical = $this->providers->resolve( $provider );
		if ( null === $canonical || null === $this->design_types->definition( $design_type ) || null === $this->capabilities->class_for( $canonical, $capability ) ) {
			return false;
		}
		return isset( $this->consumer_grants[ $design_type ][ $canonical ][ $capability ] )
			|| isset( $this->site_grants[ $design_type ][ $canonical ][ $capability ] );
	}

	public function is_authorized_canonical( string $design_type, string $provider, string $capability ): bool {
		return $this->providers->has_canonical( $provider )
			&& $this->is_authorized( $design_type, $provider, $capability );
	}

	/** @return list<array{designType:string,provider:string,capability:string,scope:string}> */
	public function definitions(): array {
		$result = [];
		foreach ( [ 'consumer' => $this->consumer_grants, 'site' => $this->site_grants ] as $scope => $types ) {
			foreach ( $types as $type => $providers ) {
				foreach ( $providers as $provider => $capabilities ) {
					foreach ( array_keys( $capabilities ) as $capability ) {
						$result[] = [ 'designType' => $type, 'provider' => $provider, 'capability' => $capability, 'scope' => $scope ];
					}
				}
			}
		}
		usort( $result, static fn( array $a, array $b ): int => strcmp( implode( "\0", array_values( $a ) ), implode( "\0", array_values( $b ) ) ) );
		return $result;
	}

	public function seal(): void {
		$this->sealed = true;
	}

	private function grant_target( string $design_type, string $provider, string $capability ): ?string {
		if ( null === $this->design_types->definition( $design_type ) ) {
			return null;
		}
		$canonical = $this->providers->resolve( $provider );
		if ( null === $canonical || null === $this->capabilities->class_for( $canonical, $capability ) ) {
			return null;
		}
		return $canonical;
	}
}
