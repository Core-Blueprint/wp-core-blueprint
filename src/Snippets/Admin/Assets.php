<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets\Admin;

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Snippets\Schema;

defined( 'ABSPATH' ) || exit;

final class Assets {
	/**
	 * Enqueue Snippets page glue for the selected list/editor view.
	 *
	 * Shared page CSS and Modal are selected declaratively by
	 * ScreenAssetRegistry. CodeMirror and location metadata are editor-only.
	 */
	public static function enqueue( string $hook, bool $editor_view = true ): void {
		if ( PageRegistry::hook_suffix( Page::SLUG ) !== $hook ) {
			return;
		}

		$editor    = $editor_view ? wp_enqueue_code_editor( [ 'type' => 'text/x-php' ] ) : [];
		$locations = [];
		if ( $editor_view ) {
			foreach ( Schema::TYPES as $type ) {
				$locations[ $type ] = Schema::locations_for_type( $type );
			}
		}

		wp_enqueue_script(
			'cb-core-snippets-admin',
			CB_CORE_URL . 'assets/js/features/snippets.js',
			$editor_view ? [ 'code-editor' ] : [],
			CB_CORE_VERSION,
			true
		);

		wp_localize_script( 'cb-core-snippets-admin', 'cbCoreSnippetsData', [
			'editor'    => is_array( $editor ) ? $editor : [],
			'locations' => $locations,
			'i18n'      => [
				'deleteTitle' => __( 'Delete snippet?', 'core-blueprint' ),
				'deleteBody'  => __( 'This removes the managed snippet file and its metadata. This action cannot be undone.', 'core-blueprint' ),
				'delete'      => __( 'Delete snippet', 'core-blueprint' ),
				'cancel'      => __( 'Cancel', 'core-blueprint' ),
			],
		] );
	}
}
