<?php
declare(strict_types=1);
/**
 * Content Models admin view module: PostTypesView.
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

trait PostTypesView {

	private function render_post_types(): void {
		$this->render_header( __( 'Define WordPress-native content types without a separate custom post type plugin. Definitions are governed by Core Blueprint; content remains ordinary WordPress posts.', 'core-blueprint' ) );
		if ( ! State::is_enabled() ) {
			$this->render_disabled_panel();
			echo '</div>';
			return;
		}

		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$model = isset( $_GET['model'] ) ? sanitize_key( wp_unslash( $_GET['model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $view ) {
			$this->render_post_type_editor( $model );
		} elseif ( 'duplicate' === $view ) {
			$this->render_post_type_editor( $model, true );
		} elseif ( 'delete' === $view ) {
			$this->render_post_type_delete( $model );
		} else {
			$this->render_post_type_list();
		}
		echo '</div>';
	}

	private function render_post_type_list(): void {
		$models = Repository::post_types();
		$add_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'post-types', 'view' => 'edit' ], admin_url( 'admin.php' ) );
		?>
		<div class="cb-content-models-toolbar">
			<a class="button cb-core-button cb-core-button--primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add Post Type', 'core-blueprint' ); ?></a>
		</div>
		<?php if ( empty( $models ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'No Core Blueprint post types have been created yet.', 'core-blueprint' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Post Type', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'Key', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'Visibility', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'REST API', 'core-blueprint' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $models as $key => $definition ) :
					$edit_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'post-types', 'view' => 'edit', 'model' => $key ], admin_url( 'admin.php' ) );
					$duplicate_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'post-types', 'view' => 'duplicate', 'model' => $key ], admin_url( 'admin.php' ) );
					$delete_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'post-types', 'view' => 'delete', 'model' => $key ], admin_url( 'admin.php' ) );
				?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) ( $definition['plural_label'] ?? $key ) ); ?></a></strong><br><span class="description"><?php echo esc_html( (string) ( $definition['singular_label'] ?? '' ) ); ?></span></td>
						<td><code><?php echo esc_html( (string) $key ); ?></code></td>
						<td><?php echo StatusUi::render( ! empty( $definition['public'] ) ? 'active' : 'idle', ! empty( $definition['public'] ) ? __( 'Public', 'core-blueprint' ) : __( 'Admin only', 'core-blueprint' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo ! empty( $definition['show_in_rest'] ) ? esc_html__( 'Enabled', 'core-blueprint' ) : esc_html__( 'Disabled', 'core-blueprint' ); ?></td>
						<td class="cb-content-models-col-actions"><div class="cb-content-models-actions"><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--danger" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'core-blueprint' ); ?></a></div></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_post_type_editor( string $key, bool $duplicate = false ): void {
		$model = '' !== $key ? Repository::post_type( $key ) : null;
		if ( '' !== $key && null === $model ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Post type definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		$duplicate_source = $duplicate ? $key : '';
		$is_edit = ! $duplicate && null !== $model;
		$model = array_replace( [
			'key'            => '',
			'singular_label' => '',
			'plural_label'   => '',
			'description'    => '',
			'public'         => true,
			'show_in_rest'   => true,
			'has_archive'    => true,
			'hierarchical'   => false,
			'rewrite_slug'   => '',
			'icon'           => 'dashicons-admin-post',
			'supports'       => [ 'title', 'editor', 'thumbnail', 'revisions' ],
		], is_array( $model ) ? $model : [] );
		if ( $duplicate ) {
			$model['key'] = $this->suggest_unique_model_key( (string) $model['key'], 'post_type', 20 );
			$model['singular_label'] = $this->copy_label( (string) $model['singular_label'] );
			$model['plural_label'] = $this->copy_label( (string) $model['plural_label'] );
			$model['rewrite_slug'] = (string) $model['key'];
		}
		?>
		<h2><?php echo esc_html( $duplicate ? __( 'Duplicate Post Type', 'core-blueprint' ) : ( $is_edit ? __( 'Edit Post Type', 'core-blueprint' ) : __( 'Add Post Type', 'core-blueprint' ) ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-scope">
			<input type="hidden" name="action" value="cb_core_content_models_save_post_type" />
			<input type="hidden" name="original_key" value="<?php echo esc_attr( $is_edit ? (string) $model['key'] : '' ); ?>" />
			<input type="hidden" name="duplicate_source" value="<?php echo esc_attr( $duplicate_source ); ?>" />
			<?php wp_nonce_field( 'cb_core_content_models_save_post_type' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
				<tr><th scope="row"><label for="cb-cm-singular"><?php esc_html_e( 'Singular label', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-singular" name="singular_label" type="text" class="regular-text" required value="<?php echo esc_attr( (string) $model['singular_label'] ); ?>" /><p class="description"><?php esc_html_e( 'Example: Project', 'core-blueprint' ); ?></p></td></tr>
				<tr><th scope="row"><label for="cb-cm-plural"><?php esc_html_e( 'Plural label', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-plural" name="plural_label" type="text" class="regular-text" required value="<?php echo esc_attr( (string) $model['plural_label'] ); ?>" /><p class="description"><?php esc_html_e( 'Example: Projects', 'core-blueprint' ); ?></p></td></tr>
				<tr><th scope="row"><label for="cb-cm-key"><?php esc_html_e( 'Post type key', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-key" name="key" type="text" class="regular-text code" maxlength="20" required <?php echo $is_edit ? 'readonly' : ''; ?> value="<?php echo esc_attr( (string) $model['key'] ); ?>" /><p class="description"><?php esc_html_e( 'Stored as the WordPress post type identifier. This key becomes immutable after creation.', 'core-blueprint' ); ?></p></td></tr>
				<tr><th scope="row"><label for="cb-cm-description"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></label></th><td><textarea id="cb-cm-description" name="description" class="large-text" rows="3"><?php echo esc_textarea( (string) $model['description'] ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="cb-cm-rewrite"><?php esc_html_e( 'URL slug', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-rewrite" name="rewrite_slug" type="text" class="regular-text code" value="<?php echo esc_attr( (string) $model['rewrite_slug'] ); ?>" /><p class="description"><?php esc_html_e( 'Used for public URLs. Leave empty to use the post type key.', 'core-blueprint' ); ?></p></td></tr>
				<tr><th scope="row"><label for="cb-cm-post-type-icon"><?php esc_html_e( 'Menu icon', 'core-blueprint' ); ?></label></th><td><?php $this->render_icon_picker( 'cb-cm-post-type-icon', 'icon', (string) $model['icon'], __( 'Choose a Dashicon or Core Blueprint Lucide icon for this post type’s admin menu item.', 'core-blueprint' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Behaviour', 'core-blueprint' ); ?></th><td>
					<?php
					echo ChoiceGroup::render( [
						'aria_label' => __( 'Post type behaviour', 'core-blueprint' ),
						'options' => [
							[ 'name' => 'public', 'label' => __( 'Publicly queryable', 'core-blueprint' ), 'checked' => ! empty( $model['public'] ) ],
							[ 'name' => 'show_in_rest', 'label' => __( 'Expose through the WordPress REST API', 'core-blueprint' ), 'checked' => ! empty( $model['show_in_rest'] ) ],
							[ 'name' => 'has_archive', 'label' => __( 'Enable archive page', 'core-blueprint' ), 'checked' => ! empty( $model['has_archive'] ) ],
							[ 'name' => 'hierarchical', 'label' => __( 'Hierarchical (page-like parent/child structure)', 'core-blueprint' ), 'checked' => ! empty( $model['hierarchical'] ) ],
						],
					] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output.
					?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Editor features', 'core-blueprint' ); ?></th><td>
					<?php
					$support_labels = [
						'title'           => __( 'Title', 'core-blueprint' ),
						'editor'          => __( 'Content editor', 'core-blueprint' ),
						'thumbnail'       => __( 'Featured image', 'core-blueprint' ),
						'excerpt'         => __( 'Excerpt', 'core-blueprint' ),
						'author'          => __( 'Author', 'core-blueprint' ),
						'revisions'       => __( 'Revisions', 'core-blueprint' ),
						'page-attributes' => __( 'Page attributes', 'core-blueprint' ),
						'custom-fields'   => __( 'WordPress native Custom Fields panel', 'core-blueprint' ),
						'comments'        => __( 'Comments', 'core-blueprint' ),
					];
					$support_options = [];
					foreach ( $support_labels as $support => $label ) {
						$support_options[] = [
							'name' => 'supports[]',
							'value' => $support,
							'label' => $label,
							'checked' => in_array( $support, (array) $model['supports'], true ),
						];
					}
					echo ChoiceGroup::render( [
						'aria_label' => __( 'Editor features', 'core-blueprint' ),
						'options' => $support_options,
					] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output.
					?>
				</td></tr>
				</tbody>
			</table>
			<?php submit_button( $duplicate ? __( 'Create Duplicate Post Type', 'core-blueprint' ) : ( $is_edit ? __( 'Save Post Type', 'core-blueprint' ) : __( 'Create Post Type', 'core-blueprint' ) ) ); ?>
		</form>
		<?php
	}

	private function render_post_type_delete( string $key ): void {
		$model = Repository::post_type( $key );
		if ( null === $model ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Post type definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}

		$used_by = [];
		foreach ( Repository::taxonomies() as $taxonomy ) {
			if ( in_array( $key, (array) ( $taxonomy['object_types'] ?? [] ), true ) ) {
				$used_by[] = (string) ( $taxonomy['plural_label'] ?? $taxonomy['key'] ?? '' );
			}
		}
		foreach ( Repository::field_groups() as $group ) {
			if ( in_array( $key, (array) ( $group['post_types'] ?? [] ), true ) ) {
				$used_by[] = (string) ( $group['title'] ?? $group['id'] ?? '' );
			}
		}
		$used_by_message = '';
		if ( ! empty( $used_by ) ) {
			/* translators: %s: comma-separated names of taxonomies or Field Groups using this post type. */
			$used_by_message = sprintf( __( 'This post type is still used by: %s. Update or remove those taxonomies or field groups first.', 'core-blueprint' ), implode( ', ', $used_by ) );
		}
		?>
		<h2><?php esc_html_e( 'Delete Post Type Definition', 'core-blueprint' ); ?></h2>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html( (string) $model['plural_label'] ); ?></strong> — <?php esc_html_e( 'This removes only the Core Blueprint definition. Existing database content is not deleted, but WordPress will stop recognising it as this post type until an equivalent definition is restored.', 'core-blueprint' ); ?></p></div>
		<?php if ( ! empty( $used_by ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $used_by_message ); ?></p></div>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_core_content_models_delete_post_type" />
				<input type="hidden" name="model" value="<?php echo esc_attr( $key ); ?>" />
				<?php wp_nonce_field( 'cb_core_content_models_delete_post_type' ); ?>
				<button type="submit" class="button cb-core-button cb-core-button--danger"><?php esc_html_e( 'Delete Definition', 'core-blueprint' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

}
