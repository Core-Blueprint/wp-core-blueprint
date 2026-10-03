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
}
