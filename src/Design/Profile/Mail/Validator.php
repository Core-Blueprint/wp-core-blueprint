<?php
declare(strict_types=1);
/**
 * Structural validator for Design Foundation Mail projects.
 *
 * Domain extensions may register additional node type/provider pairs through
 * `cb_core_design_mail_node_types`. The Mail profile remains authoritative for
 * tree/envelope safety while extension code owns its domain-specific semantics.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Design\Profile\Mail;

use CoreBlueprint\Core\Design\Kernel\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Validator {
	private const MAX_TREE_DEPTH = 64;

	/** @param array<string,mixed> $project */
	public function validate( array $project ): Diagnostics {
		$diagnostics = new Diagnostics();

		if ( Contract::DESIGN_TYPE !== ( $project['design_type'] ?? null ) ) {
			$diagnostics->error( 'mail.invalid_design_type', 'Mail project has an invalid design_type.', 'design_type' );
		}
		if ( ! isset( $project['schema_version'] ) || ! is_int( $project['schema_version'] ) || $project['schema_version'] < 0 ) {
			$diagnostics->error( 'mail.invalid_schema_version', 'Mail project requires a non-negative schema_version.', 'schema_version' );
		}

		$root = $project['root'] ?? null;
		if ( ! is_array( $root ) || [] === $root ) {
			$diagnostics->error( 'mail.invalid_root', 'Mail project requires a root node.', 'root' );
			return $diagnostics;
		}
		if ( Contract::ROOT_TYPE !== ( $root['type'] ?? null ) || Contract::CORE_PROVIDER !== ( $root['provider'] ?? null ) ) {
			$diagnostics->error( 'mail.invalid_root_type', 'Mail project root must be the Base mail root.', 'root' );
		}

		$this->validate_node( $root, $diagnostics, 'root', 0 );
		return $diagnostics;
	}

	/** @param array<string,mixed> $node */
	private function validate_node( array $node, Diagnostics $diagnostics, string $location, int $depth ): void {
		if ( $depth > self::MAX_TREE_DEPTH ) {
			$diagnostics->error( 'mail.tree_depth', 'Mail project exceeds the supported tree depth.', $location );
			return;
		}

		$type = isset( $node['type'] ) && is_string( $node['type'] ) ? trim( $node['type'] ) : '';
		$provider = isset( $node['provider'] ) && is_string( $node['provider'] ) ? trim( $node['provider'] ) : '';
		if ( 1 !== preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/', $type ) ) {
			$diagnostics->error( 'mail.node_type', 'Mail node type is invalid.', $location . '.type' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $provider ) ) {
			$diagnostics->error( 'mail.node_provider', 'Mail node provider is invalid.', $location . '.provider' );
		}

		$allowed = array_fill_keys( Contract::CORE_NODE_TYPES, Contract::CORE_PROVIDER );
		/**
		 * Filter allowed Mail node type/provider pairs.
		 *
		 * Extensions append `node.type => provider-id`; Base-owned pairs cannot be
		 * replaced because the canonical map is restored after filtering.
		 *
		 * @param array<string,string> $allowed
		 */
		$filtered = apply_filters( 'cb_core_design_mail_node_types', $allowed );
		$filtered = is_array( $filtered ) ? $filtered : $allowed;
		$allowed = array_merge( $filtered, $allowed );
		if ( ! isset( $allowed[ $type ] ) || (string) $allowed[ $type ] !== $provider ) {
			$diagnostics->error( 'mail.node_not_allowed', 'Mail node type/provider pair is not registered.', $location );
		}

		$properties = $node['properties'] ?? null;
		if ( ! is_array( $properties ) || ( [] !== $properties && array_is_list( $properties ) ) || ! $this->is_json_value( $properties, 0 ) ) {
			$diagnostics->error( 'mail.node_properties', 'Mail node properties must be JSON-compatible object values.', $location . '.properties' );
		}

		$children = $node['children'] ?? null;
		if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
			$diagnostics->error( 'mail.node_children', 'Mail node children must be a list.', $location . '.children' );
			return;
		}

		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) || [] === $child ) {
				$diagnostics->error( 'mail.invalid_child', 'Every mail child must be a node object.', $location . '.children.' . $index );
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
