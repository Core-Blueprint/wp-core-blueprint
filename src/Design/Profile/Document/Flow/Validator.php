<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Flow;

use CoreBlueprint\Core\Design\Kernel\DesignProject;
use CoreBlueprint\Core\Design\Kernel\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Validator {
	public function validate( DesignProject $project ): Diagnostics {
		$diagnostics = new Diagnostics();
		$root = $project->root();
		if ( [] === $root ) {
			$diagnostics->error( 'flow.root_required', 'Flow documents require a root node.', 'root' );
			return $diagnostics;
		}

		$properties = is_array( $root['properties'] ?? null ) ? $root['properties'] : [];
		if ( array_key_exists( Contract::NODE_FRAME_KEY, $properties ) ) {
			$diagnostics->error( 'flow.root_frame_forbidden', 'Flow documents do not use Fixed frames.', 'root.properties.frame' );
		}
		if ( array_key_exists( Contract::NODE_FLOW_KEY, $properties ) ) {
			$diagnostics->error( 'flow.root_hint_forbidden', 'Flow hints apply to ordered content nodes, not the document root.', 'root.properties.flow' );
		}

		$layout = $properties[ Contract::ROOT_LAYOUT_KEY ] ?? null;
		if ( ! is_array( $layout ) ) {
			$diagnostics->error( 'flow.layout_required', 'Flow documents require a root-owned layout object.', 'root.properties.layout' );
			return $diagnostics;
		}
		$this->reject_unknown_keys( $layout, Contract::LAYOUT_KEYS, $diagnostics, 'root.properties.layout', 'flow.layout_unknown_key' );
		if ( Contract::LAYOUT_MODE !== ( $layout['mode'] ?? null ) ) {
			$diagnostics->error( 'flow.layout_mode', 'Flow document layout mode must be flow.', 'root.properties.layout.mode' );
		}
		if ( Contract::UNITS !== ( $layout['units'] ?? null ) ) {
			$diagnostics->error( 'flow.layout_units', 'Flow document page measurements must use millimetres.', 'root.properties.layout.units' );
		}

		$page_raw = $layout['page'] ?? null;
		if ( ! is_array( $page_raw ) ) {
			$diagnostics->error( 'flow.page_required', 'Flow documents require page dimensions.', 'root.properties.layout.page' );
			return $diagnostics;
		}
		$this->reject_unknown_keys( $page_raw, Contract::PAGE_KEYS, $diagnostics, 'root.properties.layout.page', 'flow.page_unknown_key' );
		$page = Layout::page( $layout );
		if ( null === $page ) {
			$diagnostics->error( 'flow.page_invalid', 'Flow page width and height must be positive finite millimetre values.', 'root.properties.layout.page' );
			return $diagnostics;
		}

		$margins_raw = $layout['margins'] ?? null;
		if ( ! is_array( $margins_raw ) ) {
			$diagnostics->error( 'flow.margins_required', 'Flow documents require explicit page margins.', 'root.properties.layout.margins' );
			return $diagnostics;
		}
		$this->reject_unknown_keys( $margins_raw, Contract::MARGIN_KEYS, $diagnostics, 'root.properties.layout.margins', 'flow.margins_unknown_key' );
		$margins = Layout::margins( $layout );
		if ( null === $margins ) {
			$diagnostics->error( 'flow.margins_invalid', 'Flow margins must be non-negative finite millimetre values.', 'root.properties.layout.margins' );
			return $diagnostics;
		}
		if ( ! Layout::has_content_area( $page, $margins ) ) {
			$diagnostics->error( 'flow.content_area_invalid', 'Flow margins must leave a positive page content area.', 'root.properties.layout.margins' );
		}

		$children = is_array( $root['children'] ?? null ) ? $root['children'] : [];
		foreach ( $children as $index => $node ) {
			if ( is_array( $node ) ) {
				$this->validate_node( $node, $diagnostics, 'root.children.' . $index );
			}
		}
		return $diagnostics;
	}

	/** @param array<string,mixed> $node */
	private function validate_node( array $node, Diagnostics $diagnostics, string $location ): void {
		$properties = is_array( $node['properties'] ?? null ) ? $node['properties'] : [];
		if ( array_key_exists( Contract::ROOT_LAYOUT_KEY, $properties ) ) {
			$diagnostics->error( 'flow.nested_layout_forbidden', 'Flow layout context is owned by the document root.', $location . '.properties.layout' );
		}
		if ( array_key_exists( Contract::NODE_FRAME_KEY, $properties ) ) {
			$diagnostics->error( 'flow.frame_forbidden', 'Flow content participates in document order and cannot use Fixed frames.', $location . '.properties.frame' );
		}
		if ( array_key_exists( Contract::NODE_FLOW_KEY, $properties ) ) {
			$hints = $properties[ Contract::NODE_FLOW_KEY ];
			if ( ! is_array( $hints ) || ( [] !== $hints && array_is_list( $hints ) ) || null === Hints::normalize( $hints ) ) {
				$diagnostics->error( 'flow.hints_invalid', 'Flow hints contain unsupported keys or values.', $location . '.properties.flow' );
			}
		}

		$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
		foreach ( $children as $index => $child ) {
			if ( is_array( $child ) ) {
				$this->validate_node( $child, $diagnostics, $location . '.children.' . $index );
			}
		}
	}

	/** @param array<string,mixed> $value @param list<string> $allowed */
	private function reject_unknown_keys( array $value, array $allowed, Diagnostics $diagnostics, string $location, string $code ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				$diagnostics->error( $code, 'Unknown Flow layout key.', $location );
			}
		}
	}
}
