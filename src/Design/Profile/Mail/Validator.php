<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Mail;

use CoreBlueprint\Core\Design\Kernel\Diagnostics;

defined( 'ABSPATH' ) || exit;

/** Mail-specific server invariants on the native stable-v1 project. */
final class Validator {
	private int $count = 0;

	public function validate( array $project ): Diagnostics {
		$errors = new Diagnostics();
		if ( array_keys( $project ) !== [ 'schema_version', 'design_type', 'root' ]
			|| ( $project['schema_version'] ?? null ) !== 1
			|| ( $project['design_type'] ?? null ) !== Contract::DESIGN_TYPE
			|| ! is_array( $project['root'] ?? null ) ) {
			$errors->error( 'mail.native_envelope', 'Invalid native Mail document.', 'project' );
			return $errors;
		}
		$this->count = 0;
		$this->check_node( $project['root'], null, $errors, 'root', 0 );
		return $errors;
	}

	private function check_node( array $node, ?string $parent, Diagnostics $errors, string $path, int $depth ): void {
		if ( $depth > 64 || ++$this->count > 5000 ) {
			$errors->error( 'mail.depth', 'Mail document is too complex.', $path );
			return;
		}
		$definitions = Contract::definitions();
		$type = $node['type'] ?? null;
		if ( ! is_string( $type ) || ! isset( $definitions[ $type ] )
			|| ( $node['provider'] ?? null ) !== Contract::CORE_PROVIDER
			|| ( null === $parent && Contract::ROOT_TYPE !== $type )
			|| ( null !== $parent && ! in_array( $parent, $definitions[ $type ]['parents'], true ) )
			|| array_keys( $node ) !== [ 'type', 'provider', 'properties', 'children' ] ) {
			$errors->error( 'mail.native_node', 'Unrecognized native Mail node.', $path );
			return;
		}
		$properties = $node['properties'] ?? null;
		$schema = $definitions[ $type ]['properties'];
		if ( ! is_array( $properties ) || array_is_list( $properties )
			|| count( $properties ) !== count( $schema ) ) {
			$errors->error( 'mail.properties', 'Invalid Mail properties.', $path . '.properties' );
			return;
		}
		foreach ( $schema as $key => $rule ) {
			if ( ! array_key_exists( $key, $properties ) || ! self::valid_value( $properties[ $key ], $rule ) ) {
				$errors->error( 'mail.property_value', 'Invalid Mail property value.', $path . '.properties.' . $key );
			}
		}
		$children = $node['children'] ?? null;
		if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
			$errors->error( 'mail.children', 'Mail children must be a list.', $path . '.children' );
			return;
		}
		if ( 'mail.columns' === $type && ( count( $children ) !== 2
			|| ( $children[0]['type'] ?? null ) !== 'mail.column'
			|| ( $children[1]['type'] ?? null ) !== 'mail.column' ) ) {
			$errors->error( 'mail.columns', 'Two columns are required.', $path . '.children' );
		}
		if ( ! in_array( $type, [ Contract::ROOT_TYPE, 'mail.section', 'mail.columns', 'mail.column' ], true )
			&& [] !== $children ) {
			$errors->error( 'mail.leaf_children', 'A Mail content block cannot contain children.', $path . '.children' );
		}
		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				$errors->error( 'mail.child', 'Invalid child node.', $path . '.children.' . $index );
				continue;
			}
			$this->check_node( $child, $type, $errors, $path . '.children.' . $index, $depth + 1 );
		}
	}

	private static function valid_value( mixed $value, array $rule ): bool {
		if ( 'integer' === $rule['type'] ) {
			return is_int( $value ) && $value >= $rule['minimum'] && $value <= $rule['maximum'];
		}
		if ( ! is_string( $value ) || mb_strlen( $value, 'UTF-8' ) > ( $rule['maxLength'] ?? 65535 )
			|| mb_strlen( $value, 'UTF-8' ) < ( $rule['minLength'] ?? 0 ) ) return false;
		if ( isset( $rule['enum'] ) ) return in_array( $value, $rule['enum'], true );
		if ( ( $rule['maxLength'] ?? 0 ) === 32 && str_starts_with( $value, '#' ) ) {
			return 1 === preg_match( '/^#[a-fA-F0-9]{6}$/D', $value );
		}
		if ( ( $rule['maxLength'] ?? 0 ) === 2048 && '' !== $value ) {
			// Exact dynamic tokens are permitted; unsafe/missing resolved URLs
			// are separately rejected by the rendering boundary.
			if ( 1 === preg_match( '/^\{\{[a-z][a-z0-9._-]*\}\}$/D', $value ) ) return true;
			return 1 === preg_match( '/^https?:\/\/[^\s\x00-\x1F\x7F]+$/iD', $value );
		}
		return true;
	}
}
