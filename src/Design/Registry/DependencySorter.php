<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Registry;

defined( 'ABSPATH' ) || exit;

/** Deterministic dependency order independent of registration/hook arrival. */
final class DependencySorter {
	/**
	 * @param array<string,list<string>> $dependencies node => required node IDs
	 * @return list<string>
	 */
	public function sort( array $dependencies ): array {
		$vertices = array_fill_keys( array_keys( $dependencies ), true );
		$indegree = array_fill_keys( array_keys( $dependencies ), 0 );
		$followers = array_fill_keys( array_keys( $dependencies ), [] );
		foreach ( $dependencies as $node => $requires ) {
			if ( ! is_string( $node ) || '' === $node || ! is_array( $requires ) || ! array_is_list( $requires ) ) {
				throw new \DomainException( 'REGISTRY_INVALID' );
			}
			$seen = [];
			foreach ( $requires as $required ) {
				if ( ! is_string( $required ) || '' === $required || isset( $seen[ $required ] ) ) {
					throw new \DomainException( 'REGISTRY_INVALID' );
				}
				$seen[ $required ] = true;
				if ( ! isset( $vertices[ $required ] ) ) {
					throw new \DomainException( 'REGISTRY_DEPENDENCY_MISSING' );
				}
				++$indegree[ $node ];
				$followers[ $required ][] = $node;
			}
		}
		$ready = [];
		foreach ( $indegree as $node => $count ) {
			if ( 0 === $count ) {
				$ready[] = $node;
			}
		}
		$order = [];
		while ( $ready ) {
			usort( $ready, [ self::class, 'compare' ] );
			$id = array_shift( $ready );
			$order[] = $id;
			foreach ( $followers[ $id ] as $child ) {
				--$indegree[ $child ];
				if ( 0 === $indegree[ $child ] ) {
					$ready[] = $child;
				}
			}
		}
		if ( count( $order ) !== count( $dependencies ) ) {
			throw new \DomainException( 'REGISTRY_DEPENDENCY_CYCLE' );
		}
		return $order;
	}

	private static function compare( string $a, string $b ): int {
		$base_a = str_starts_with( $a, 'core-blueprint/' ) || str_starts_with( $a, '@cb-core/' ) ? 0 : 1;
		$base_b = str_starts_with( $b, 'core-blueprint/' ) || str_starts_with( $b, '@cb-core/' ) ? 0 : 1;
		return $base_a <=> $base_b ?: strcmp( $a, $b );
	}
}
