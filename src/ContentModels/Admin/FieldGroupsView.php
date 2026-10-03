<?php
declare(strict_types=1);
/** Content Models FieldGroupsView view module.
 * @package Core_Blueprint
 * @since 1.0.0
 */
namespace CoreBlueprint\Core\ContentModels\Admin;

use CoreBlueprint\Core\ContentModels\FieldTypes;
use CoreBlueprint\Core\ContentModels\Repository;
use CoreBlueprint\Core\ContentModels\Runtime;
use CoreBlueprint\Core\ContentModels\State;
use CoreBlueprint\Core\UI\ChoiceGroup;
use CoreBlueprint\Core\UI\Icon;
use CoreBlueprint\Core\UI\Status as StatusUi;

defined( 'ABSPATH' ) || exit;

trait FieldGroupsView {

	private function render_field_groups(): void {
		$this->render_header( __( 'Define native WordPress fields and choose which post types or Option Pages should display them. Field definitions are governed by Core Blueprint; values remain native WordPress post meta or options.', 'core-blueprint' ) );
		if ( ! State::is_enabled() ) {
			$this->render_disabled_panel();
			echo '</div>';
			return;
		}

		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$model = isset( $_GET['model'] ) ? sanitize_key( wp_unslash( $_GET['model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$field = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $view ) {
			$this->render_field_group_editor( $model );
		} elseif ( 'duplicate' === $view ) {
			$this->render_field_group_editor( $model, true );
		} elseif ( 'delete' === $view ) {
			$this->render_field_group_delete( $model );
		} elseif ( 'field' === $view ) {
			$this->render_field_editor( $model, $field );
		} elseif ( 'duplicate-field' === $view ) {
			$this->render_field_editor( $model, $field, true );
		} elseif ( 'delete-field' === $view ) {
			$this->render_field_delete( $model, $field );
		} else {
			$this->render_field_group_list();
		}
		echo '</div>';
	}

	private function render_field_group_list(): void {
		$groups = Repository::field_groups();
		$add_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'edit' ], admin_url( 'admin.php' ) );
		?>
		<div class="cb-content-models-toolbar"><a class="button cb-core-button cb-core-button--primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add Field Group', 'core-blueprint' ); ?></a></div>
		<?php if ( empty( $groups ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'No Core Blueprint field groups have been created yet.', 'core-blueprint' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped cb-content-models-table">
				<thead><tr><th><?php esc_html_e( 'Field Group', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Location', 'core-blueprint' ); ?></th><th class="cb-content-models-col-fields"><?php esc_html_e( 'Fields', 'core-blueprint' ); ?></th><th class="cb-content-models-col-actions"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $groups as $id => $group ) :
					$edit_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'edit', 'model' => $id ], admin_url( 'admin.php' ) );
					$duplicate_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'duplicate', 'model' => $id ], admin_url( 'admin.php' ) );
					$delete_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'delete', 'model' => $id ], admin_url( 'admin.php' ) );
					$post_targets = array_map( [ $this, 'post_type_label' ], (array) ( $group['post_types'] ?? [] ) );
					$option_targets = array_map( [ $this, 'option_page_label' ], (array) ( $group['option_pages'] ?? [] ) );
					$term_targets = array_map( static function ( string $taxonomy ): string {
						$object = get_taxonomy( $taxonomy );
						return $object ? (string) $object->labels->singular_name : $taxonomy;
					}, (array) ( $group['term_taxonomies'] ?? [] ) );
					$user_target = ! empty( $group['user_enabled'] ) ? __( 'User profiles', 'core-blueprint' ) : '';
				?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) ( $group['title'] ?? $id ) ); ?></a></strong><br><code><?php echo esc_html( (string) $id ); ?></code></td>
						<td><?php $locations = []; if ( ! empty( $post_targets ) ) { $locations[] = __( 'Posts:', 'core-blueprint' ) . ' ' . implode( ', ', $post_targets ); } if ( ! empty( $option_targets ) ) { $locations[] = __( 'Options:', 'core-blueprint' ) . ' ' . implode( ', ', $option_targets ); } if ( ! empty( $term_targets ) ) { $locations[] = __( 'Terms:', 'core-blueprint' ) . ' ' . implode( ', ', $term_targets ); } if ( '' !== $user_target ) { $locations[] = __( 'Users:', 'core-blueprint' ) . ' ' . $user_target; } echo esc_html( implode( ' · ', $locations ) ); ?></td>
						<td class="cb-content-models-col-fields"><?php echo esc_html( (string) count( (array) ( $group['fields'] ?? [] ) ) ); ?></td>
						<td class="cb-content-models-col-actions"><div class="cb-content-models-actions"><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--danger" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'core-blueprint' ); ?></a></div></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_field_group_editor( string $id, bool $duplicate = false ): void {
		$group = '' !== $id ? Repository::field_group( $id ) : null;
		if ( '' !== $id && null === $group ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Field group definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		$duplicate_source = $duplicate ? $id : '';
		$is_edit = ! $duplicate && null !== $group;
		$group = array_replace( [
			'id'          => '',
			'title'       => '',
			'description' => '',
			'post_types'   => [],
			'option_pages'    => [],
			'term_taxonomies' => [],
			'user_enabled'    => false,
			'user_roles'      => [],
			'context'         => 'normal',
			'priority'    => 'default',
			'fields'      => [],
		], is_array( $group ) ? $group : [] );
		$duplicate_field_count = $duplicate ? count( (array) $group['fields'] ) : 0;
		if ( $duplicate ) {
			$group['id'] = '';
			$group['title'] = $this->copy_label( (string) $group['title'] );
			$group['post_types'] = [];
			$group['option_pages'] = [];
			$group['term_taxonomies'] = [];
			$group['user_enabled'] = false;
			$group['user_roles'] = [];
		}

		$post_types = get_post_types( [ 'show_ui' => true ], 'objects' );
		uasort( $post_types, static fn( $a, $b ): int => strnatcasecmp( (string) $a->labels->name, (string) $b->labels->name ) );
		$option_pages = Repository::option_pages();
		$term_taxonomies = get_taxonomies( [ 'show_ui' => true ], 'objects' );
		uasort( $term_taxonomies, static fn( $a, $b ): int => strnatcasecmp( (string) $a->labels->name, (string) $b->labels->name ) );
		$roles = wp_roles();
		$user_roles = $roles ? $roles->roles : [];
		$duplicate_message = '';
		if ( $duplicate ) {
			/* translators: %d: number of fields being copied into the duplicated Field Group. */
			$duplicate_message = sprintf( _n( '%d field will be copied with a new internal ID. Choose a location before creating the duplicate; overlapping locations with the same meta keys are blocked.', '%d fields will be copied with new internal IDs. Choose a location before creating the duplicate; overlapping locations with the same meta keys are blocked.', $duplicate_field_count, 'core-blueprint' ), $duplicate_field_count );
		}
		?>
		<section class="cb-content-models-section cb-content-models-section--settings">
			<h2><?php echo esc_html( $duplicate ? __( 'Duplicate Field Group', 'core-blueprint' ) : ( $is_edit ? __( 'Edit Field Group', 'core-blueprint' ) : __( 'Add Field Group', 'core-blueprint' ) ) ); ?></h2>
			<?php if ( $duplicate ) : ?><div class="notice notice-info inline"><p><?php echo esc_html( $duplicate_message ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-scope cb-content-models-group-form">
				<input type="hidden" name="action" value="cb_core_content_models_save_field_group" />
				<input type="hidden" name="original_id" value="<?php echo esc_attr( $is_edit ? (string) $group['id'] : '' ); ?>" />
				<input type="hidden" name="duplicate_source" value="<?php echo esc_attr( $duplicate_source ); ?>" />
				<?php wp_nonce_field( 'cb_core_content_models_save_field_group' ); ?>
				<table class="form-table cb-content-models-form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="cb-cm-group-title"><?php esc_html_e( 'Title', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-group-title" name="title" type="text" class="regular-text" required value="<?php echo esc_attr( (string) $group['title'] ); ?>" /></td></tr>
				<tr><th scope="row"><label for="cb-cm-group-description"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></label></th><td><textarea id="cb-cm-group-description" name="description" class="large-text" rows="3"><?php echo esc_textarea( (string) $group['description'] ); ?></textarea></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Show this field group on', 'core-blueprint' ); ?></th><td>
					<strong class="cb-content-models-location-title"><?php esc_html_e( 'Post Types', 'core-blueprint' ); ?></strong>
					<?php
					$location_post_type_options = [];
					foreach ( $post_types as $post_type ) {
						$location_post_type_options[] = [
							'name' => 'post_types[]',
							'value' => $post_type->name,
							'label' => (string) $post_type->labels->name . ' (' . $post_type->name . ')',
							'checked' => in_array( $post_type->name, (array) $group['post_types'], true ),
						];
					}
					echo ChoiceGroup::render( [
						'aria_label' => __( 'Post Types', 'core-blueprint' ),
						'options' => $location_post_type_options,
						'scrollable' => true,
					] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output.
					?>
					<strong class="cb-content-models-location-title cb-content-models-location-title--secondary"><?php esc_html_e( 'Options Pages', 'core-blueprint' ); ?></strong>
					<?php if ( empty( $option_pages ) ) : ?>
						<p class="description"><?php esc_html_e( 'No Option Pages exist yet. Create one in the Options Pages tab to use it as a field-group location.', 'core-blueprint' ); ?></p>
					<?php else : ?>
						<?php
						$location_option_page_options = [];
						foreach ( $option_pages as $page_slug => $option_page ) {
							$location_option_page_options[] = [
								'name' => 'option_pages[]',
								'value' => (string) $page_slug,
								'label' => (string) ( $option_page['title'] ?? $page_slug ) . ' (' . $page_slug . ')',
								'checked' => in_array( (string) $page_slug, (array) $group['option_pages'], true ),
							];
						}
						echo ChoiceGroup::render( [
							'aria_label' => __( 'Options Pages', 'core-blueprint' ),
							'options' => $location_option_page_options,
						] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output.
						?>
					<?php endif; ?>
					<strong class="cb-content-models-location-title cb-content-models-location-title--secondary"><?php esc_html_e( 'Taxonomy Terms', 'core-blueprint' ); ?></strong>
					<?php
					$location_taxonomy_options = [];
					foreach ( $term_taxonomies as $taxonomy ) {
						$location_taxonomy_options[] = [
							'name' => 'term_taxonomies[]',
							'value' => $taxonomy->name,
							'label' => (string) $taxonomy->labels->name . ' (' . $taxonomy->name . ')',
							'checked' => in_array( $taxonomy->name, (array) $group['term_taxonomies'], true ),
						];
					}
					echo ChoiceGroup::render( [ 'aria_label' => __( 'Taxonomy Terms', 'core-blueprint' ), 'options' => $location_taxonomy_options, 'scrollable' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<strong class="cb-content-models-location-title cb-content-models-location-title--secondary"><?php esc_html_e( 'User Profiles', 'core-blueprint' ); ?></strong>
					<div class="cb-core-stack cb-core-stack--compact">
						<?php echo ChoiceGroup::render( [ 'aria_label' => __( 'User profile location', 'core-blueprint' ), 'options' => [ [ 'name' => 'user_enabled', 'label' => __( 'Show this field group on user profile/edit screens', 'core-blueprint' ), 'checked' => ! empty( $group['user_enabled'] ) ] ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php
						$location_user_role_options = [];
						foreach ( $user_roles as $role_key => $role_data ) {
							$location_user_role_options[] = [ 'name' => 'user_roles[]', 'value' => (string) $role_key, 'label' => (string) ( $role_data['name'] ?? $role_key ) . ' (' . $role_key . ')', 'checked' => in_array( (string) $role_key, (array) $group['user_roles'], true ) ];
						}
						echo ChoiceGroup::render( [ 'aria_label' => __( 'Limit user profiles by role', 'core-blueprint' ), 'options' => $location_user_role_options, 'compact' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
					<p class="description"><?php esc_html_e( 'A group may target Post Meta, Options API, Term Meta, User Meta, or any combination. Leaving all user roles unchecked means all user profiles.', 'core-blueprint' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="cb-cm-group-context"><?php esc_html_e( 'Post editor position', 'core-blueprint' ); ?></label></th><td><select id="cb-cm-group-context" name="context"><option value="normal" <?php selected( $group['context'], 'normal' ); ?>><?php esc_html_e( 'Main column', 'core-blueprint' ); ?></option><option value="side" <?php selected( $group['context'], 'side' ); ?>><?php esc_html_e( 'Sidebar', 'core-blueprint' ); ?></option></select></td></tr>
				<tr><th scope="row"><label for="cb-cm-group-priority"><?php esc_html_e( 'Priority', 'core-blueprint' ); ?></label></th><td><select id="cb-cm-group-priority" name="priority"><option value="high" <?php selected( $group['priority'], 'high' ); ?>><?php esc_html_e( 'High', 'core-blueprint' ); ?></option><option value="default" <?php selected( $group['priority'], 'default' ); ?>><?php esc_html_e( 'Default', 'core-blueprint' ); ?></option><option value="low" <?php selected( $group['priority'], 'low' ); ?>><?php esc_html_e( 'Low', 'core-blueprint' ); ?></option></select></td></tr>
				</tbody></table>
				<?php submit_button( $duplicate ? __( 'Create Duplicate Field Group', 'core-blueprint' ) : ( $is_edit ? __( 'Save Field Group', 'core-blueprint' ) : __( 'Create Field Group', 'core-blueprint' ) ) ); ?>
			</form>
		</section>
		<?php
		if ( $is_edit ) {
			$this->render_fields_table( (string) $group['id'], (array) $group['fields'] );
		}
	}

	/** @param array<string,array<string,mixed>> $fields */

	private function render_field_group_delete( string $id ): void {
		$group = Repository::field_group( $id );
		if ( null === $group ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Field group definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		?>
		<h2><?php esc_html_e( 'Delete Field Group Definition', 'core-blueprint' ); ?></h2>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html( (string) $group['title'] ); ?></strong> — <?php esc_html_e( 'This removes the field definitions and editor UI only. Existing post-meta and Option Page values remain stored in WordPress.', 'core-blueprint' ); ?></p></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="cb_core_content_models_delete_field_group" /><input type="hidden" name="model" value="<?php echo esc_attr( $id ); ?>" /><?php wp_nonce_field( 'cb_core_content_models_delete_field_group' ); ?><button type="submit" class="button cb-core-button cb-core-button--danger"><?php esc_html_e( 'Delete Definition', 'core-blueprint' ); ?></button></form>
		<?php
	}

}
