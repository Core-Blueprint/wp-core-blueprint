<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function explode;
use function is_array;
use function sanitize_key;

/**
 * Builds a conservative component-level diff between the latest scan and the
 * previous scan. This does not make security claims, it only reports changes.
 */
final class DiffService {
	public function compare( ?array $previous, array $current ): array {
		$previous_checks = $this->checks( $previous );
		$current_checks  = $this->checks( $current );

		if ( [] === $previous_checks ) {
			return [
				'has_previous' => false,
				'has_changes'  => false,
				'summary'      => $this->empty_summary(),
				'components'   => [],
			];
		}

		$previous_map = $this->map_by_id( $previous_checks );
		$current_map  = $this->map_by_id( $current_checks );
		$summary      = $this->empty_summary();
		$components   = [];

		foreach ( $current_map as $id => $current_check ) {
			$component_key = $this->component_key( $current_check );

			if ( ! array_key_exists( $id, $previous_map ) ) {
				$summary['new']++;
				$components[ $component_key ]['new'][] = $this->compact_check( $current_check );
				continue;
			}

			$previous_hash = $this->hash_for_check( $previous_map[ $id ] );
			$current_hash  = $this->hash_for_check( $current_check );

			if ( '' !== $previous_hash && '' !== $current_hash && $previous_hash !== $current_hash ) {
				$summary['changed']++;
				$components[ $component_key ]['changed'][] = $this->compact_check( $current_check );
				continue;
			}

			$summary['unchanged']++;
		}

		foreach ( $previous_map as $id => $previous_check ) {
			if ( array_key_exists( $id, $current_map ) ) {
				continue;
			}

			$summary['missing']++;
			$components[ $this->component_key( $previous_check ) ]['missing'][] = $this->compact_check( $previous_check );
		}

		return [
			'has_previous' => true,
			'has_changes'  => $summary['new'] > 0 || $summary['changed'] > 0 || $summary['missing'] > 0,
			'summary'      => $summary,
			'components'   => $this->normalise_components( $components ),
		];
	}

	private function checks( ?array $result ): array {
		if ( ! is_array( $result ) ) {
			return [];
		}

		$checks = is_array( $result['checks'] ?? null ) ? $result['checks'] : [];

		return array_values( array_filter( $checks, 'is_array' ) );
	}

	private function map_by_id( array $checks ): array {
		$map = [];

		foreach ( $checks as $check ) {
			$id = (string) ( $check['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$map[ $id ] = $check;
		}

		return $map;
	}

	private function hash_for_check( array $check ): string {
		$meta = is_array( $check['meta'] ?? null ) ? $check['meta'] : [];

		return (string) ( $check['hash'] ?? $meta['fingerprint_hash'] ?? $meta['actual_sha256'] ?? $meta['actual_hash'] ?? $meta['expected_hash'] ?? '' );
	}

	private function component_key( array $check ): string {
		$type   = sanitize_key( (string) ( $check['type'] ?? 'other' ) );
		$target = is_array( $check['target'] ?? null ) ? $check['target'] : [];
		$slug   = sanitize_key( (string) ( $target['slug'] ?? $type ) );

		return $type . ':' . $slug;
	}

	private function compact_check( array $check ): array {
		$target = is_array( $check['target'] ?? null ) ? $check['target'] : [];

		return [
			'id'       => (string) ( $check['id'] ?? '' ),
			'type'     => (string) ( $check['type'] ?? '' ),
			'target'   => $target,
			'status'   => (string) ( $check['status'] ?? '' ),
			'severity' => (string) ( $check['severity'] ?? '' ),
		];
	}

	private function normalise_components( array $components ): array {
		$normalised = [];

		foreach ( $components as $key => $changes ) {
			$changes = is_array( $changes ) ? $changes : [];
			$parts   = explode( ':', (string) $key, 2 );
			$type    = (string) ( $parts[0] ?? 'other' );
			$slug    = (string) ( $parts[1] ?? $key );
			$first   = $changes['new'][0] ?? $changes['changed'][0] ?? $changes['missing'][0] ?? [];

			$normalised[] = [
				'key'     => (string) $key,
				'type'    => $type,
				'slug'    => $slug,
				'label'   => is_array( $first ) && is_array( $first['target'] ?? null ) ? (string) ( $first['target']['label'] ?? $slug ) : $slug,
				'counts'  => [
					'new'     => count( $changes['new'] ?? [] ),
					'changed' => count( $changes['changed'] ?? [] ),
					'missing' => count( $changes['missing'] ?? [] ),
				],
				'changes' => [
					'new'     => array_values( $changes['new'] ?? [] ),
					'changed' => array_values( $changes['changed'] ?? [] ),
					'missing' => array_values( $changes['missing'] ?? [] ),
				],
			];
		}

		return $normalised;
	}

	private function empty_summary(): array {
		return [
			'new'       => 0,
			'changed'   => 0,
			'missing'   => 0,
			'unchanged' => 0,
		];
	}
}
