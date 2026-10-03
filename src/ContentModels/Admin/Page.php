<?php
declare(strict_types=1);
/**
 * Content Models admin page router.
 *
 * Each content-model surface owns its rendering in a focused view module; this
 * class keeps only the public Page contract and top-level tab routing.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\ContentModels\Admin;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\Admin\TabNav;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {
	use CommonView;
	use ToolsView;
	use PostTypesView;
	use TaxonomiesView;
	use OptionPagesView;
	use FieldGroupsView;
	use FieldsView;
	use StructuredFieldsView;
	use RelationFieldsView;

	public const SLUG = 'core-blueprint-content-models';

	public function slug(): string { return self::SLUG; }

	public function title(): string { return __( 'Content Models', 'core-blueprint' ); }

	public function position(): ?int { return 25; }

	public function capability(): string { return 'cb_manage_content_models'; }

	public function render(): void {
		$this->guard();

		$tabs = [
			'post-types'   => __( 'Post Types', 'core-blueprint' ),
			'taxonomies'   => __( 'Taxonomies', 'core-blueprint' ),
			'option-pages' => __( 'Options Pages', 'core-blueprint' ),
			'field-groups' => __( 'Field Groups', 'core-blueprint' ),
			'tools'        => __( 'Tools', 'core-blueprint' ),
		];
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'post-types'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $tabs[ $requested ] ) ? $requested : 'post-types';

		ob_start();
		if ( 'tools' === $tab ) {
			$this->render_tools();
		} elseif ( 'field-groups' === $tab ) {
			$this->render_field_groups();
		} elseif ( 'option-pages' === $tab ) {
			$this->render_option_pages();
		} elseif ( 'taxonomies' === $tab ) {
			$this->render_taxonomies();
		} else {
			$this->render_post_types();
		}
		$html = (string) ob_get_clean();

		echo TabNav::inject( $html, self::SLUG, $tab, $tabs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

}
