<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class SchemaValidator {
	private const MAX_TREE_DEPTH = 64;
	private const ENVELOPE_KEYS = [ 'schema_version', 'design_type', 'root' ];
	private const NODE_KEYS = [ 'type', 'provider', 'properties', 'children' ];

	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly DesignTypeRegistry $design_types
	) {}

	/** @param array<string,mixed> $payload */
	public function validate( array $payload, bool $allow_experimental = false ): Diagnostics {
		$diagnostics = new Diagnostics();
		$this->validate_envelope_keys( $payload, $diagnostics );

		$schema_version = $payload['schema_version'] ?? null;
		if ( ! is_int( $schema_version ) || $schema_version < 0 ) {
			$diagnostics->error( 'schema.invalid_version', 'schema_version must be a non-negative integer.', 'schema_version' );
		} elseif ( 0 === $schema_version && ! $allow_experimental ) {
			$diagnostics->error( 'schema.experimental_not_stable', 'Experimental schema version 0 requires explicit opt-in.', 'schema_version' );
		} elseif ( $schema_version > 0 ) {
			$diagnostics->error( 'schema.unsupported_stable_generation', 'No stable Design Foundation schema generation is registered.', 'schema_version' );
		}

		$design_type = $payload['design_type'] ?? null;
		if ( ! is_string( $design_type ) || ! Identifier::design_type( $design_type ) ) {
			$diagnostics->error( 'design_type.invalid', 'design_type must be a valid semantic identifier.', 'design_type' );
		} elseif ( null === $this->design_types->definition( $design_type ) ) {
			$diagnostics->error( 'design_type.unknown', 'Unknown design_type.', 'design_type' );
		}

		$root = $payload['root'] ?? null;
		if ( ! is_array( $root ) ) {
			$diagnostics->error( 'root.invalid', 'root must be an object.', 'root' );
		} elseif ( [] !== $root ) {
			$this->validate_node( $root, $diagnostics, 'root', 0 );
		}

		return $diagnostics;
	}

	/** @param array<string,mixed> $payload */
	private function validate_envelope_keys( array $payload, Diagnostics $diagnostics ): void {
		foreach ( self::ENVELOPE_KEYS as $required ) {
			if ( ! array_key_exists( $required, $payload ) ) {
				$diagnostics->error( 'schema.missing_key', 'Missing required DesignProject key.', $required );
			}
		}
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::ENVELOPE_KEYS, true ) ) {
				$diagnostics->error( 'schema.unknown_key', 'Unknown DesignProject key.', is_string( $key ) ? $key : 'design' );
			}
		}
	}

	/** @param array<string,mixed> $node */
	private function validate_node( array $node, Diagnostics $diagnostics, string $location, int $depth ): void {
		if ( $depth > self::MAX_TREE_DEPTH ) {
			$diagnostics->error( 'tree.depth_exceeded', 'Design tree exceeds the supported nesting depth.', $location );
			return;
		}

		foreach ( self::NODE_KEYS as $required ) {
			if ( ! array_key_exists( $required, $node ) ) {
				$diagnostics->error( 'node.missing_key', 'Missing required node key.', $location . '.' . $required );
			}
		}
		foreach ( array_keys( $node ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::NODE_KEYS, true ) ) {
				$diagnostics->error( 'node.unknown_key', 'Unknown node key.', $location );
			}
		}

		$type = $node['type'] ?? null;
		if ( ! is_string( $type ) || ! Identifier::node_type( $type ) ) {
			$diagnostics->error( 'node.invalid_type', 'Node type must be a valid declarative identifier.', $location . '.type' );
		}

		$provider = $node['provider'] ?? null;
		if ( ! is_string( $provider ) || ! Identifier::provider( $provider ) ) {
			$diagnostics->error( 'node.invalid_provider', 'Node provider must be a valid semantic provider identifier.', $location . '.provider' );
		} elseif ( null === $this->providers->resolve( $provider ) ) {
			$diagnostics->error( 'node.unknown_provider', 'Unknown node provider.', $location . '.provider' );
		}

		$properties = $node['properties'] ?? null;
		if ( ! is_array( $properties ) || ( [] !== $properties && array_is_list( $properties ) ) ) {
			$diagnostics->error( 'node.invalid_properties', 'Node properties must be an object.', $location . '.properties' );
		} elseif ( ! $this->is_json_value( $properties, 0 ) ) {
			$diagnostics->error( 'node.invalid_property_value', 'Node properties must contain JSON-compatible declarative values only.', $location . '.properties' );
		}

		$children = $node['children'] ?? null;
		if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
			$diagnostics->error( 'node.invalid_children', 'Node children must be a list.', $location . '.children' );
			return;
		}
		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) || [] === $child ) {
				$diagnostics->error( 'node.invalid_child', 'Every child must be a non-empty node object.', $location . '.children.' . $index );
				continue;
			}
			$this->validate_node( $child, $diagnostics, $location . '.children.' . $index, $depth + 1 );
		}
	}

	private function is_json_value( mixed $value, int $depth ): bool {
		if ( $depth > self::MAX_TREE_DEPTH ) {
			return false;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				return false;
			}
			if ( ! $this->is_json_value( $item, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}


	/**
	 * Stable v1 is shape-preserving and independent of the legacy opt-in v0
	 * Diagnostics API. The input MUST be the original parsed JSON object.
	 *
	 * @return array{valid:bool,diagnostics:list<array{code:string,pointer:string}>}
	 */
	public function validate_stable_v1(
		object $json_object,
		StableSchemaRegistry $definitions,
		string $profile_id,
		string $project_schema
	): array {
		$errors = [];
		$add = static function ( string $code, string $pointer ) use ( &$errors ): void {
			$errors[ $pointer . "\0" . $code ] = [ 'code' => $code, 'pointer' => $pointer ];
		};
		if ( ! $json_object instanceof \stdClass ) {
			$add( 'SCHEMA_NODE_INVALID', '' );
			return self::stable_result( $errors );
		}
		if ( ! $definitions->matches( $profile_id, $project_schema ) ) {
			$add( 'SCHEMA_DOMAIN_INVALID', '' );
			return self::stable_result( $errors );
		}

		$values = get_object_vars( $json_object );
		foreach ( [ 'schema_version', 'design_type', 'root' ] as $required ) {
			if ( ! array_key_exists( $required, $values ) ) {
				$add( 'SCHEMA_NODE_INVALID', '/' . $required );
			}
		}
		foreach ( array_keys( $values ) as $key ) {
			if ( ! in_array( $key, [ 'schema_version', 'design_type', 'root' ], true ) ) {
				$add( 'SCHEMA_NODE_INVALID', '/' . self::pointer_segment( $key ) );
			}
		}
		if ( ! isset( $values['schema_version'] ) || 1 !== $values['schema_version'] ) {
			$add( 'SCHEMA_UNSUPPORTED_GENERATION', '/schema_version' );
		}
		$design_type = $values['design_type'] ?? null;
		$type_definition = null;
		if ( ! is_string( $design_type ) || ! Identifier::design_type( $design_type )
			|| null === ( $type_definition = $definitions->design_type( $design_type ) ) ) {
			$add( 'SCHEMA_NODE_INVALID', '/design_type' );
		}
		$root = $values['root'] ?? null;
		if ( ! $root instanceof \stdClass ) {
			$add( 'SCHEMA_NODE_INVALID', '/root' );
		} elseif ( 1 === ( $values['schema_version'] ?? null ) ) {
			$seen = new \SplObjectStorage();
			$count = 0;
			$this->validate_stable_node( $root, '/root', null, $design_type, $type_definition, $definitions, $add, $seen, 0, $count );
		}
		return self::stable_result( $errors );
	}

	/**
	 * @param array<string,array{code:string,pointer:string}> $errors
	 * @return array{valid:bool,diagnostics:list<array{code:string,pointer:string}>}
	 */
	private static function stable_result( array $errors ): array {
		$ordered = array_values( $errors );
		usort(
			$ordered,
			static fn ( array $a, array $b ): int => strcmp( $a['pointer'], $b['pointer'] )
				?: strcmp( $a['code'], $b['code'] )
		);
		return [ 'valid' => [] === $ordered, 'diagnostics' => $ordered ];
	}

	private static function pointer_segment( string|int $segment ): string {
		return str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $segment );
	}

	/**
	 * @param callable(string,string):void $add
	 * @param array<string,mixed>|null $type_definition
	 */
	private function validate_stable_node(
		\stdClass $node,
		string $pointer,
		?string $parent_type,
		mixed $root_type,
		?array $type_definition,
		StableSchemaRegistry $definitions,
		callable $add,
		\SplObjectStorage $active,
		int $depth,
		int &$count
	): void {
		if ( $depth > self::MAX_TREE_DEPTH || ++$count > 100000 ) {
			$add( 'SCHEMA_LIMIT_EXCEEDED', $pointer );
			return;
		}
		if ( $active->contains( $node ) ) {
			$add( 'SCHEMA_NODE_INVALID', $pointer );
			return;
		}
		$active->attach( $node );
		try {
			$data = get_object_vars( $node );
			foreach ( self::NODE_KEYS as $key ) {
				if ( ! array_key_exists( $key, $data ) ) {
					$add( 'SCHEMA_NODE_INVALID', $pointer . '/' . $key );
				}
			}
			foreach ( array_keys( $data ) as $key ) {
				if ( ! in_array( $key, self::NODE_KEYS, true ) ) {
					$add( 'SCHEMA_NODE_INVALID', $pointer . '/' . self::pointer_segment( $key ) );
				}
			}
			$type = $data['type'] ?? null;
			$provider = $data['provider'] ?? null;
			$definition = is_string( $type ) ? $definitions->node_type( $type ) : null;
			if ( ! is_string( $type ) || ! Identifier::node_type( $type ) || null === $definition
				|| ( null === $parent_type && $type !== $root_type )
				|| ( null !== $parent_type && ! in_array( $parent_type, $definition['allowedParents'], true ) ) ) {
				$add( 'SCHEMA_NODE_INVALID', $pointer . '/type' );
			}
			if ( ! is_string( $provider ) || ! Identifier::provider( $provider )
				|| ! $definitions->has_canonical_provider( $provider ) ) {
				$add( 'SCHEMA_PROVIDER_UNKNOWN', $pointer . '/provider' );
			} elseif ( ( null !== $definition && $definition['provider'] !== $provider )
				|| ( null === $parent_type && null !== $type_definition
					&& $type_definition['ownerProvider'] !== $provider ) ) {
				$add( 'SCHEMA_NODE_INVALID', $pointer . '/provider' );
			}
			$properties = $data['properties'] ?? null;
			if ( ! $properties instanceof \stdClass ) {
				$add( 'SCHEMA_NODE_INVALID', $pointer . '/properties' );
			} elseif ( null !== $definition && is_string( $definition['propertiesSchemaId'] ) ) {
				$domain = $definitions->domain_schema( $definition['propertiesSchemaId'] );
				if ( null === $domain ) {
					$add( 'SCHEMA_DOMAIN_INVALID', $pointer . '/properties' );
				} else {
					$this->validate_domain_value( $properties, $domain['schema'], $pointer . '/properties', $add, new \SplObjectStorage(), 0 );
				}
			} elseif ( null !== $definition && [] !== get_object_vars( $properties ) ) {
				$add( 'SCHEMA_DOMAIN_INVALID', $pointer . '/properties' );
			}
			$children = $data['children'] ?? null;
			if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
				$add( 'SCHEMA_NODE_INVALID', $pointer . '/children' );
			} else {
				foreach ( $children as $index => $child ) {
					$child_pointer = $pointer . '/children/' . $index;
					if ( ! $child instanceof \stdClass ) {
						$add( 'SCHEMA_NODE_INVALID', $child_pointer );
						continue;
					}
					$this->validate_stable_node( $child, $child_pointer, is_string( $type ) ? $type : null,
						$root_type, $type_definition, $definitions, $add, $active, $depth + 1, $count );
				}
			}
		} finally {
			$active->detach( $node );
		}
	}

	/**
	 * A deliberately closed declarative JSON Schema subset. Unrecognized
	 * keywords fail closed until that schema vocabulary is jointly signed.
	 *
	 * @param array<string,mixed> $schema
	 * @param callable(string,string):void $add
	 */
	private function validate_domain_value(
		mixed $value,
		array $schema,
		string $pointer,
		callable $add,
		\SplObjectStorage $active,
		int $depth
	): void {
		if ( $depth > self::MAX_TREE_DEPTH ) {
			$add( 'SCHEMA_LIMIT_EXCEEDED', $pointer );
			return;
		}
		$allowed = [ 'type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength' ];
		if ( array_is_list( $schema ) || array_diff( array_keys( $schema ), $allowed ) ) {
			$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
			return;
		}
		$type = $schema['type'] ?? null;
		if ( ! is_string( $type ) || ! in_array( $type, [ 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ], true ) ) {
			$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
			return;
		}
		$matched = match ( $type ) {
			'object' => $value instanceof \stdClass,
			'array' => is_array( $value ) && array_is_list( $value ),
			'string' => is_string( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'integer' => is_int( $value ),
			'boolean' => is_bool( $value ),
			'null' => null === $value,
		};
		if ( ! $matched ) {
			$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
			return;
		}
		if ( ( is_int( $value ) || is_float( $value ) )
			&& ( ! is_finite( (float) $value ) || abs( (float) $value ) > 9007199254740991 ) ) {
			$add( 'SCHEMA_LIMIT_EXCEEDED', $pointer );
			return;
		}
		if ( isset( $schema['enum'] ) && ( ! is_array( $schema['enum'] )
			|| ! array_is_list( $schema['enum'] )
			|| ! in_array( $value, $schema['enum'], true ) ) ) {
			$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
		}
		if ( is_string( $value ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
			if ( ( isset( $schema['minLength'] ) && ( ! is_int( $schema['minLength'] ) || $length < $schema['minLength'] ) )
				|| ( isset( $schema['maxLength'] ) && ( ! is_int( $schema['maxLength'] ) || $length > $schema['maxLength'] ) ) ) {
				$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
			}
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( ( isset( $schema['minimum'] ) && ( ! is_numeric( $schema['minimum'] ) || $value < $schema['minimum'] ) )
				|| ( isset( $schema['maximum'] ) && ( ! is_numeric( $schema['maximum'] ) || $value > $schema['maximum'] ) ) ) {
				$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
			}
		}
		if ( is_array( $value ) ) {
			if ( ( isset( $schema['minItems'] ) && ( ! is_int( $schema['minItems'] ) || count( $value ) < $schema['minItems'] ) )
				|| ( isset( $schema['maxItems'] ) && ( ! is_int( $schema['maxItems'] ) || count( $value ) > $schema['maxItems'] ) ) ) {
				$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
			}
			if ( ! isset( $schema['items'] ) || ! is_array( $schema['items'] ) ) {
				$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
				return;
			}
			foreach ( $value as $index => $item ) {
				$this->validate_domain_value( $item, $schema['items'], $pointer . '/' . $index, $add, $active, $depth + 1 );
			}
		}
		if ( $value instanceof \stdClass ) {
			if ( $active->contains( $value ) ) {
				$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
				return;
			}
			$active->attach( $value );
			try {
				$properties = $schema['properties'] ?? [];
				$required = $schema['required'] ?? [];
				if ( ! is_array( $properties ) || ! is_array( $required ) || ! array_is_list( $required )
					|| ! is_bool( $schema['additionalProperties'] ?? false ) ) {
					$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
					return;
				}
				$data = get_object_vars( $value );
				foreach ( $required as $name ) {
					if ( ! is_string( $name ) ) {
						$add( 'SCHEMA_DOMAIN_INVALID', $pointer );
					} elseif ( ! array_key_exists( $name, $data ) ) {
						$add( 'SCHEMA_DOMAIN_INVALID', $pointer . '/' . self::pointer_segment( $name ) );
					}
				}
				foreach ( $data as $key => $child ) {
					$child_pointer = $pointer . '/' . self::pointer_segment( $key );
					if ( in_array( $key, [ '__proto__', 'prototype', 'constructor' ], true ) ) {
						$add( 'SCHEMA_DOMAIN_INVALID', $child_pointer );
					} elseif ( array_key_exists( $key, $properties ) && is_array( $properties[ $key ] ) ) {
						$this->validate_domain_value( $child, $properties[ $key ], $child_pointer, $add, $active, $depth + 1 );
					} elseif ( ! ( $schema['additionalProperties'] ?? false ) ) {
						$add( 'SCHEMA_DOMAIN_INVALID', $child_pointer );
					}
				}
			} finally {
				$active->detach( $value );
			}
		}
	}

}
