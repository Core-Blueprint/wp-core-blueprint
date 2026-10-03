<?php
declare(strict_types=1);
/** Content Models FieldsView view module.
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

trait FieldsView {

	private function render_fields_table( string $group_id, array $fields ): void {
		$add_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'field', 'model' => $group_id ], admin_url( 'admin.php' ) );
		$field_order = implode( ',', array_map( 'strval', array_keys( $fields ) ) );
		$order_form_id = 'cb-cm-field-order-' . sanitize_html_class( $group_id );
		?>
		<div class="cb-content-models-divider" aria-hidden="true"></div>
		<section class="cb-content-models-section cb-content-models-section--fields">
			<div class="cb-content-models-section-header">
				<div>
					<h2><?php esc_html_e( 'Fields', 'core-blueprint' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Expand a field for quick editing without leaving this page, or open the full editor in a separate tab.', 'core-blueprint' ); ?></p>
				</div>
				<div class="cb-content-models-section-actions">
					<a class="button cb-core-button cb-core-button--primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add Field', 'core-blueprint' ); ?></a>
				</div>
			</div>
			<?php if ( empty( $fields ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This group does not contain fields yet.', 'core-blueprint' ); ?></p></div>
			<?php else : ?>
				<form id="<?php echo esc_attr( $order_form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cb_core_content_models_sort_fields" />
					<input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id ); ?>" />
					<input type="hidden" name="field_order" value="<?php echo esc_attr( $field_order ); ?>" data-cb-cm-order-input />
					<?php wp_nonce_field( 'cb_core_content_models_sort_fields' ); ?>
				</form>
				<div class="cb-content-models-sort-toolbar">
					<p class="description"><?php esc_html_e( 'Drag fields by the handle to change their display order in the editor, then save the new order.', 'core-blueprint' ); ?></p>
					<button type="submit" class="button cb-core-button cb-core-button--secondary" form="<?php echo esc_attr( $order_form_id ); ?>" data-cb-cm-order-save disabled><?php esc_html_e( 'Save Field Order', 'core-blueprint' ); ?></button>
				</div>
				<table class="widefat striped cb-content-models-fields-table"><thead><tr><th class="cb-content-models-fields-table__order"><?php esc_html_e( 'Order', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Field', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Meta key', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Type', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'REST API', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></th></tr></thead><tbody data-cb-cm-sortable data-order-form="<?php echo esc_attr( $order_form_id ); ?>">
				<?php foreach ( $fields as $field_id => $field ) :
					$edit_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'field', 'model' => $group_id, 'field' => $field_id ], admin_url( 'admin.php' ) );
					$duplicate_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'duplicate-field', 'model' => $group_id, 'field' => $field_id ], admin_url( 'admin.php' ) );
					$delete_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'delete-field', 'model' => $group_id, 'field' => $field_id ], admin_url( 'admin.php' ) );
					$type = (string) ( $field['type'] ?? 'text' );
					$panel_id = 'cb-cm-quick-' . sanitize_html_class( (string) $field_id );
					$default = $field['default_value'] ?? '';
					if ( is_array( $default ) ) {
						$default = implode( ', ', array_map( 'strval', $default ) );
					} elseif ( is_bool( $default ) ) {
						$default = $default ? '1' : '0';
					}
				?>
				<tr class="cb-content-models-field-summary" data-field-id="<?php echo esc_attr( $field_id ); ?>">
					<td class="cb-content-models-drag-cell"><span class="cb-content-models-drag-handle dashicons dashicons-menu" draggable="true" data-cb-cm-drag-handle aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Drag to reorder', 'core-blueprint' ); ?></span></td>
					<td><button type="button" class="button-link cb-content-models-field-toggle" data-cb-cm-quick-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><strong data-cb-cm-summary-label><?php echo esc_html( (string) ( $field['label'] ?? $field_id ) ); ?></strong></button><?php echo ! empty( $field['required'] ) ? ' <span class="description" data-cb-cm-summary-required>(' . esc_html__( 'required', 'core-blueprint' ) . ')</span>' : '<span class="description" data-cb-cm-summary-required hidden></span>'; ?></td>
					<td><code><?php echo esc_html( (string) ( $field['name'] ?? '' ) ); ?></code></td>
					<td data-cb-cm-summary-type><?php echo esc_html( FieldTypes::labels()[ $type ] ?? $type ); ?></td>
					<td data-cb-cm-summary-rest><?php echo ! empty( $field['show_in_rest'] ) ? esc_html__( 'Enabled', 'core-blueprint' ) : esc_html__( 'Disabled', 'core-blueprint' ); ?></td>
					<td class="cb-content-models-col-actions"><div class="cb-content-models-actions"><button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-cm-quick-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'Quick Edit', 'core-blueprint' ); ?></button><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Full Editor ↗', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'core-blueprint' ); ?></a><a class="button cb-core-button cb-core-button--danger" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'core-blueprint' ); ?></a></div></td>
				</tr>
				<tr id="<?php echo esc_attr( $panel_id ); ?>" class="cb-content-models-quick-row" data-cb-cm-quick-row data-field-id="<?php echo esc_attr( $field_id ); ?>" hidden>
					<td colspan="6">
						<div class="cb-content-models-quick-panel">
							<div class="cb-content-models-quick-header"><div><strong><?php esc_html_e( 'Quick Edit', 'core-blueprint' ); ?></strong><p class="description"><?php esc_html_e( 'Common field settings can be changed here without leaving the field group.', 'core-blueprint' ); ?></p></div><a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open full editor ↗', 'core-blueprint' ); ?></a></div>
							<form class="cb-content-models-quick-form" data-cb-cm-quick-form>
								<input type="hidden" name="action" value="cb_core_content_models_quick_save_field" />
								<input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id ); ?>" />
								<input type="hidden" name="original_field_id" value="<?php echo esc_attr( $field_id ); ?>" />
								<input type="hidden" name="name" value="<?php echo esc_attr( (string) ( $field['name'] ?? '' ) ); ?>" />
								<input type="hidden" name="confirm_type_change" value="0" data-cb-cm-confirm-type-change />
								<?php wp_nonce_field( 'cb_core_content_models_quick_save_field', '_ajax_nonce' ); ?>
								<div class="cb-content-models-quick-grid">
									<label><span><?php esc_html_e( 'Field label', 'core-blueprint' ); ?></span><input name="label" type="text" required value="<?php echo esc_attr( (string) ( $field['label'] ?? '' ) ); ?>" /></label>
									<label><span><?php esc_html_e( 'Meta key', 'core-blueprint' ); ?></span><input type="text" class="code" readonly value="<?php echo esc_attr( (string) ( $field['name'] ?? '' ) ); ?>" /></label>
									<div class="cb-content-models-quick-field"><label for="cb-cm-field-type-<?php echo esc_attr( $field_id ); ?>"><span><?php esc_html_e( 'Field type', 'core-blueprint' ); ?></span></label><select id="cb-cm-field-type-<?php echo esc_attr( $field_id ); ?>" name="type" data-cb-cm-field-type data-cb-core-select-picker data-cb-core-select-picker-search="true" data-original-type="<?php echo esc_attr( $type ); ?>"><?php foreach ( FieldTypes::grouped_labels() as $group_label => $group_types ) : ?><optgroup label="<?php echo esc_attr( $group_label ); ?>"><?php foreach ( $group_types as $option_type => $option_label ) : ?><option value="<?php echo esc_attr( $option_type ); ?>" <?php selected( $type, $option_type ); ?>><?php echo esc_html( $option_label ); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
									<label><span><?php esc_html_e( 'Placeholder', 'core-blueprint' ); ?></span><input name="placeholder" type="text" value="<?php echo esc_attr( (string) ( $field['placeholder'] ?? '' ) ); ?>" /></label>
								</div>
								<?php $this->render_relation_settings( $field, true ); ?>
								<label class="cb-content-models-quick-wide"><span><?php esc_html_e( 'Instructions', 'core-blueprint' ); ?></span><textarea name="instructions" rows="2"><?php echo esc_textarea( (string) ( $field['instructions'] ?? '' ) ); ?></textarea></label>
								<div class="cb-content-models-quick-flags"><?php echo ChoiceGroup::render( [ 'compact' => true, 'aria_label' => __( 'Field behaviour', 'core-blueprint' ), 'options' => [ [ 'name' => 'required', 'label' => __( 'Required', 'core-blueprint' ), 'checked' => ! empty( $field['required'] ) ], [ 'name' => 'show_in_rest', 'label' => __( 'Expose through REST API', 'core-blueprint' ), 'checked' => ! empty( $field['show_in_rest'] ) ] ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output. ?></div>
								<div class="cb-content-models-quick-grid cb-content-models-quick-grid--advanced">
									<label><span><?php esc_html_e( 'Default value', 'core-blueprint' ); ?></span><input name="default_value" type="text" value="<?php echo esc_attr( (string) $default ); ?>" /></label>
									<label><span><?php esc_html_e( 'Editor rows', 'core-blueprint' ); ?></span><input name="rows" type="number" min="2" max="20" value="<?php echo esc_attr( (string) ( $field['rows'] ?? 5 ) ); ?>" /></label>
									<label><span><?php esc_html_e( 'Min', 'core-blueprint' ); ?></span><input name="min" type="number" step="any" value="<?php echo esc_attr( (string) ( $field['min'] ?? '' ) ); ?>" /></label>
									<label><span><?php esc_html_e( 'Max', 'core-blueprint' ); ?></span><input name="max" type="number" step="any" value="<?php echo esc_attr( (string) ( $field['max'] ?? '' ) ); ?>" /></label>
									<label><span><?php esc_html_e( 'Step', 'core-blueprint' ); ?></span><input name="step" type="number" step="any" value="<?php echo esc_attr( (string) ( $field['step'] ?? '' ) ); ?>" /></label>
								</div>
								<label class="cb-content-models-quick-wide"><span><?php esc_html_e( 'Choices', 'core-blueprint' ); ?></span><textarea name="choices_text" class="code" rows="4"><?php echo esc_textarea( FieldTypes::choices_to_text( (array) ( $field['choices'] ?? [] ) ) ); ?></textarea><span class="description"><?php esc_html_e( 'Used by Select, Radio and Checkbox. One choice per line: value : Label.', 'core-blueprint' ); ?></span></label>
								<div class="cb-content-models-quick-footer"><button type="submit" class="button cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save Field', 'core-blueprint' ); ?></button><span class="cb-content-models-quick-status" role="status" aria-live="polite" data-cb-cm-quick-status></span></div>
							</form>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_field_editor( string $group_id, string $field_id, bool $duplicate = false ): void {
		$group = Repository::field_group( $group_id );
		if ( null === $group ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Field group definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		$field = '' !== $field_id ? Repository::field( $group_id, $field_id ) : null;
		if ( '' !== $field_id && null === $field ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Field definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		$duplicate_source = $duplicate ? $field_id : '';
		$is_edit = ! $duplicate && null !== $field;
		$field = array_replace( [
			'id'            => '',
			'name'          => '',
			'label'         => '',
			'type'          => 'text',
			'instructions'  => '',
			'required'      => false,
			'show_in_rest'  => false,
			'placeholder'   => '',
			'default_value' => '',
			'choices'       => [],
			'min'           => '',
			'max'           => '',
			'step'          => '',
			'rows'                => 5,
			'relation_multiple'   => false,
			'relation_post_types' => [],
			'relation_roles'      => [],
			'relation_taxonomies' => [],
			'sub_fields'           => [],
			'repeater_min'         => 0,
			'repeater_max'         => 0,
			'conditional_logic'    => [],
		], is_array( $field ) ? $field : [] );
		if ( $duplicate ) {
			$field['id'] = '';
			$field['label'] = $this->copy_label( (string) $field['label'] );
			$field['name'] = Repository::suggest_field_copy_name( $group_id, (string) $field['name'] );
		}
		$back_url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'field-groups', 'view' => 'edit', 'model' => $group_id ], admin_url( 'admin.php' ) );
		$default = $field['default_value'];
		if ( is_array( $default ) ) {
			$default = implode( ', ', array_map( 'strval', $default ) );
		} elseif ( is_bool( $default ) ) {
			$default = $default ? '1' : '0';
		}
		?>
		<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php echo esc_html( (string) $group['title'] ); ?></a></p>
		<h2><?php echo esc_html( $duplicate ? __( 'Duplicate Field', 'core-blueprint' ) : ( $is_edit ? __( 'Edit Field', 'core-blueprint' ) : __( 'Add Field', 'core-blueprint' ) ) ); ?></h2>
		<?php if ( $is_edit ) : ?><div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Field storage is protected.', 'core-blueprint' ); ?></strong> <?php esc_html_e( 'The meta key cannot be changed after creation. Changing the field type is allowed after confirmation because existing WordPress metadata may be interpreted differently. All other field settings remain editable.', 'core-blueprint' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-scope">
			<input type="hidden" name="action" value="cb_core_content_models_save_field" /><input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id ); ?>" /><input type="hidden" name="original_field_id" value="<?php echo esc_attr( $is_edit ? (string) $field['id'] : '' ); ?>" /><input type="hidden" name="duplicate_source_field" value="<?php echo esc_attr( $duplicate_source ); ?>" /><input type="hidden" name="confirm_type_change" value="0" data-cb-cm-confirm-type-change />
			<?php wp_nonce_field( 'cb_core_content_models_save_field' ); ?>
			<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><label for="cb-cm-field-label"><?php esc_html_e( 'Field label', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-field-label" name="label" type="text" class="regular-text" required value="<?php echo esc_attr( (string) $field['label'] ); ?>" /></td></tr>
			<tr><th scope="row"><label for="cb-cm-field-name"><?php esc_html_e( 'Field name / meta key', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-field-name" name="name" type="text" class="regular-text code" required <?php echo $is_edit ? 'readonly' : ''; ?> value="<?php echo esc_attr( (string) $field['name'] ); ?>" /><p class="description"><?php esc_html_e( 'Stable field key used by native WordPress storage. Post locations use post meta; Option Pages use the Options API; Term and User locations use their native metadata APIs.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-field-type"><?php esc_html_e( 'Field type', 'core-blueprint' ); ?></label></th><td><select id="cb-cm-field-type" name="type" data-cb-cm-field-type data-cb-core-select-picker data-cb-core-select-picker-search="true" data-original-type="<?php echo esc_attr( (string) $field['type'] ); ?>"><?php foreach ( FieldTypes::grouped_labels() as $group_label => $group_types ) : ?><optgroup label="<?php echo esc_attr( $group_label ); ?>"><?php foreach ( $group_types as $type => $label ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field['type'], $type ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select><?php if ( $is_edit ) : ?><p class="description"><?php esc_html_e( 'Changing the type requires confirmation. Existing field values are preserved and are not automatically migrated.', 'core-blueprint' ); ?></p><?php endif; ?></td></tr>
			<tr data-cb-cm-relation-settings-row<?php echo FieldTypes::is_relation_type( (string) $field['type'] ) ? '' : ' hidden'; ?>><th scope="row"><?php esc_html_e( 'Relation settings', 'core-blueprint' ); ?></th><td><?php $this->render_relation_settings( $field, false ); ?></td></tr>
			<tr data-cb-cm-structured-settings-row<?php echo FieldTypes::is_structured_type( (string) $field['type'] ) ? '' : ' hidden'; ?>><th scope="row"><?php esc_html_e( 'Structured field schema', 'core-blueprint' ); ?></th><td><?php $this->render_structured_settings( $field ); ?></td></tr>
			<tr><th scope="row"><label for="cb-cm-field-instructions"><?php esc_html_e( 'Instructions', 'core-blueprint' ); ?></label></th><td><textarea id="cb-cm-field-instructions" name="instructions" class="large-text" rows="3"><?php echo esc_textarea( (string) $field['instructions'] ); ?></textarea></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Behaviour', 'core-blueprint' ); ?></th><td><?php echo ChoiceGroup::render( [ 'aria_label' => __( 'Field behaviour', 'core-blueprint' ), 'options' => [ [ 'name' => 'required', 'label' => __( 'Required', 'core-blueprint' ), 'checked' => ! empty( $field['required'] ) ], [ 'name' => 'show_in_rest', 'label' => __( 'Expose this field through the WordPress REST API', 'core-blueprint' ), 'checked' => ! empty( $field['show_in_rest'] ) ] ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output. ?><p class="description"><?php esc_html_e( 'REST exposure is opt-in. Storage remains native WordPress data regardless of this setting.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-field-placeholder"><?php esc_html_e( 'Placeholder', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-field-placeholder" name="placeholder" type="text" class="regular-text" value="<?php echo esc_attr( (string) $field['placeholder'] ); ?>" /></td></tr>
			<tr><th scope="row"><label for="cb-cm-field-default"><?php esc_html_e( 'Default value', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-field-default" name="default_value" type="text" class="regular-text" value="<?php echo esc_attr( (string) $default ); ?>" /><p class="description"><?php esc_html_e( 'For Checkbox fields, separate multiple default choice values with commas. For True / False use 1 or 0. Media and Relation fields use an empty default and ignore this setting.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cb-cm-field-choices"><?php esc_html_e( 'Choices', 'core-blueprint' ); ?></label></th><td><textarea id="cb-cm-field-choices" name="choices_text" class="large-text code" rows="6"><?php echo esc_textarea( FieldTypes::choices_to_text( (array) $field['choices'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Used by Select, Radio and Checkbox. One choice per line: value : Label. A line containing only a value uses that value as the label.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Number constraints', 'core-blueprint' ); ?></th><td><label><?php esc_html_e( 'Min', 'core-blueprint' ); ?> <input name="min" type="number" step="any" value="<?php echo esc_attr( (string) $field['min'] ); ?>" /></label> &nbsp; <label><?php esc_html_e( 'Max', 'core-blueprint' ); ?> <input name="max" type="number" step="any" value="<?php echo esc_attr( (string) $field['max'] ); ?>" /></label> &nbsp; <label><?php esc_html_e( 'Step', 'core-blueprint' ); ?> <input name="step" type="number" step="any" value="<?php echo esc_attr( (string) $field['step'] ); ?>" /></label></td></tr>
			<tr><th scope="row"><label for="cb-cm-field-rows"><?php esc_html_e( 'Editor rows', 'core-blueprint' ); ?></label></th><td><input id="cb-cm-field-rows" name="rows" type="number" min="2" max="20" value="<?php echo esc_attr( (string) $field['rows'] ); ?>" /><p class="description"><?php esc_html_e( 'Used by Textarea and WYSIWYG.', 'core-blueprint' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Conditional Logic', 'core-blueprint' ); ?></th><td><?php $this->render_conditional_logic( $group, $field ); ?></td></tr>
			</tbody></table>
			<?php submit_button( $duplicate ? __( 'Create Duplicate Field', 'core-blueprint' ) : ( $is_edit ? __( 'Save Field', 'core-blueprint' ) : __( 'Create Field', 'core-blueprint' ) ) ); ?>
		</form>
		<?php
	}


	/** @param array<string,mixed> $field */

	private function render_field_delete( string $group_id, string $field_id ): void {
		$group = Repository::field_group( $group_id );
		$field = Repository::field( $group_id, $field_id );
		if ( null === $group || null === $field ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Field definition not found.', 'core-blueprint' ) . '</p></div>';
			return;
		}
		?>
		<h2><?php esc_html_e( 'Delete Field Definition', 'core-blueprint' ); ?></h2>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html( (string) $field['label'] ); ?></strong> (<code><?php echo esc_html( (string) $field['name'] ); ?></code>) — <?php esc_html_e( 'The editor field will disappear, but existing metadata values are deliberately preserved.', 'core-blueprint' ); ?></p></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="cb_core_content_models_delete_field" /><input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id ); ?>" /><input type="hidden" name="field_id" value="<?php echo esc_attr( $field_id ); ?>" /><?php wp_nonce_field( 'cb_core_content_models_delete_field' ); ?><button type="submit" class="button cb-core-button cb-core-button--danger"><?php esc_html_e( 'Delete Definition', 'core-blueprint' ); ?></button></form>
		<?php
	}

}
