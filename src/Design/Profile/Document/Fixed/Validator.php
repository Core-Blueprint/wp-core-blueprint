<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

use CoreBlueprint\Core\Design\Kernel\DesignProject;
use CoreBlueprint\Core\Design\Kernel\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Validator {
	public function validate( DesignProject $project ): Diagnostics {
		$diagnostics = new Diagnostics();
		$root = $project->root();

		if ( [] === $root ) {
			$diagnostics->error( 'fixed.root_required', 'Fixed documents require a root node.', 'root' );
			return $diagnostics;
		}

		$properties = is_array( $root['properties'] ?? null ) ? $root['properties'] : [];
		if ( array_key_exists( Contract::NODE_FRAME_KEY, $properties ) ) {
			$diagnostics->error( 'fixed.root_frame_forbidden', 'The Fixed root owns the page and must not have an element frame.', 'root.properties.frame' );
		}

		$layout = $properties[ Contract::ROOT_LAYOUT_KEY ] ?? null;
		if ( ! is_array( $layout ) ) {
			$diagnostics->error( 'fixed.layout_required', 'Fixed documents require a root-owned layout object.', 'root.properties.layout' );
			return $diagnostics;
		}

		$this->reject_unknown_keys( $layout, Contract::LAYOUT_KEYS, $diagnostics, 'root.properties.layout', 'fixed.layout_unknown_key' );

		if ( Contract::LAYOUT_MODE !== ( $layout['mode'] ?? null ) ) {
			$diagnostics->error( 'fixed.layout_mode', 'Fixed document layout mode must be fixed.', 'root.properties.layout.mode' );
		}
		if ( Contract::UNITS !== ( $layout['units'] ?? null ) ) {
			$diagnostics->error( 'fixed.layout_units', 'Fixed document geometry must use millimetres.', 'root.properties.layout.units' );
		}

		$page_raw = $layout['page'] ?? null;
		if ( ! is_array( $page_raw ) ) {
			$diagnostics->error( 'fixed.page_required', 'Fixed documents require page dimensions.', 'root.properties.layout.page' );
			return $diagnostics;
		}
		$this->reject_unknown_keys( $page_raw, Contract::PAGE_KEYS, $diagnostics, 'root.properties.layout.page', 'fixed.page_unknown_key' );

		$page = Geometry::page( $layout );
		if ( null === $page ) {
			$diagnostics->error( 'fixed.page_invalid', 'Fixed page width and height must be positive finite numbers in millimetres.', 'root.properties.layout.page' );
			return $diagnostics;
		}

		$children = is_array( $root['children'] ?? null ) ? $root['children'] : [];
		foreach ( $children as $index => $node ) {
			if ( is_array( $node ) ) {
				$this->validate_node( $node, $page, $diagnostics, 'root.children.' . $index );
			}
		}

		return $diagnostics;
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array{width:float,height:float} $page
	 */
	private function validate_node( array $node, array $page, Diagnostics $diagnostics, string $location ): void {
		$properties = is_array( $node['properties'] ?? null ) ? $node['properties'] : [];

		if ( array_key_exists( Contract::ROOT_LAYOUT_KEY, $properties ) ) {
			$diagnostics->error( 'fixed.nested_layout_forbidden', 'Fixed layout context is owned by the document root.', $location . '.properties.layout' );
		}

		$frame_raw = $properties[ Contract::NODE_FRAME_KEY ] ?? null;
		if ( ! is_array( $frame_raw ) ) {
			$diagnostics->error( 'fixed.frame_required', 'Every Fixed document element requires a frame.', $location . '.properties.frame' );
		} else {
			$this->reject_unknown_keys( $frame_raw, Contract::FRAME_KEYS, $diagnostics, $location . '.properties.frame', 'fixed.frame_unknown_key' );
			$frame = Geometry::frame( $properties );
			if ( null === $frame ) {
				$diagnostics->error( 'fixed.frame_invalid', 'Fixed frames require non-negative x/y and positive finite width/height values.', $location . '.properties.frame' );
			} elseif ( ! Geometry::within_page( $frame, $page ) ) {
				$diagnostics->error( 'fixed.frame_outside_page', 'Fixed frames must remain within the root page.', $location . '.properties.frame' );
			}
		}

		$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
		foreach ( $children as $index => $child ) {
			if ( is_array( $child ) ) {
				$this->validate_node( $child, $page, $diagnostics, $location . '.children.' . $index );
			}
		}
	}

	/**
	 * @param array<string,mixed> $value
	 * @param list<string> $allowed
	 */
	private function reject_unknown_keys( array $value, array $allowed, Diagnostics $diagnostics, string $location, string $code ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				$diagnostics->error( $code, 'Unknown Fixed geometry key.', $location );
			}
		}
	}
}
