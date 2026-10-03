<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class AuthorizationRegistry {
	/** @var array<string,array<string,array<string,true>>> */
	private array $consumer_grants = [];

	/** @var array<string,array<string,array<string,true>>> */
	private array $site_grants = [];

	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly DesignTypeRegistry $design_types,
		private readonly CapabilityRegistry $capabilities
	) {}

	public function grant_consumer( string $design_type, string $provider, string $capability ): bool {
		$canonical = $this->grant_target( $design_type, $provider, $capability );
		if ( null === $canonical ) {
			return false;
		}
		$this->consumer_grants[ $design_type ][ $canonical ][ $capability ] = true;
		return true;
	}

	public function grant_site( string $design_type, string $provider, string $capability ): bool {
		$definition = $this->design_types->definition( $design_type );
		$canonical  = $this->grant_target( $design_type, $provider, $capability );
		if ( null === $definition || null === $canonical || ! $definition['allow_site_presentational'] ) {
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
		if (
			null === $canonical
			|| null === $this->design_types->definition( $design_type )
			|| null === $this->capabilities->class_for( $canonical, $capability )
		) {
			return false;
		}
		return isset( $this->consumer_grants[ $design_type ][ $canonical ][ $capability ] )
			|| isset( $this->site_grants[ $design_type ][ $canonical ][ $capability ] );
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
