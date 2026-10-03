<?php
declare(strict_types=1);
/**
 * Content Models admin view module: OptionPagesView.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\ContentModels\Admin;

use CoreBlueprint\Core\ContentModels\FieldTypes;
use CoreBlueprint\Core\ContentModels\Repository;
use CoreBlueprint\Core\ContentModels\Runtime;
use CoreBlueprint\Core\ContentModels\State;
use CoreBlueprint\Core\ContentModels\Importers\NativeWordPress\Bootstrap as NativeImporter;
use CoreBlueprint\Core\UI\ChoiceGroup;
use CoreBlueprint\Core\UI\Icon;
use CoreBlueprint\Core\UI\Status as StatusUi;

defined( 'ABSPATH' ) || exit;

trait OptionPagesView {

	private function render_option_pages(): void {
		$this->render_header( __( 'Create WordPress admin Option Pages for site-wide settings. Field values use the native WordPress Options API and remain separate from their Content Model definitions.', 'core-blueprint' ) );
		if ( ! State::is_enabled() ) {
			$this->render_disabled_panel();
			echo '</div>';
			return;
		}

		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$model = isset( $_GET['model'] ) ? sanitize_key( wp_unslash( $_GET['model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $view ) {
			$this->render_option_page_editor( $model );
		} elseif ( 'duplicate' === $view ) {
			$this->render_option_page_editor( $model, true );
		} elseif ( 'delete' === $view ) {
			$this->render_option_page_delete( $model );
		} else {
			$this->render_option_page_list();
		}
		echo '</div>';
	}

	private function render_option_page_list(): void {
		$pages = Repository::option_pages();
		$add_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'option-pages', 'view' => 'edit' ], admin_url( 'admin.php' ) );
		?>
		<div class="cb-content-models-toolbar"><a class="button cb-core-button cb-core-button--primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add Option Page', 'core-blueprint' ); ?></a></div>
		<?php if ( empty( $pages ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'No Core Blueprint Option Pages have been created yet.', 'core-blueprint' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped cb-content-models-table">
				<thead><tr><th><?php esc_html_e( 'Option Page', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Slug', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Parent', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Capability', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $pages as $slug => $definition ) :
					$edit_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'option-pages', 'view' => 'edit', 'model' => $slug ], admin_url( 'admin.php' ) );
					$duplicate_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'option-pages', 'view' => 'duplicate', 'model' => $slug ], admin_url( 'admin.php' ) );
					$delete_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'option-pages', 'view' => 'delete', 'model' => $slug ], admin_url( 'admin.php' ) );
				?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) ( $definition['title'] ?? $slug ) ); ?></a></strong><br><span class="description"><?php echo esc_html( (string) ( $definition['menu_label'] ?? '' ) ); ?></span></td>
						<td><code><?php echo esc_html( (string) $slug ); ?></code></td>
						<td><?php echo esc_html( '' !== (string) ( $definition['parent_slug'] ?? '' ) ? (string) $definition['parent_slug'] : __( 'Top-level', 'core-blueprint' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $definition['capability'] ?? 'manage_options' ) ); ?></code></td>
						<td class="cb-content-models-col-actions"><div class="cb-content-models-actions"><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--danger" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'core-blueprint' ); ?></a></div></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_option_page_editor( string $slug, bool $duplicate = false ): void {
		$model = '' !== $slug ? Repository::option_page( $slug ) : null;
		if ( '' !== $slug && null === $model ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Option Page definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		$duplicate_source = $duplicate ? $slug : '';
		$is_edit = ! $duplicate && null !== $model;
		$model = array_replace( [
			'slug' => '', 'title' => '', 'menu_label' => '', 'description' => '', 'parent_slug' => '',
			'capability' => 'manage_options', 'position' => null, 'icon' => 'dashicons-admin-generic',
		], is_array( $model ) ? $model : [] );
		if ( $duplicate ) {
			$model['slug'] = $this->suggest_unique_option_page_slug( (string) $model['slug'] );
			$model['title'] = $this->copy_label( (string) $model['title'] );
			$model['menu_label'] = $this->copy_label( (string) $model['menu_label'] );
		}
		$parent_choices = $this->option_page_parent_choices( $is_edit ? $slug : '' );
		?>
		<h2><?php echo esc_html( $duplicate ? __( 'Duplicate Option Page', 'core-blueprint' ) : ( $is_edit ? __( 'Edit Option Page', 'core-blueprint' ) : __( 'Add Option Page', 'core-blueprint' ) ) ); ?></h2>
		<?php if ( $is_edit ) : ?><div class="notice notice-info inline"><p><?php esc_html_e( 'The Option Page slug is protected after creation because it is part of the option-value storage namespace.', 'core-blueprint' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-scope">
			<input type="hidden" name="action" value="cb_core_content_models_save_option_page" />
			<input type="hidden" name="original_slug" value="<?php echo esc_attr( $is_edit ? (string) $model['slug'] : '' ); ?>" />
			<input type="hidden" name="duplicate_source" value="<?php echo esc_attr( $duplicate_source ); ?>" />
			<?php wp_nonce_field( 'cb_core_content_models_save_option_page' ); ?>
			<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><label for="cb-cm-op-title"><?php esc_html_e( 'Page title', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-op-title" name="title" type="text" class="regular-text" required value="<?php echo esc_attr( (string) $model['title'] ); ?>" /></td></tr>
			<tr><th scope="row"><label for="cb-cm-op-menu"><?php esc_html_e( 'Menu label', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-op-menu" name="menu_label" type="text" class="regular-text" value="<?php echo esc_attr( (string) $model['menu_label'] ); ?>" /><p class="description"><?php esc_html_e( 'Leave empty to use the page title.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-op-slug"><?php esc_html_e( 'Page slug', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-op-slug" name="slug" type="text" class="regular-text code" required <?php echo $is_edit ? 'readonly' : ''; ?> value="<?php echo esc_attr( (string) $model['slug'] ); ?>" /><p class="description"><?php esc_html_e( 'Stable identifier used by WordPress admin routing and this page’s option storage namespace.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-op-description"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></label></th><td><textarea id="cb-cm-op-description" name="description" class="large-text" rows="3"><?php echo esc_textarea( (string) $model['description'] ); ?></textarea></td></tr>
			<tr><th scope="row"><label for="cb-cm-op-parent"><?php esc_html_e( 'Parent page', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-op-parent" name="parent_slug" type="text" class="regular-text code" list="cb-cm-op-parent-choices" value="<?php echo esc_attr( (string) $model['parent_slug'] ); ?>" placeholder="<?php esc_attr_e( 'Leave empty for top-level', 'core-blueprint' ); ?>" /><datalist id="cb-cm-op-parent-choices"><?php foreach ( $parent_choices as $parent_slug => $label ) : ?><option value="<?php echo esc_attr( $parent_slug ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></datalist><p class="description"><?php esc_html_e( 'Use a WordPress admin menu slug such as options-general.php, tools.php or core-blueprint. Existing Core Blueprint Option Pages are suggested too.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-op-cap"><?php esc_html_e( 'Required capability', 'core-blueprint' ); ?></label></th><td><?php $this->render_capability_picker( 'cb-cm-op-cap', 'capability', (string) $model['capability'], __( 'Controls who may view and save values on the generated Option Page.', 'core-blueprint' ) ); ?></td></tr>
			<tr><th scope="row"><label for="cb-cm-op-position"><?php esc_html_e( 'Menu position', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-op-position" name="position" type="number" min="1" max="999" value="<?php echo esc_attr( null !== $model['position'] ? (string) $model['position'] : '' ); ?>" /><p class="description"><?php esc_html_e( 'Optional. Leave empty to let WordPress place the item naturally.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-op-icon"><?php esc_html_e( 'Menu icon', 'core-blueprint' ); ?></label></th><td><?php $this->render_icon_picker( 'cb-cm-op-icon', 'icon', (string) $model['icon'], __( 'Choose a Dashicon or Core Blueprint Lucide icon for top-level pages. Submenu pages inherit their parent menu.', 'core-blueprint' ) ); ?></td></tr>
			</tbody></table>
			<?php submit_button( $duplicate ? __( 'Create Duplicate Option Page', 'core-blueprint' ) : ( $is_edit ? __( 'Save Option Page', 'core-blueprint' ) : __( 'Create Option Page', 'core-blueprint' ) ) ); ?>
		</form>
		<?php
	}

	private function render_option_page_delete( string $slug ): void {
		$page = Repository::option_page( $slug );
		if ( null === $page ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Option Page definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		?>
		<h2><?php esc_html_e( 'Delete Option Page Definition', 'core-blueprint' ); ?></h2>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html( (string) $page['title'] ); ?></strong> — <?php esc_html_e( 'This removes only the generated admin page definition. Stored WordPress option values are deliberately preserved. Remove any Field Group assignments or child Option Pages first.', 'core-blueprint' ); ?></p></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="cb_core_content_models_delete_option_page" /><input type="hidden" name="model" value="<?php echo esc_attr( $slug ); ?>" /><?php wp_nonce_field( 'cb_core_content_models_delete_option_page' ); ?><button type="submit" class="button cb-core-button cb-core-button--danger"><?php esc_html_e( 'Delete Definition', 'core-blueprint' ); ?></button></form>
		<?php
	}

	private function option_page_parent_choices( string $current_slug = '' ): array {
		$choices = [
			'index.php' => __( 'Dashboard', 'core-blueprint' ),
			'edit.php' => __( 'Posts', 'core-blueprint' ),
			'upload.php' => __( 'Media', 'core-blueprint' ),
			'edit.php?post_type=page' => __( 'Pages', 'core-blueprint' ),
			'edit-comments.php' => __( 'Comments', 'core-blueprint' ),
			'themes.php' => __( 'Appearance', 'core-blueprint' ),
			'plugins.php' => __( 'Plugins', 'core-blueprint' ),
			'users.php' => __( 'Users', 'core-blueprint' ),
			'tools.php' => __( 'Tools', 'core-blueprint' ),
			'options-general.php' => __( 'Settings', 'core-blueprint' ),
			'core-blueprint' => __( 'Core Blueprint', 'core-blueprint' ),
		];
		foreach ( Repository::option_pages() as $slug => $page ) {
			if ( $slug !== $current_slug && '' === (string) ( $page['parent_slug'] ?? '' ) ) {
				/* translators: %s: option page title. */
			$choices[ (string) $slug ] = sprintf( __( 'Option Page: %s', 'core-blueprint' ), (string) ( $page['title'] ?? $slug ) );
			}
		}
		return $choices;
	}

	private function suggest_unique_option_page_slug( string $source_slug ): string {
		$base = sanitize_key( $source_slug );
		if ( '' === $base ) { $base = 'options'; }
		$suffix = '-copy';
		$i = 2;
		$candidate = substr( $base, 0, 80 - strlen( $suffix ) ) . $suffix;
		while ( null !== Repository::option_page( $candidate ) ) {
			$suffix = '-copy-' . $i++;
			$candidate = substr( $base, 0, 80 - strlen( $suffix ) ) . $suffix;
		}
		return $candidate;
	}

}
