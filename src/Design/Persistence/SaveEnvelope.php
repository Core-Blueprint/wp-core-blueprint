<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, jointly captured A2 Save intent. Construct only after trusted
 * server-side project/field validation; this object grants no authorization.
 *
 * The canonical encoder is deliberately owned by this one module. Until the
 * StableSchemaFieldsV1 cross-language numeric/byte ABI is frozen, callers must
 * supply integer-safe JSON values; non-integral floats fail closed.
 *
 * @internal
 */
final class SaveEnvelope {
	public readonly int $blog_id;
	public readonly int $actor_id;
	public readonly string $resource_type;
	public readonly string $resource_key;
	public readonly string $profile_id;
	public readonly string $project_schema;
	/** Exact FrozenRegistryV1 external identity, including the sha256: prefix. */
	public readonly string $registry_fingerprint;
	public readonly string $operation_id;
	public readonly string $expected_revision;
	public readonly object $project;
	public readonly object $fields;
	public readonly string $project_digest;
	public readonly string $fields_digest;
	public readonly string $request_digest;

	public function __construct(
		int $blog_id,
		int $actor_id,
		string $resource_type,
		string $resource_key,
		string $profile_id,
		string $project_schema,
		string $registry_fingerprint,
		string $operation_id,
		string $expected_revision,
		object $project,
		object $fields
	) {
		if ( $blog_id < 1 || $actor_id < 1
			|| ! self::label( $resource_type, 48 )
			|| ! self::label( $resource_key, 191 )
			|| ! self::label( $profile_id, 128 )
			|| ! self::label( $project_schema, 128 )
			|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $registry_fingerprint )
			|| ! preg_match( '/^[A-Za-z0-9_-]{22,128}$/D', $operation_id )
			|| ! self::decimal( $expected_revision ) ) {
			throw new \InvalidArgumentException( 'SAVE_INVALID: invalid save identity.' );
		}
		$this->blog_id = $blog_id;
		$this->actor_id = $actor_id;
		$this->resource_type = $resource_type;
		$this->resource_key = $resource_key;
		$this->profile_id = $profile_id;
		$this->project_schema = $project_schema;
		$this->registry_fingerprint = $registry_fingerprint;
		$this->operation_id = $operation_id;
		$this->expected_revision = $expected_revision;

		// Re-decode complete canonical JSON: caller cannot mutate our captured data.
		$this->project = self::decode_object( self::canonical_json( $project ) );
		$this->fields = self::decode_object( self::canonical_json( $fields ) );
		$this->project_digest = hash( 'sha256', self::canonical_json( $this->project ) );
		$this->fields_digest = hash( 'sha256', self::canonical_json( $this->fields ) );
		$this->request_digest = self::digest( $this->project, $this->fields );
	}

	public function digest( object $project, object $fields ): string {
		return hash( 'sha256', self::canonical_json( (object) [
			'contractVersion' => 1,
			'actorId' => (string) $this->actor_id,
			'blogId' => (string) $this->blog_id,
			'expectedStoreRevision' => $this->expected_revision,
			'fields' => $fields,
			'operationId' => $this->operation_id,
			'profileId' => $this->profile_id,
			'project' => $project,
			'projectSchema' => $this->project_schema,
			'registryFingerprint' => $this->registry_fingerprint,
			'resourceKey' => $this->resource_key,
			'resourceType' => $this->resource_type,
		] ) );
	}

	/**
	 * Deterministic, type-preserving PHP encoding for JSON objects and arrays.
	 * Fractional/exponent floats remain blocked pending numeric parity sign-off.
	 */
	public static function canonical_json( mixed $value ): string {
		return self::encode_node( $value, 0 );
	}

	private static function encode_node( mixed $value, int $depth ): string {
		if ( $depth > 64 ) {
			throw new \InvalidArgumentException( 'SCHEMA_LIMIT_EXCEEDED: JSON nesting.' );
		}
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			$keys = array_map( 'strval', array_keys( $properties ) );
			usort( $keys, 'strcmp' ); // Exact binary UTF-8 ordering, not locale.
			$pairs = [];
			foreach ( $keys as $key ) {
				if ( in_array( $key, [ '__proto__', 'prototype', 'constructor' ], true ) ) {
					throw new \InvalidArgumentException( 'SCHEMA_DOMAIN_INVALID: unsafe JSON key.' );
				}
				$pairs[] = self::encode_scalar( $key ) . ':' . self::encode_node( $properties[$key], $depth + 1 );
			}
			return '{' . implode( ',', $pairs ) . '}';
		}
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				throw new \InvalidArgumentException( 'SCHEMA_DOMAIN_INVALID: associative JSON array.' );
			}
			$entries = [];
			foreach ( $value as $item ) {
				$entries[] = self::encode_node( $item, $depth + 1 );
			}
			return '[' . implode( ',', $entries ) . ']';
		}
		if ( is_float( $value ) ) {
			throw new \InvalidArgumentException( 'SCHEMA_DOMAIN_INVALID: numeric ABI not frozen.' );
		}
		if ( is_int( $value ) && abs( (float) $value ) > 9007199254740991 ) {
			throw new \InvalidArgumentException( 'SCHEMA_LIMIT_EXCEEDED: unsafe JSON integer.' );
		}
		if ( ! is_null( $value ) && ! is_bool( $value ) && ! is_int( $value ) && ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'SCHEMA_DOMAIN_INVALID: non-JSON value.' );
		}
		return self::encode_scalar( $value );
	}

	private static function encode_scalar( string|int|bool|null $value ): string {
		return json_encode(
			$value,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
		);
	}

	public static function decode_object( string $json ): object {
		$value = json_decode( $json, false, 128, JSON_THROW_ON_ERROR );
		if ( ! $value instanceof \stdClass ) {
			throw new \InvalidArgumentException( 'SCHEMA_DOMAIN_INVALID: JSON object required.' );
		}
		return $value;
	}

	public static function decimal( string $revision ): bool {
		if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $revision ) ) {
			return false;
		}
		$maximum = (string) PHP_INT_MAX;
		return strlen( $revision ) < strlen( $maximum )
			|| ( strlen( $revision ) === strlen( $maximum ) && strcmp( $revision, $maximum ) <= 0 );
	}

	private static function label( string $label, int $max ): bool {
		return strlen( $label ) <= $max
			&& 1 === preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D', $label );
	}
}
