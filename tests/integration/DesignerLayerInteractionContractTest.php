<?php
declare(strict_types=1);

final class CB_Designer_Layer_Interaction_Contract_Test extends WP_UnitTestCase {

	private function source( string $relative_path ): string {
		$path = CB_CORE_DIR . $relative_path;
		self::assertFileExists( $path );
		$source = file_get_contents( $path );
		self::assertIsString( $source );
		return $source;
	}

	public function test_public_designer_exposes_base_owned_layer_row_and_tree_factories(): void {
		$shell  = $this->source( 'assets/js/design/shell/index.js' );
		$editor = $this->source( 'assets/js/design/editor.js' );

		self::assertStringContainsString( "from './layers.js';", $shell );
		self::assertStringContainsString( 'createDesignerLayerRow', $shell );
		self::assertStringContainsString( 'createDesignerLayerTree', $shell );
		self::assertStringContainsString( 'DESIGNER_LAYER_ACTIONS', $shell );
		self::assertStringContainsString( 'createRow: createDesignerLayerRow', $editor );
		self::assertStringContainsString( 'createTree: createDesignerLayerTree', $editor );
		self::assertStringContainsString( 'actions: DESIGNER_LAYER_ACTIONS', $editor );
	}

	public function test_layer_row_factory_owns_standard_action_order_and_iconography(): void {
		$layers = $this->source( 'assets/js/design/shell/layers.js' );
		$icons  = $this->source( 'assets/js/design/shell/icons.js' );

		self::assertStringContainsString( "icon: 'arrow-up'", $layers );
		self::assertStringContainsString( "icon: 'arrow-down'", $layers );
		self::assertStringContainsString( "icon: 'trash-2'", $layers );
		self::assertStringContainsString( "for (const actionName of ['moveUp', 'moveDown', 'remove'])", $layers );
		self::assertStringContainsString( 'iconOnly: true', $layers );
		self::assertStringContainsString( "button.dataset.cbDesignLayerAction = actionName;", $layers );
		self::assertStringContainsString( "config.layerActionLabels?.[actionName]", $layers );
		self::assertStringContainsString( "'trash-2': Object.freeze([", $icons );
	}

	public function test_layer_tree_owns_hierarchy_collapse_keyboard_and_bounded_sibling_reorder(): void {
		$layers = $this->source( 'assets/js/design/shell/layers.js' );

		self::assertStringContainsString( 'export const createDesignerLayerTree', $layers );
		self::assertStringContainsString( "element.setAttribute('role', 'tree')", $layers );
		self::assertStringContainsString( "row.setAttribute('aria-level'", $layers );
		self::assertStringContainsString( "row.setAttribute('aria-expanded'", $layers );
		self::assertStringContainsString( "group.setAttribute('role', 'group')", $layers );
		self::assertStringContainsString( "event.key === 'ArrowRight'", $layers );
		self::assertStringContainsString( "event.key === 'ArrowLeft'", $layers );
		self::assertStringContainsString( "event.key === 'ArrowDown'", $layers );
		self::assertStringContainsString( "event.key === 'ArrowUp'", $layers );
		self::assertStringContainsString( 'collapsedKeys', $layers );
		self::assertStringContainsString( 'dragRecord.parentKey !== parentKey', $layers );
		self::assertStringContainsString( 'reorder.onMove', $layers );
		self::assertStringContainsString( 'onSelectItem = null', $layers );
		self::assertStringContainsString( "source: 'layers'", $layers );
		self::assertStringContainsString( 'openInspector: false', $layers );
	}

	public function test_layer_action_labels_are_localized_by_base(): void {
		$assets = $this->source( 'src/Design/Editor/Assets.php' );

		self::assertStringContainsString( "'layerActionLabels' => [", $assets );
		self::assertStringContainsString( "'moveUp'   => __( 'Move element up', 'core-blueprint' )", $assets );
		self::assertStringContainsString( "'moveDown' => __( 'Move element down', 'core-blueprint' )", $assets );
		self::assertStringContainsString( "'remove'   => __( 'Remove element', 'core-blueprint' )", $assets );
	}

	public function test_layer_actions_are_overlayed_and_revealed_by_hover_focus_or_selection(): void {
		$css = $this->source( 'assets/css/design/designer-composition.css' );

		self::assertStringContainsString( '.cb-core-design-shell__layer-tree', $css );
		self::assertStringContainsString( '.cb-core-design-shell__layer-group', $css );
		self::assertStringContainsString( 'border-inline-start: 1px dashed var(--cb-border);', $css );
		self::assertStringContainsString( 'min-height: 38px;', $css );
		self::assertStringContainsString( 'width: 26px !important;', $css );
		self::assertStringContainsString( 'position: absolute;', $css );
		self::assertStringContainsString( 'opacity: 0;', $css );
		self::assertStringContainsString( 'visibility: hidden;', $css );
		self::assertStringContainsString( '.cb-core-design-shell__layer-row:hover > .cb-core-design-shell__layer-actions', $css );
		self::assertStringContainsString( '.cb-core-design-shell__layer-row:focus-within > .cb-core-design-shell__layer-actions', $css );
		self::assertStringContainsString( '.cb-core-design-shell__layer-row.is-selected > .cb-core-design-shell__layer-actions', $css );
		self::assertStringContainsString( 'pointer-events: auto;', $css );
	}

	public function test_layout_contract_assigns_layer_presentation_to_base(): void {
		$docs = $this->source( 'docs/DESIGNER-LAYOUT-CONTRACT.md' );

		self::assertStringContainsString( 'Layers tree traversal, parent/child presentation', $docs );
		self::assertStringContainsString( 'Canonical Layers interaction', $docs );
		self::assertStringContainsString( 'must not recursively build their own Layer rows', $docs );
	}
}
