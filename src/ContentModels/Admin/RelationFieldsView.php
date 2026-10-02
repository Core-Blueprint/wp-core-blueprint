<?php
declare(strict_types=1);
/** Content Models RelationFieldsView view module.
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

trait RelationFieldsView {

	private function render_relation_settings( array $field, bool $quick ): void {
		$type = (string) ( $field['type'] ?? 'text' );
		$is_relation = FieldTypes::is_relation_type( $type );
		$classes = 'cb-content-models-relation-settings' . ( $quick ? ' cb-content-models-relation-settings--quick' : '' );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-cb-cm-relation-settings <?php echo $is_relation ? '' : 'hidden'; ?>>
			<div class="cb-content-models-relation-card">
				<strong><?php esc_html_e( 'Cardinality', 'core-blueprint' ); ?></strong>
				<?php echo ChoiceGroup::render( [
					'type'       => ChoiceGroup::TYPE_RADIO,
					'compact'    => $quick,
					'aria_label' => __( 'Relation cardinality', 'core-blueprint' ),
					'options'    => [
						[ 'name' => 'relation_multiple', 'value' => '0', 'label' => __( 'Single selection', 'core-blueprint' ), 'checked' => empty( $field['relation_multiple'] ) ],
						[ 'name' => 'relation_multiple', 'value' => '1', 'label' => __( 'Multiple selection', 'core-blueprint' ), 'checked' => ! empty( $field['relation_multiple'] ) ],
					],
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output. ?>
			</div>

			<div class="cb-content-models-relation-card" data-cb-cm-relation-target="post_relation" <?php echo 'post_relation' === $type ? '' : 'hidden'; ?>>
				<strong><?php esc_html_e( 'Allowed post types', 'core-blueprint' ); ?></strong>
				<?php echo ChoiceGroup::render( [
					'compact'    => $quick,
					'scrollable' => true,
					'aria_label' => __( 'Allowed post types', 'core-blueprint' ),
					'options'    => $this->relation_post_type_choices( (array) ( $field['relation_post_types'] ?? [] ) ),
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output. ?>
				<p class="description"><?php esc_html_e( 'Search results are restricted to these WordPress post types. Values are stored as post IDs.', 'core-blueprint' ); ?></p>
			</div>

			<div class="cb-content-models-relation-card" data-cb-cm-relation-target="user_relation" <?php echo 'user_relation' === $type ? '' : 'hidden'; ?>>
				<strong><?php esc_html_e( 'Allowed user roles', 'core-blueprint' ); ?></strong>
				<?php echo ChoiceGroup::render( [
					'compact'    => $quick,
					'scrollable' => true,
					'aria_label' => __( 'Allowed user roles', 'core-blueprint' ),
					'options'    => $this->relation_role_choices( (array) ( $field['relation_roles'] ?? [] ) ),
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output. ?>
				<p class="description"><?php esc_html_e( 'Leave all roles unchecked to allow any WordPress user. Values are stored as user IDs.', 'core-blueprint' ); ?></p>
			</div>

			<div class="cb-content-models-relation-card" data-cb-cm-relation-target="term_relation" <?php echo 'term_relation' === $type ? '' : 'hidden'; ?>>
				<strong><?php esc_html_e( 'Allowed taxonomies', 'core-blueprint' ); ?></strong>
				<?php echo ChoiceGroup::render( [
					'compact'    => $quick,
					'scrollable' => true,
					'aria_label' => __( 'Allowed taxonomies', 'core-blueprint' ),
					'options'    => $this->relation_taxonomy_choices( (array) ( $field['relation_taxonomies'] ?? [] ) ),
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output. ?>
				<p class="description"><?php esc_html_e( 'Search results are restricted to these taxonomies. Values are stored as term IDs.', 'core-blueprint' ); ?></p>
			</div>
		</div>
		<?php
	}

	/** @param string[] $selected @return array<int,array<string,mixed>> */

	private function relation_post_type_choices( array $selected ): array {
		$objects = get_post_types( [ 'show_ui' => true ], 'objects' );
		uasort( $objects, static fn( $a, $b ): int => strnatcasecmp( (string) $a->labels->singular_name, (string) $b->labels->singular_name ) );
		$options = [];
		foreach ( $objects as $key => $object ) {
			$options[] = [
				'name'    => 'relation_post_types[]',
				'value'   => (string) $key,
				'label'   => sprintf( '%s (%s)', (string) $object->labels->singular_name, (string) $key ),
				'checked' => in_array( (string) $key, $selected, true ),
			];
		}
		foreach ( array_diff( $selected, array_map( 'strval', array_keys( $objects ) ) ) as $missing ) {
			/* translators: %s: unavailable post type key. */
			$options[] = [ 'name' => 'relation_post_types[]', 'value' => (string) $missing, 'label' => sprintf( __( 'Unavailable post type (%s)', 'core-blueprint' ), (string) $missing ), 'checked' => true ];
		}
		return $options;
	}

	/** @param string[] $selected @return array<int,array<string,mixed>> */

	private function relation_taxonomy_choices( array $selected ): array {
		$objects = get_taxonomies( [ 'show_ui' => true ], 'objects' );
		uasort( $objects, static fn( $a, $b ): int => strnatcasecmp( (string) $a->labels->singular_name, (string) $b->labels->singular_name ) );
		$options = [];
		foreach ( $objects as $key => $object ) {
			$options[] = [
				'name'    => 'relation_taxonomies[]',
				'value'   => (string) $key,
				'label'   => sprintf( '%s (%s)', (string) $object->labels->singular_name, (string) $key ),
				'checked' => in_array( (string) $key, $selected, true ),
			];
		}
		foreach ( array_diff( $selected, array_map( 'strval', array_keys( $objects ) ) ) as $missing ) {
			/* translators: %s: unavailable taxonomy key. */
			$options[] = [ 'name' => 'relation_taxonomies[]', 'value' => (string) $missing, 'label' => sprintf( __( 'Unavailable taxonomy (%s)', 'core-blueprint' ), (string) $missing ), 'checked' => true ];
		}
		return $options;
	}

	/** @param string[] $selected @return array<int,array<string,mixed>> */

	private function relation_role_choices( array $selected ): array {
		$wp_roles = wp_roles();
		$roles = is_object( $wp_roles ) ? (array) $wp_roles->roles : [];
		uasort( $roles, static fn( array $a, array $b ): int => strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ) );
		$options = [];
		foreach ( $roles as $key => $role ) {
			$options[] = [
				'name'    => 'relation_roles[]',
				'value'   => (string) $key,
				'label'   => sprintf( '%s (%s)', translate_user_role( (string) ( $role['name'] ?? $key ) ), (string) $key ),
				'checked' => in_array( (string) $key, $selected, true ),
			];
		}
		foreach ( array_diff( $selected, array_map( 'strval', array_keys( $roles ) ) ) as $missing ) {
			/* translators: %s: unavailable WordPress role key. */
			$options[] = [ 'name' => 'relation_roles[]', 'value' => (string) $missing, 'label' => sprintf( __( 'Unavailable role (%s)', 'core-blueprint' ), (string) $missing ), 'checked' => true ];
		}
		return $options;
	}

}
