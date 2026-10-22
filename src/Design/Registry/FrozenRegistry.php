<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The single value-only, request-immutable registry graph. Every read returns
 * copied arrays; browser fingerprints are data integrity, never actor authority.
 */
final class FrozenRegistry {
	private readonly array $graph;
	private readonly string $fingerprint;
	private readonly array $project_snapshots;
	private readonly array $field_facets;

	/** @param array<string,mixed> $graph Compiler-validated, canonical declarations. */
	public function __construct( array $graph ) {
		if ( ! self::pure( $graph ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
		$this->graph = $graph;
		$this->fingerprint = self::digest( $graph, 'FrozenRegistryV1' );
		$snapshots = [];
		$facets = [];
		foreach ( $graph['profiles'] as $profile ) {
			$id = $profile['id'];
			$snapshot = $profile['schemaSnapshot'];
			$snapshot['fingerprint'] = self::digest( $snapshot, 'RegistrySnapshotV1' );
			$snapshots[ $id ] = $snapshot;
			$facet = [
				'contractVersion' => 1,
				'schemaGeneration' => 1,
				'profileId' => $id,
				'projectSchema' => $profile['projectSchema'],
				'registryFingerprint' => $this->fingerprint,
				'projectSchemaFingerprint' => $snapshot['fingerprint'],
				'fieldDescriptors' => $profile['persistedFieldDefinitions'],
			];
			$facet['fingerprint'] = self::digest( $facet, 'PersistedFieldFacetV1' );
			$facets[ $id ] = $facet;
		}
		$this->project_snapshots = $snapshots;
		$this->field_facets = $facets;
	}

	public function fingerprint(): string {
		return $this->fingerprint;
	}

	/** Complete trusted graph, no PHP objects or callables. */
	public function graph(): array {
		return $this->graph;
	}

	/** @return array<string,mixed>|null */
	public function profile( string $id ): ?array {
		foreach ( $this->graph['profiles'] as $profile ) {
			if ( $profile['id'] === $id ) {
				return $profile;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null Exact closed, project-only schema snapshot. */
	public function project_schema_snapshot( string $profile_id ): ?array {
		return $this->project_snapshots[ $profile_id ] ?? null;
	}

	/** @return array<string,mixed>|null Exact StableSchemaFieldsV1 read-only facet. */
	public function persisted_field_facet( string $profile_id ): ?array {
		return $this->field_facets[ $profile_id ] ?? null;
	}

	/** @return list<array<string,mixed>> */
	public function modules(): array {
		return $this->graph['modules'];
	}

	/** Canonical, domain-separated, length-prefixed UTF-8 value encoding. */
	public static function digest( array $value, string $domain ): string {
		return 'sha256:' . hash( 'sha256', self::encode( $domain ) . self::encode( $value ) );
	}

	private static function encode( mixed $value ): string {
		if ( null === $value ) {
			return 'n';
		}
		if ( is_bool( $value ) ) {
			return $value ? 't' : 'f';
		}
		if ( is_int( $value ) ) {
			return 'i' . strlen( (string) $value ) . ':' . $value;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			$encoded = json_encode( $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
			return 'd' . strlen( $encoded ) . ':' . $encoded;
		}
		if ( is_string( $value ) ) {
			return 's' . strlen( $value ) . ':' . $value;
		}
		if ( ! is_array( $value ) ) {
			throw new \DomainException( 'REGISTRY_INVALID' );
		}
		if ( array_is_list( $value ) ) {
			$output = 'a' . count( $value ) . ':';
			foreach ( $value as $item ) {
				$output .= self::encode( $item );
			}
			return $output;
		}
		ksort( $value, SORT_STRING );
		$output = 'o' . count( $value ) . ':';
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				throw new \DomainException( 'REGISTRY_INVALID' );
			}
			$output .= self::encode( $key ) . self::encode( $item );
		}
		return $output;
	}

	/** No PHP objects, resources, recursive arrays or executable values in transport. */
	public static function pure( mixed $value, int $depth = 0 ): bool {
		if ( $depth > 64 ) {
			return false;
		}
		if ( null === $value || is_string( $value ) || is_bool( $value ) || is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $child ) {
			if ( ( ! is_int( $key ) && ! is_string( $key ) ) || ! self::pure( $child, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}
}
