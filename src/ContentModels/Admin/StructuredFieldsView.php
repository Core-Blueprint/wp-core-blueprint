<?php
declare(strict_types=1);
/** Content Models StructuredFieldsView view module.
 * @package Core_Blueprint
 * @since 1.0.0
 */
namespace CB\Core\ContentModels\Admin;

use CB\Core\ContentModels\FieldTypes;
use CB\Core\ContentModels\Repository;
use CB\Core\ContentModels\Runtime;
use CB\Core\ContentModels\State;
use CB\Core\UI\ChoiceGroup;
use CB\Core\UI\Icon;
use CB\Core\UI\Status as StatusUi;

defined( 'ABSPATH' ) || exit;

trait StructuredFieldsView {

	private function render_structured_settings( array $field ): void {
		$sub_fields = FieldTypes::sub_fields( $field );
		$type = (string) ( $field['type'] ?? '' );
		?>
		<div class="cb-content-models-structured" data-cb-cm-structured-settings data-cb-core-reorder data-empty-subfield-label="<?php echo esc_attr__( 'New subfield', 'core-blueprint' ); ?>">
			<p class="description"><?php esc_html_e( 'Group stores one associative array. Repeater stores an ordered array of rows. Subfield names are stable schema keys; existing structured values are never automatically migrated when a subfield type changes.', 'core-blueprint' ); ?></p>
			<div class="cb-content-models-repeater-limits" data-cb-cm-repeater-limits <?php echo 'repeater' === $type ? '' : 'hidden'; ?>>
				<label><?php esc_html_e( 'Minimum rows', 'core-blueprint' ); ?> <input type="number" min="0" max="100" name="repeater_min" value="<?php echo esc_attr( (string) ( $field['repeater_min'] ?? 0 ) ); ?>" /></label>
				<label><?php esc_html_e( 'Maximum rows', 'core-blueprint' ); ?> <input type="number" min="0" max="500" name="repeater_max" value="<?php echo esc_attr( (string) ( $field['repeater_max'] ?? 0 ) ); ?>" /></label>
				<p class="description"><?php esc_html_e( 'Use 0 for no explicit limit. A non-zero maximum may not be lower than the minimum.', 'core-blueprint' ); ?></p>
			</div>
			<div class="cb-content-models-subfields" data-cb-cm-subfields data-cb-core-reorder-list="subfields" data-cb-core-reorder-list-label="<?php echo esc_attr__( 'Subfields', 'core-blueprint' ); ?>">
				<?php $index = 0; foreach ( $sub_fields as $sub_field ) : $this->render_subfield_row( $sub_field, $index++ ); endforeach; ?>
			</div>
			<button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-cm-add-subfield><?php esc_html_e( 'Add Subfield', 'core-blueprint' ); ?></button>
			<template data-cb-cm-subfield-template><?php $this->render_subfield_row( [], '__INDEX__' ); ?></template>
		</div>
		<?php
	}

	/** @param array<string,mixed> $sub_field @param int|string $index */

	private function render_subfield_row( array $sub_field, $index ): void {
		$sub_field = array_replace( [
			'id' => '', 'label' => '', 'name' => '', 'type' => 'text', 'instructions' => '', 'required' => false,
			'placeholder' => '', 'default_value' => '', 'choices' => [], 'min' => '', 'max' => '', 'step' => '', 'rows' => 5,
			'relation_multiple' => false, 'relation_post_types' => [], 'relation_roles' => [], 'relation_taxonomies' => [],
		], $sub_field );
		$prefix = 'sub_fields[' . $index . ']';
		$type = (string) $sub_field['type'];
		$atomic_groups = FieldTypes::grouped_labels();
		unset( $atomic_groups[ __( 'Structured', 'core-blueprint' ) ] );
		?>
		<?php $reorder_label = '' !== (string) $sub_field['label'] ? (string) $sub_field['label'] : __( 'New subfield', 'core-blueprint' ); ?>
		<div class="cb-content-models-subfield" data-cb-cm-subfield data-cb-core-reorder-item="subfield-<?php echo esc_attr( (string) $index ); ?>" data-cb-core-reorder-label="<?php echo esc_attr( $reorder_label ); ?>">
			<div class="cb-content-models-subfield__header">
				<button type="button" class="button-link cb-content-models-subfield__drag" data-cb-cm-subfield-handle data-cb-core-reorder-handle aria-label="<?php echo esc_attr( sprintf( __( 'Reorder %s', 'core-blueprint' ), $reorder_label ) ); ?>"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>
				<strong data-cb-cm-subfield-title><?php echo esc_html( $reorder_label ); ?></strong>
				<button type="button" class="button-link-delete" data-cb-cm-remove-subfield><?php esc_html_e( 'Remove', 'core-blueprint' ); ?></button>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( (string) $sub_field['id'] ); ?>" />
			<div class="cb-content-models-subfield__grid">
				<label><span><?php esc_html_e( 'Label', 'core-blueprint' ); ?></span><input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( (string) $sub_field['label'] ); ?>" data-cb-cm-subfield-label /></label>
				<label><span><?php esc_html_e( 'Field name', 'core-blueprint' ); ?></span><input type="text" class="code" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( (string) $sub_field['name'] ); ?>" <?php echo '' !== (string) $sub_field['id'] ? 'readonly' : ''; ?> /></label>
				<label><span><?php esc_html_e( 'Type', 'core-blueprint' ); ?></span><select name="<?php echo esc_attr( $prefix ); ?>[type]" data-cb-cm-subfield-type><?php foreach ( $atomic_groups as $group_label => $types ) : ?><optgroup label="<?php echo esc_attr( $group_label ); ?>"><?php foreach ( $types as $option_type => $label ) : ?><option value="<?php echo esc_attr( $option_type ); ?>" <?php selected( $type, $option_type ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
				<label class="cb-content-models-subfield__check"><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[required]" value="1" <?php checked( ! empty( $sub_field['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'core-blueprint' ); ?></label>
				<label><span><?php esc_html_e( 'Placeholder', 'core-blueprint' ); ?></span><input type="text" name="<?php echo esc_attr( $prefix ); ?>[placeholder]" value="<?php echo esc_attr( (string) $sub_field['placeholder'] ); ?>" /></label>
				<label><span><?php esc_html_e( 'Default', 'core-blueprint' ); ?></span><input type="text" name="<?php echo esc_attr( $prefix ); ?>[default_value]" value="<?php echo esc_attr( is_scalar( $sub_field['default_value'] ) ? (string) $sub_field['default_value'] : '' ); ?>" /></label>
			</div>
			<label class="cb-content-models-subfield__wide"><span><?php esc_html_e( 'Instructions', 'core-blueprint' ); ?></span><textarea name="<?php echo esc_attr( $prefix ); ?>[instructions]" rows="2"><?php echo esc_textarea( (string) $sub_field['instructions'] ); ?></textarea></label>
			<div data-cb-cm-subfield-choice-settings <?php echo in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ? '' : 'hidden'; ?>>
				<label class="cb-content-models-subfield__wide"><span><?php esc_html_e( 'Choices', 'core-blueprint' ); ?></span><textarea class="code" name="<?php echo esc_attr( $prefix ); ?>[choices_text]" rows="4"><?php echo esc_textarea( FieldTypes::choices_to_text( (array) $sub_field['choices'] ) ); ?></textarea></label>
			</div>
			<div class="cb-content-models-subfield__grid" data-cb-cm-subfield-number-settings <?php echo 'number' === $type ? '' : 'hidden'; ?>>
				<label><span><?php esc_html_e( 'Min', 'core-blueprint' ); ?></span><input type="number" step="any" name="<?php echo esc_attr( $prefix ); ?>[min]" value="<?php echo esc_attr( (string) $sub_field['min'] ); ?>" /></label>
				<label><span><?php esc_html_e( 'Max', 'core-blueprint' ); ?></span><input type="number" step="any" name="<?php echo esc_attr( $prefix ); ?>[max]" value="<?php echo esc_attr( (string) $sub_field['max'] ); ?>" /></label>
				<label><span><?php esc_html_e( 'Step', 'core-blueprint' ); ?></span><input type="number" step="any" name="<?php echo esc_attr( $prefix ); ?>[step]" value="<?php echo esc_attr( (string) $sub_field['step'] ); ?>" /></label>
			</div>
			<div data-cb-cm-subfield-relation-settings <?php echo FieldTypes::is_relation_type( $type ) ? '' : 'hidden'; ?>>
				<label class="cb-content-models-subfield__check"><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[relation_multiple]" value="1" <?php checked( ! empty( $sub_field['relation_multiple'] ) ); ?> /> <?php esc_html_e( 'Allow multiple selections', 'core-blueprint' ); ?></label>
				<div data-cb-cm-subfield-relation-target="post_relation" <?php echo 'post_relation' === $type ? '' : 'hidden'; ?>><?php echo ChoiceGroup::render( [ 'compact' => true, 'scrollable' => true, 'aria_label' => __( 'Allowed post types', 'core-blueprint' ), 'options' => $this->prefixed_choice_options( $this->relation_post_type_choices( (array) $sub_field['relation_post_types'] ), $prefix ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div data-cb-cm-subfield-relation-target="user_relation" <?php echo 'user_relation' === $type ? '' : 'hidden'; ?>><?php echo ChoiceGroup::render( [ 'compact' => true, 'scrollable' => true, 'aria_label' => __( 'Allowed user roles', 'core-blueprint' ), 'options' => $this->prefixed_choice_options( $this->relation_role_choices( (array) $sub_field['relation_roles'] ), $prefix ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div data-cb-cm-subfield-relation-target="term_relation" <?php echo 'term_relation' === $type ? '' : 'hidden'; ?>><?php echo ChoiceGroup::render( [ 'compact' => true, 'scrollable' => true, 'aria_label' => __( 'Allowed taxonomies', 'core-blueprint' ), 'options' => $this->prefixed_choice_options( $this->relation_taxonomy_choices( (array) $sub_field['relation_taxonomies'] ), $prefix ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
		</div>
		<?php
	}

	/** @param array<int,array<string,mixed>> $options @return array<int,array<string,mixed>> */

	private function prefixed_choice_options( array $options, string $prefix ): array {
		foreach ( $options as &$option ) {
			$name = (string) ( $option['name'] ?? '' );
			$option['name'] = $prefix . '[' . str_replace( '[]', '', $name ) . '][]';
		}
		unset( $option );
		return $options;
	}

	/** @param array<string,mixed> $group @param array<string,mixed> $field */

	private function render_conditional_logic( array $group, array $field ): void {
		$available = [];
		foreach ( (array) ( $group['fields'] ?? [] ) as $candidate_id => $candidate ) {
			if ( is_array( $candidate ) && (string) $candidate_id !== (string) ( $field['id'] ?? '' ) ) {
				$available[ (string) $candidate_id ] = (string) ( $candidate['label'] ?? $candidate_id );
			}
		}
		$logic = is_array( $field['conditional_logic'] ?? null ) ? $field['conditional_logic'] : [];
		?>
		<div class="cb-content-models-conditions" data-cb-cm-conditions>
			<p class="description"><?php esc_html_e( 'Rules inside a group use AND. Separate groups use OR. Conditional Logic controls editor visibility only; stored data is preserved when a field is hidden.', 'core-blueprint' ); ?></p>
			<div data-cb-cm-condition-groups>
				<?php foreach ( $logic as $group_index => $rules ) : if ( is_array( $rules ) ) : ?>
					<div class="cb-content-models-condition-group" data-cb-cm-condition-group>
						<div class="cb-content-models-condition-group__header"><strong><?php esc_html_e( 'Condition group', 'core-blueprint' ); ?></strong><button type="button" class="button-link-delete" data-cb-cm-remove-condition-group><?php esc_html_e( 'Remove group', 'core-blueprint' ); ?></button></div>
						<div data-cb-cm-condition-rules><?php foreach ( $rules as $rule_index => $rule ) : if ( is_array( $rule ) ) : $this->render_condition_rule( $available, $rule, $group_index, $rule_index ); endif; endforeach; ?></div>
						<button type="button" class="button" data-cb-cm-add-condition><?php esc_html_e( 'Add AND rule', 'core-blueprint' ); ?></button>
					</div>
				<?php endif; endforeach; ?>
			</div>
			<button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-cm-add-condition-group <?php echo empty( $available ) ? 'disabled' : ''; ?>><?php esc_html_e( 'Add condition group', 'core-blueprint' ); ?></button>
			<?php if ( empty( $available ) ) : ?><p class="description"><?php esc_html_e( 'Add another field to this Field Group before defining Conditional Logic.', 'core-blueprint' ); ?></p><?php endif; ?>
			<template data-cb-cm-condition-group-template><div class="cb-content-models-condition-group" data-cb-cm-condition-group><div class="cb-content-models-condition-group__header"><strong><?php esc_html_e( 'Condition group', 'core-blueprint' ); ?></strong><button type="button" class="button-link-delete" data-cb-cm-remove-condition-group><?php esc_html_e( 'Remove group', 'core-blueprint' ); ?></button></div><div data-cb-cm-condition-rules><?php $this->render_condition_rule( $available, [], '__GROUP__', '__RULE__' ); ?></div><button type="button" class="button" data-cb-cm-add-condition><?php esc_html_e( 'Add AND rule', 'core-blueprint' ); ?></button></div></template>
			<template data-cb-cm-condition-rule-template><?php $this->render_condition_rule( $available, [], '__GROUP__', '__RULE__' ); ?></template>
		</div>
		<?php
	}

	/** @param array<string,string> $available @param array<string,mixed> $rule @param int|string $group_index @param int|string $rule_index */

	private function render_condition_rule( array $available, array $rule, $group_index, $rule_index ): void {
		$prefix = 'conditional_logic[' . $group_index . '][' . $rule_index . ']';
		?>
		<div class="cb-content-models-condition-rule" data-cb-cm-condition-rule>
			<select name="<?php echo esc_attr( $prefix ); ?>[field]" aria-label="<?php esc_attr_e( 'Field', 'core-blueprint' ); ?>"><?php foreach ( $available as $candidate_id => $label ) : ?><option value="<?php echo esc_attr( $candidate_id ); ?>" <?php selected( (string) ( $rule['field'] ?? '' ), $candidate_id ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
			<select name="<?php echo esc_attr( $prefix ); ?>[operator]" data-cb-cm-condition-operator aria-label="<?php esc_attr_e( 'Operator', 'core-blueprint' ); ?>"><option value="equals" <?php selected( (string) ( $rule['operator'] ?? 'equals' ), 'equals' ); ?>><?php esc_html_e( 'equals', 'core-blueprint' ); ?></option><option value="not_equals" <?php selected( (string) ( $rule['operator'] ?? '' ), 'not_equals' ); ?>><?php esc_html_e( 'does not equal', 'core-blueprint' ); ?></option><option value="empty" <?php selected( (string) ( $rule['operator'] ?? '' ), 'empty' ); ?>><?php esc_html_e( 'is empty', 'core-blueprint' ); ?></option><option value="not_empty" <?php selected( (string) ( $rule['operator'] ?? '' ), 'not_empty' ); ?>><?php esc_html_e( 'is not empty', 'core-blueprint' ); ?></option></select>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[value]" value="<?php echo esc_attr( (string) ( $rule['value'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Value', 'core-blueprint' ); ?>" data-cb-cm-condition-value />
			<button type="button" class="button-link-delete" data-cb-cm-remove-condition aria-label="<?php esc_attr_e( 'Remove condition', 'core-blueprint' ); ?>">&times;</button>
		</div>
		<?php
	}


	/** @param array<string,mixed> $field */

}
