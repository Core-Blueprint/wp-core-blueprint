<?php
declare(strict_types=1);
/**
 * Content Models admin view module: TaxonomiesView.
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

trait TaxonomiesView {

	private function render_taxonomies(): void {
		$this->render_header( __( 'Create classification structures and attach them to standard or Core Blueprint-managed post types.', 'core-blueprint' ) );
		if ( ! State::is_enabled() ) {
			$this->render_disabled_panel();
			echo '</div>';
			return;
		}

		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$model = isset( $_GET['model'] ) ? sanitize_key( wp_unslash( $_GET['model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $view ) {
			$this->render_taxonomy_editor( $model );
		} elseif ( 'duplicate' === $view ) {
			$this->render_taxonomy_editor( $model, true );
		} elseif ( 'delete' === $view ) {
			$this->render_taxonomy_delete( $model );
		} else {
			$this->render_taxonomy_list();
		}
		echo '</div>';
	}

	private function render_taxonomy_list(): void {
		$models = Repository::taxonomies();
		$add_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'taxonomies', 'view' => 'edit' ], admin_url( 'admin.php' ) );
		?>
		<div class="cb-content-models-toolbar">
			<a class="button cb-core-button cb-core-button--primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add Taxonomy', 'core-blueprint' ); ?></a>
		</div>
		<?php if ( empty( $models ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'No Core Blueprint taxonomies have been created yet.', 'core-blueprint' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Taxonomy', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Key', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Assigned to', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Structure', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $models as $key => $definition ) :
					$edit_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'taxonomies', 'view' => 'edit', 'model' => $key ], admin_url( 'admin.php' ) );
					$duplicate_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'taxonomies', 'view' => 'duplicate', 'model' => $key ], admin_url( 'admin.php' ) );
					$delete_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'taxonomies', 'view' => 'delete', 'model' => $key ], admin_url( 'admin.php' ) );
				?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) ( $definition['plural_label'] ?? $key ) ); ?></a></strong><br><span class="description"><?php echo esc_html( (string) ( $definition['singular_label'] ?? '' ) ); ?></span></td>
						<td><code><?php echo esc_html( (string) $key ); ?></code></td>
						<td><?php echo esc_html( implode( ', ', (array) ( $definition['object_types'] ?? [] ) ) ); ?></td>
						<td><?php echo ! empty( $definition['hierarchical'] ) ? esc_html__( 'Hierarchical', 'core-blueprint' ) : esc_html__( 'Flat', 'core-blueprint' ); ?></td>
						<td class="cb-content-models-col-actions"><div class="cb-content-models-actions"><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--danger" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'core-blueprint' ); ?></a></div></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_taxonomy_editor( string $key, bool $duplicate = false ): void {
		$model = '' !== $key ? Repository::taxonomy( $key ) : null;
		if ( '' !== $key && null === $model ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Taxonomy definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		$duplicate_source = $duplicate ? $key : '';
		$is_edit = ! $duplicate && null !== $model;
		$model = array_replace( [
			'key'               => '',
			'singular_label'    => '',
			'plural_label'      => '',
			'description'       => '',
			'object_types'      => [ 'post' ],
			'public'            => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'show_admin_column' => true,
			'rewrite_slug'      => '',
		], is_array( $model ) ? $model : [] );
		if ( $duplicate ) {
			$model['key'] = $this->suggest_unique_model_key( (string) $model['key'], 'taxonomy', 32 );
			$model['singular_label'] = $this->copy_label( (string) $model['singular_label'] );
			$model['plural_label'] = $this->copy_label( (string) $model['plural_label'] );
			$model['rewrite_slug'] = (string) $model['key'];
		}

		$post_types = get_post_types( [ 'show_ui' => true ], 'objects' );
		uasort( $post_types, static fn( $a, $b ): int => strnatcasecmp( (string) $a->labels->name, (string) $b->labels->name ) );
		?>
		<h2><?php echo esc_html( $duplicate ? __( 'Duplicate Taxonomy', 'core-blueprint' ) : ( $is_edit ? __( 'Edit Taxonomy', 'core-blueprint' ) : __( 'Add Taxonomy', 'core-blueprint' ) ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-scope">
			<input type="hidden" name="action" value="cb_core_content_models_save_taxonomy" />
			<input type="hidden" name="original_key" value="<?php echo esc_attr( $is_edit ? (string) $model['key'] : '' ); ?>" />
			<input type="hidden" name="duplicate_source" value="<?php echo esc_attr( $duplicate_source ); ?>" />
			<?php wp_nonce_field( 'cb_core_content_models_save_taxonomy' ); ?>
			<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><label for="cb-cm-tax-singular"><?php esc_html_e( 'Singular label', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-tax-singular" name="singular_label" type="text" class="regular-text" required value="<?php echo esc_attr( (string) $model['singular_label'] ); ?>" /><p class="description"><?php esc_html_e( 'Example: Project Category', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-tax-plural"><?php esc_html_e( 'Plural label', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-tax-plural" name="plural_label" type="text" class="regular-text" required value="<?php echo esc_attr( (string) $model['plural_label'] ); ?>" /><p class="description"><?php esc_html_e( 'Example: Project Categories', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-tax-key"><?php esc_html_e( 'Taxonomy key', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-tax-key" name="key" type="text" class="regular-text code" maxlength="32" required <?php echo $is_edit ? 'readonly' : ''; ?> value="<?php echo esc_attr( (string) $model['key'] ); ?>" /><p class="description"><?php esc_html_e( 'Stored as the WordPress taxonomy identifier. This key becomes immutable after creation.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-tax-description"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></label></th><td><textarea id="cb-cm-tax-description" name="description" class="large-text" rows="3"><?php echo esc_textarea( (string) $model['description'] ); ?></textarea></td></tr>
			<tr><th scope="row"><label for="cb-cm-tax-rewrite"><?php esc_html_e( 'URL slug', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-tax-rewrite" name="rewrite_slug" type="text" class="regular-text code" value="<?php echo esc_attr( (string) $model['rewrite_slug'] ); ?>" /><p class="description"><?php esc_html_e( 'Used for public taxonomy archives. Leave empty to use the taxonomy key.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Assigned post types', 'core-blueprint' ); ?></th><td>
				<?php
				$object_type_options = [];
				foreach ( $post_types as $post_type ) {
					$object_type_options[] = [
						'name' => 'object_types[]',
						'value' => $post_type->name,
						'label' => (string) $post_type->labels->name . ' (' . $post_type->name . ')',
						'checked' => in_array( $post_type->name, (array) $model['object_types'], true ),
					];
				}
				echo ChoiceGroup::render( [
					'aria_label' => __( 'Assigned post types', 'core-blueprint' ),
					'options' => $object_type_options,
					'scrollable' => true,
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output.
				?>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Behaviour', 'core-blueprint' ); ?></th><td>
				<?php
				echo ChoiceGroup::render( [
					'aria_label' => __( 'Taxonomy behaviour', 'core-blueprint' ),
					'options' => [
						[ 'name' => 'public', 'label' => __( 'Publicly queryable', 'core-blueprint' ), 'checked' => ! empty( $model['public'] ) ],
						[ 'name' => 'show_in_rest', 'label' => __( 'Expose through the WordPress REST API', 'core-blueprint' ), 'checked' => ! empty( $model['show_in_rest'] ) ],
						[ 'name' => 'hierarchical', 'label' => __( 'Hierarchical (category-like)', 'core-blueprint' ), 'checked' => ! empty( $model['hierarchical'] ) ],
						[ 'name' => 'show_admin_column', 'label' => __( 'Show taxonomy column in post lists', 'core-blueprint' ), 'checked' => ! empty( $model['show_admin_column'] ) ],
					],
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output.
				?>
			</td></tr>
			</tbody></table>
			<?php submit_button( $duplicate ? __( 'Create Duplicate Taxonomy', 'core-blueprint' ) : ( $is_edit ? __( 'Save Taxonomy', 'core-blueprint' ) : __( 'Create Taxonomy', 'core-blueprint' ) ) ); ?>
		</form>
		<?php
	}

	private function render_taxonomy_delete( string $key ): void {
		$model = Repository::taxonomy( $key );
		if ( null === $model ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Taxonomy definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		?>
		<h2><?php esc_html_e( 'Delete Taxonomy Definition', 'core-blueprint' ); ?></h2>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html( (string) $model['plural_label'] ); ?></strong> — <?php esc_html_e( 'This removes only the Core Blueprint definition. Existing terms and relationships stay in the WordPress database, but the taxonomy is no longer registered until an equivalent definition is restored.', 'core-blueprint' ); ?></p></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cb_core_content_models_delete_taxonomy" />
			<input type="hidden" name="model" value="<?php echo esc_attr( $key ); ?>" />
			<?php wp_nonce_field( 'cb_core_content_models_delete_taxonomy' ); ?>
			<button type="submit" class="button cb-core-button cb-core-button--danger"><?php esc_html_e( 'Delete Definition', 'core-blueprint' ); ?></button>
		</form>
		<?php
	}

}
