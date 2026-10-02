<?php
declare(strict_types=1);
/**
 * Native WordPress post-editor integration for Core Blueprint fields.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels\Admin;

use CB\Core\ContentModels\FieldTypes;
use CB\Core\ContentModels\LocationMatcher;
use CB\Core\ContentModels\Repository;
use CB\Core\ContentModels\State;
use CB\Core\UI\Assets;
use CB\Core\UI\ObjectPicker;

use WP_Post;

defined( 'ABSPATH' ) || exit;

final class MetaBoxes {
	/** @var string[] */
	private static array $validation_errors = [];

	public static function boot(): void {
		if ( ! State::is_enabled() ) {
			return;
		}
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save' ], 20, 2 );
		add_filter( 'redirect_post_location', [ __CLASS__, 'redirect_location' ], 10, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'render_validation_notice' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}


	public static function enqueue_assets(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( (string) $screen->base, [ 'post', 'post-new' ], true ) ) {
			return;
		}
		$post_type = sanitize_key( (string) ( $screen->post_type ?? '' ) );
		if ( '' === $post_type ) {
			return;
		}

		$has_media = self::post_type_has_media_fields( $post_type );
		$has_relations = self::post_type_has_relation_fields( $post_type );
		$needs_runtime = self::post_type_needs_runtime_fields( $post_type );
		if ( ! $has_media && ! $has_relations && ! $needs_runtime ) {
			return;
		}

		if ( $has_media ) {
			wp_enqueue_media();
			wp_enqueue_style(
				'cb-core-content-model-media-fields',
				CB_CORE_URL . 'assets/css/components/content-model-media-fields-native.css',
				[],
				CB_CORE_VERSION
			);
			wp_enqueue_script(
				'cb-core-content-model-media-fields',
				CB_CORE_URL . 'assets/js/features/content-model-media-fields.js',
				[ 'media-editor' ],
				CB_CORE_VERSION,
				true
			);
		}
		if ( $has_relations ) {
			Assets::enqueue_object_picker( Assets::OBJECT_PICKER_PRESENTATION_WP_NATIVE );
		}
		if ( $needs_runtime ) {
			wp_enqueue_style( 'cb-core-content-model-structured-fields', CB_CORE_URL . 'assets/css/components/content-model-structured-fields-native.css', [], CB_CORE_VERSION );
			wp_enqueue_script( 'cb-core-content-model-fields', CB_CORE_URL . 'assets/js/features/content-model-fields.js', [], CB_CORE_VERSION, true );
		}
	}

	public static function register_boxes(): void {
		foreach ( Repository::field_groups() as $group_id => $group ) {
			foreach ( (array) ( $group['post_types'] ?? [] ) as $post_type ) {
				if ( ! is_string( $post_type ) || ! post_type_exists( $post_type ) ) {
					continue;
				}
				add_meta_box(
					'cb-cm-' . sanitize_html_class( (string) $group_id ),
					(string) ( $group['title'] ?? __( 'Custom Fields', 'core-blueprint' ) ),
					[ __CLASS__, 'render_box' ],
					$post_type,
					(string) ( $group['context'] ?? 'normal' ),
					(string) ( $group['priority'] ?? 'default' ),
					[ 'group_id' => (string) $group_id ]
				);
			}
		}
	}

	/** @param array<string,mixed> $box */
	public static function render_box( WP_Post $post, array $box ): void {
		$group_id = sanitize_key( (string) ( $box['args']['group_id'] ?? '' ) );
		$group = Repository::field_group( $group_id );
		if ( null === $group || ! LocationMatcher::matches_post_type( $group, $post->post_type ) ) {
			return;
		}

		wp_nonce_field( 'cb_cm_save_fields_' . $group_id, 'cb_cm_nonce_' . $group_id );
		if ( '' !== (string) ( $group['description'] ?? '' ) ) {
			echo '<p class="description">' . esc_html( (string) $group['description'] ) . '</p>';
		}

		$fields = is_array( $group['fields'] ?? null ) ? $group['fields'] : [];
		if ( empty( $fields ) ) {
			echo '<p>' . esc_html__( 'This field group does not contain any fields yet.', 'core-blueprint' ) . '</p>';
			return;
		}

		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				self::render_field( $post, $field, (string) $group_id );
			}
		}
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$submitted = isset( $_POST['cb_cm_fields'] ) && is_array( $_POST['cb_cm_fields'] ) ? wp_unslash( $_POST['cb_cm_fields'] ) : [];
		$present   = isset( $_POST['cb_cm_fields_present'] ) && is_array( $_POST['cb_cm_fields_present'] ) ? wp_unslash( $_POST['cb_cm_fields_present'] ) : ( isset( $_POST['cb_cm_present'] ) && is_array( $_POST['cb_cm_present'] ) ? wp_unslash( $_POST['cb_cm_present'] ) : [] );

		foreach ( Repository::field_groups() as $group_id => $group ) {
			if ( ! LocationMatcher::matches_post_type( $group, $post->post_type ) ) {
				continue;
			}
			$nonce_name = 'cb_cm_nonce_' . $group_id;
			$nonce = isset( $_POST[ $nonce_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ) : '';
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'cb_cm_save_fields_' . $group_id ) ) {
				continue;
			}

			$fields = (array) ( $group['fields'] ?? [] );
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				if ( '' === $name || ! isset( $present[ $name ] ) ) {
					continue;
				}
				if ( ! FieldTypes::conditional_is_active( $field, $fields, $submitted ) ) {
					continue;
				}

				$type = (string) ( $field['type'] ?? '' );
				$raw = array_key_exists( $name, $submitted ) ? $submitted[ $name ] : FieldTypes::empty_input_value( $field );
				$value = FieldTypes::sanitize_value( $field, $raw );

				if ( ! self::is_valid_submission( $field, $raw, $value ) ) {
					/* translators: %s: field label. */
			self::$validation_errors[] = sprintf( __( '%s contains an invalid value and was not changed.', 'core-blueprint' ), (string) ( $field['label'] ?? $name ) );
					continue;
				}
				if ( ! empty( $field['required'] ) && self::is_empty_field_value( $field, $value ) ) {
					/* translators: %s: field label. */
			self::$validation_errors[] = sprintf( __( '%s is required and was not changed.', 'core-blueprint' ), (string) ( $field['label'] ?? $name ) );
					continue;
				}

				if ( self::is_empty_field_value( $field, $value ) && 'true_false' !== $type ) {
					delete_post_meta( $post_id, $name );
				} else {
					update_post_meta( $post_id, $name, $value );
				}
			}
		}
	}

	public static function redirect_location( string $location, int $post_id ): string {
		if ( empty( self::$validation_errors ) || get_current_user_id() <= 0 ) {
			return $location;
		}
		set_transient(
			'cb_cm_validation_' . get_current_user_id() . '_' . $post_id,
			array_values( array_unique( self::$validation_errors ) ),
			MINUTE_IN_SECONDS
		);
		return add_query_arg( 'cb_cm_fields_error', '1', $location );
	}

	public static function render_validation_notice(): void {
		if ( empty( $_GET['cb_cm_fields_error'] ) || empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = 'cb_cm_validation_' . get_current_user_id() . '_' . $post_id;
		$errors = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $errors ) || empty( $errors ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Core Blueprint fields need attention:', 'core-blueprint' ) . '</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( (string) $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	private static function render_field( WP_Post $post, array $field, string $group_id ): void {
		$name = (string) ( $field['name'] ?? '' );
		$stored = metadata_exists( 'post', $post->ID, $name ) ? get_post_meta( $post->ID, $name, true ) : ( $field['default_value'] ?? '' );
		self::render_stored_field( $field, $stored, 'cb_cm_fields', 'cb-cm-field-', [
			'group_id'  => $group_id,
			'field_id'  => (string) ( $field['id'] ?? '' ),
			'kind'      => 'post',
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
		] );
	}

	/** @param mixed $stored */
	public static function render_stored_field( array $field, $stored, string $input_root = 'cb_cm_fields', string $id_prefix = 'cb-cm-field-', array $picker_context = [], bool $include_present = true ): void {
		$name  = (string) ( $field['name'] ?? '' );
		$label = (string) ( $field['label'] ?? $name );
		$type  = (string) ( $field['type'] ?? 'text' );
		$id = $id_prefix . sanitize_html_class( (string) ( $field['id'] ?? $name ) );
		$conditional = is_array( $field['conditional_logic'] ?? null ) ? $field['conditional_logic'] : [];
		$conditional_json = ! empty( $conditional ) ? wp_json_encode( $conditional ) : '';

		echo '<div class="cb-cm-field" data-cb-cm-runtime-field data-field-id="' . esc_attr( (string) ( $field['id'] ?? '' ) ) . '" data-field-type="' . esc_attr( $type ) . '"';
		if ( is_string( $conditional_json ) && '' !== $conditional_json ) {
			echo ' data-conditional="' . esc_attr( $conditional_json ) . '"';
		}
		echo ' style="margin:0 0 18px;">';
		if ( $include_present ) {
			echo '<input type="hidden" name="' . esc_attr( $input_root ) . '_present[' . esc_attr( $name ) . ']" value="1" />';
		}
		$field_label = '<strong>' . esc_html( $label ) . ( ! empty( $field['required'] ) ? ' <span aria-hidden="true">*</span>' : '' ) . '</strong>';
		if ( in_array( $type, [ 'image', 'file', 'gallery', 'group', 'repeater' ], true ) ) {
			echo '<div class="cb-cm-field-label">' . $field_label . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<label for="' . esc_attr( $id ) . '">' . $field_label . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( '' !== (string) ( $field['instructions'] ?? '' ) ) {
			echo '<p class="description" style="margin:4px 0 7px;">' . esc_html( (string) $field['instructions'] ) . '</p>';
		}

		$input_name = $input_root . '[' . $name . ']';
		switch ( $type ) {
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" class="widefat" rows="' . esc_attr( (string) ( $field['rows'] ?? 5 ) ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '">' . esc_textarea( (string) $stored ) . '</textarea>';
				break;
			case 'wysiwyg':
				if ( ! empty( $picker_context['structured_repeater'] ) ) {
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" class="widefat" rows="' . esc_attr( (string) max( 4, (int) ( $field['rows'] ?? 8 ) ) ) . '">' . esc_textarea( (string) $stored ) . '</textarea>';
				} else {
					wp_editor( (string) $stored, $id, [ 'textarea_name' => $input_name, 'textarea_rows' => max( 4, (int) ( $field['rows'] ?? 8 ) ), 'media_buttons' => true, 'teeny' => false ] );
				}
				break;
			case 'number':
				echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" type="number" class="regular-text" value="' . esc_attr( (string) $stored ) . '"';
				foreach ( [ 'min', 'max', 'step' ] as $attribute ) { if ( '' !== (string) ( $field[ $attribute ] ?? '' ) ) { echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( (string) $field[ $attribute ] ) . '"'; } }
				echo ' />';
				break;
			case 'email':
			case 'url':
			case 'date':
			case 'time':
			case 'color':
				echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" type="' . esc_attr( $type ) . '" class="regular-text" value="' . esc_attr( (string) $stored ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '" />';
				break;
			case 'datetime':
				echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" type="datetime-local" class="regular-text" value="' . esc_attr( (string) $stored ) . '" />';
				break;
			case 'true_false':
				echo '<input type="hidden" name="' . esc_attr( $input_name ) . '" value="0" /><label style="display:block;margin-top:7px;"><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" type="checkbox" value="1" ' . checked( ! empty( $stored ), true, false ) . ' /> ' . esc_html__( 'Enabled', 'core-blueprint' ) . '</label>';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" class="regular-text"><option value="">' . esc_html__( '— Select —', 'core-blueprint' ) . '</option>';
				foreach ( (array) ( $field['choices'] ?? [] ) as $value => $choice_label ) { echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( (string) $stored, (string) $value, false ) . '>' . esc_html( (string) $choice_label ) . '</option>'; }
				echo '</select>';
				break;
			case 'radio':
				foreach ( (array) ( $field['choices'] ?? [] ) as $value => $choice_label ) { echo '<label style="display:block;margin:7px 0;"><input name="' . esc_attr( $input_name ) . '" type="radio" value="' . esc_attr( (string) $value ) . '" ' . checked( (string) $stored, (string) $value, false ) . ' /> ' . esc_html( (string) $choice_label ) . '</label>'; }
				break;
			case 'checkbox':
				$selected = is_array( $stored ) ? $stored : [];
				foreach ( (array) ( $field['choices'] ?? [] ) as $value => $choice_label ) { echo '<label style="display:block;margin:7px 0;"><input name="' . esc_attr( $input_name ) . '[]" type="checkbox" value="' . esc_attr( (string) $value ) . '" ' . checked( in_array( (string) $value, array_map( 'strval', $selected ), true ), true, false ) . ' /> ' . esc_html( (string) $choice_label ) . '</label>'; }
				break;
			case 'group':
			case 'repeater': self::render_structured_field( $field, $stored, $id, $input_name, $picker_context ); break;
			case 'post_relation':
			case 'user_relation':
			case 'term_relation': self::render_relation_field( $field, $stored, $id, $input_name, $picker_context ); break;
			case 'image': self::render_media_field( $id, $input_name, absint( $stored ), true ); break;
			case 'file': self::render_media_field( $id, $input_name, absint( $stored ), false ); break;
			case 'gallery': self::render_gallery_field( $id, $input_name, is_array( $stored ) ? $stored : [] ); break;
			case 'text':
			default:
				echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" type="text" class="regular-text" value="' . esc_attr( (string) $stored ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '" />';
				break;
		}
		echo '</div>';
	}


	/** @param mixed $stored @param array<string,mixed> $picker_context */
	private static function render_structured_field( array $field, $stored, string $id, string $input_name, array $picker_context ): void {
		$type = (string) ( $field['type'] ?? '' );
		$sub_fields = array_values( FieldTypes::sub_fields( $field ) );
		if ( empty( $sub_fields ) ) {
			echo '<p class="description">' . esc_html__( 'This structured field does not contain any subfields yet.', 'core-blueprint' ) . '</p>';
			return;
		}
		if ( 'group' === $type ) {
			$row = is_array( $stored ) ? $stored : [];
			echo '<fieldset id="' . esc_attr( $id ) . '" class="cb-cm-structured-field cb-cm-group-field">';
			self::render_structured_row( $sub_fields, $row, $input_name, $id . '-group-', $picker_context, false, 0 );
			echo '</fieldset>';
			return;
		}

		$rows = is_array( $stored ) ? array_values( $stored ) : [];
		$minimum = max( 0, (int) ( $field['repeater_min'] ?? 0 ) );
		$maximum = max( 0, (int) ( $field['repeater_max'] ?? 0 ) );
		while ( count( $rows ) < $minimum ) {
			$rows[] = [];
		}
		/* translators: %d: row number placeholder replaced in the repeater interface. */
		echo '<div id="' . esc_attr( $id ) . '" class="cb-cm-structured-field cb-cm-repeater-field" data-cb-cm-repeater data-min="' . esc_attr( (string) $minimum ) . '" data-max="' . esc_attr( (string) $maximum ) . '" data-row-label-template="' . esc_attr__( 'Row %d', 'core-blueprint' ) . '">';
		echo '<div class="cb-cm-repeater-rows" data-cb-cm-repeater-rows>';
		foreach ( $rows as $index => $row ) {
			self::render_structured_row( $sub_fields, is_array( $row ) ? $row : [], $input_name . '[' . $index . ']', $id . '-row-' . $index . '-', $picker_context, true, $index );
		}
		echo '</div><button type="button" class="button" data-cb-cm-repeater-add>' . esc_html__( 'Add row', 'core-blueprint' ) . '</button>';
		if ( $maximum > 0 ) {
			/* translators: %d: maximum number of repeater rows. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Maximum %d rows.', 'core-blueprint' ), $maximum ) ) . '</p>';
		}
		echo '<template data-cb-cm-repeater-template>';
		self::render_structured_row( $sub_fields, [], $input_name . '[__INDEX__]', $id . '-row-__INDEX__-', $picker_context, true, -1 );
		echo '</template></div>';
	}

	/** @param array<int,array<string,mixed>> $sub_fields @param array<string,mixed> $row @param array<string,mixed> $picker_context */
	private static function render_structured_row( array $sub_fields, array $row, string $row_input_root, string $id_prefix, array $picker_context, bool $repeater, int $index ): void {
		$row_id = sanitize_key( (string) ( $row['_cb_row_id'] ?? '' ) );
		if ( $repeater && '' === $row_id && $index >= 0 ) {
			$row_id = 'row_' . str_replace( '-', '', wp_generate_uuid4() );
		}
		echo '<div class="cb-cm-structured-row' . ( $repeater ? ' cb-cm-repeater-row' : '' ) . '"' . ( $repeater ? ' draggable="true" data-cb-cm-repeater-row' : '' ) . '>';
		if ( $repeater ) {
			/* translators: %d: one-based repeater row number. */
		echo '<div class="cb-cm-repeater-row-header"><span class="cb-cm-repeater-handle" data-cb-cm-repeater-handle aria-hidden="true">↕</span><strong data-cb-cm-repeater-row-label>' . esc_html( sprintf( __( 'Row %d', 'core-blueprint' ), max( 1, $index + 1 ) ) ) . '</strong><button type="button" class="button-link-delete" data-cb-cm-repeater-remove>' . esc_html__( 'Remove', 'core-blueprint' ) . '</button></div>';
			echo '<input type="hidden" name="' . esc_attr( $row_input_root . '[_cb_row_id]' ) . '" value="' . esc_attr( $row_id ) . '" data-cb-cm-row-id />';
		}
		echo '<div class="cb-cm-structured-row-fields">';
		foreach ( $sub_fields as $sub_field ) {
			$name = sanitize_key( (string) ( $sub_field['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$value = array_key_exists( $name, $row ) ? $row[ $name ] : ( $sub_field['default_value'] ?? FieldTypes::empty_input_value( $sub_field ) );
			$context = array_merge( $picker_context, [
				'structured_subfield' => true,
				'structured_repeater' => $repeater,
				'sub_field_id' => (string) ( $sub_field['id'] ?? '' ),
			] );
			self::render_stored_field( $sub_field, $value, $row_input_root, $id_prefix, $context, false );
		}
		echo '</div></div>';
	}


	/** @param mixed $stored @param array<string,mixed> $context */
	private static function render_relation_field( array $field, $stored, string $id, string $input_name, array $context ): void {
		$group_id = sanitize_key( (string) ( $context['group_id'] ?? '' ) );
		$field_id = sanitize_key( (string) ( $context['field_id'] ?? $field['id'] ?? '' ) );
		if ( '' === $group_id || '' === $field_id ) {
			echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" type="text" class="regular-text code" value="' . esc_attr( implode( ',', FieldTypes::relation_raw_ids( $stored ) ) ) . '" />';
			return;
		}

		$type = (string) ( $field['type'] ?? '' );
		$placeholder = match ( $type ) {
			'user_relation' => __( 'Search users…', 'core-blueprint' ),
			'term_relation' => __( 'Search terms…', 'core-blueprint' ),
			default         => __( 'Search posts…', 'core-blueprint' ),
		};
		echo ObjectPicker::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes its own output.
			'id'          => $id,
			'name'        => $input_name,
			'multiple'    => ! empty( $field['relation_multiple'] ),
			'action'      => 'cb_core_content_models_search_relation_objects',
			'nonce'       => wp_create_nonce( 'cb_cm_relation_search_' . $group_id . '_' . $field_id ),
			'context'     => array_merge( $context, [ 'group_id' => $group_id, 'field_id' => $field_id ] ),
			'selected'    => FieldTypes::relation_selected_items( $field, $stored ),
			'placeholder' => $placeholder,
		] );
	}


	private static function render_media_field( string $id, string $input_name, int $attachment_id, bool $image_only ): void {
		$is_valid = $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) && ( ! $image_only || wp_attachment_is_image( $attachment_id ) );
		if ( ! $is_valid ) {
			$attachment_id = 0;
		}
		$select_label = $image_only ? __( 'Choose image', 'core-blueprint' ) : __( 'Choose file', 'core-blueprint' );
		$replace_label = $image_only ? __( 'Replace image', 'core-blueprint' ) : __( 'Replace file', 'core-blueprint' );
		$frame_title = $image_only ? __( 'Choose an image', 'core-blueprint' ) : __( 'Choose a file', 'core-blueprint' );

		echo '<div class="cb-cm-media-field" data-cb-cm-media-field data-media-kind="' . esc_attr( $image_only ? 'image' : 'file' ) . '" data-frame-title="' . esc_attr( $frame_title ) . '" data-select-label="' . esc_attr( $select_label ) . '" data-replace-label="' . esc_attr( $replace_label ) . '">';
		echo '<input id="' . esc_attr( $id ) . '" type="hidden" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' ) . '" data-cb-cm-media-value />';
		echo '<div class="cb-cm-media-preview" data-cb-cm-media-preview>';
		if ( $attachment_id > 0 ) {
			self::render_attachment_preview( $attachment_id, $image_only );
		}
		echo '</div>';
		echo '<div class="cb-cm-media-actions">';
		echo '<button type="button" class="button" data-cb-cm-media-select>' . esc_html( $attachment_id > 0 ? $replace_label : $select_label ) . '</button> ';
		echo '<button type="button" class="button-link-delete" data-cb-cm-media-remove' . ( $attachment_id > 0 ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'core-blueprint' ) . '</button>';
		echo '</div></div>';
	}

	/** @param array<int,mixed> $attachment_ids */
	private static function render_gallery_field( string $id, string $input_name, array $attachment_ids ): void {
		$ids = [];
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) && wp_attachment_is_image( $attachment_id ) && ! in_array( $attachment_id, $ids, true ) ) {
				$ids[] = $attachment_id;
			}
		}

		echo '<div id="' . esc_attr( $id ) . '" class="cb-cm-gallery-field" data-cb-cm-gallery-field data-frame-title="' . esc_attr__( 'Choose gallery images', 'core-blueprint' ) . '" data-select-label="' . esc_attr__( 'Use selected images', 'core-blueprint' ) . '" data-remove-label="' . esc_attr__( 'Remove image', 'core-blueprint' ) . '" data-input-name="' . esc_attr( $input_name . '[]' ) . '">';
		echo '<div class="cb-cm-gallery-items" data-cb-cm-gallery-items>';
		foreach ( $ids as $attachment_id ) {
			self::render_gallery_item( $input_name, $attachment_id );
		}
		echo '</div>';
		echo '<button type="button" class="button" data-cb-cm-gallery-select>' . esc_html__( 'Choose images', 'core-blueprint' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Drag selected images to change gallery order. The stored value is an ordered array of WordPress attachment IDs.', 'core-blueprint' ) . '</p>';
		echo '</div>';
	}

	private static function render_attachment_preview( int $attachment_id, bool $image_only ): void {
		if ( $image_only ) {
			echo wp_get_attachment_image( $attachment_id, 'thumbnail', false, [ 'class' => 'cb-cm-media-thumbnail' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated attachment image markup.
			return;
		}
		$file = get_attached_file( $attachment_id );
		$label = is_string( $file ) && '' !== $file ? wp_basename( $file ) : get_the_title( $attachment_id );
		$icon = wp_mime_type_icon( $attachment_id );
		echo '<div class="cb-cm-file-preview">';
		if ( is_string( $icon ) && '' !== $icon ) {
			echo '<img src="' . esc_url( $icon ) . '" alt="" />';
		}
		echo '<span>' . esc_html( (string) $label ) . '</span></div>';
	}

	private static function render_gallery_item( string $input_name, int $attachment_id ): void {
		echo '<div class="cb-cm-gallery-item" draggable="true" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $input_name ) . '[]" value="' . esc_attr( (string) $attachment_id ) . '" />';
		echo wp_get_attachment_image( $attachment_id, 'thumbnail', false, [ 'class' => 'cb-cm-gallery-thumbnail' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated attachment image markup.
		echo '<button type="button" class="button-link-delete cb-cm-gallery-remove" data-cb-cm-gallery-remove aria-label="' . esc_attr__( 'Remove image', 'core-blueprint' ) . '">&times;</button>';
		echo '</div>';
	}

	private static function post_type_has_media_fields( string $post_type ): bool {
		foreach ( Repository::field_groups() as $group ) {
			if ( ! in_array( $post_type, (array) ( $group['post_types'] ?? [] ), true ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && FieldTypes::contains_media( $field ) ) {
					return true;
				}
			}
		}
		return false;
	}


	private static function post_type_has_relation_fields( string $post_type ): bool {
		foreach ( Repository::field_groups() as $group ) {
			if ( ! in_array( $post_type, (array) ( $group['post_types'] ?? [] ), true ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && FieldTypes::contains_relations( $field ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function post_type_needs_runtime_fields( string $post_type ): bool {
		foreach ( Repository::field_groups() as $group ) {
			if ( ! in_array( $post_type, (array) ( $group['post_types'] ?? [] ), true ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && ( FieldTypes::is_structured_type( (string) ( $field['type'] ?? '' ) ) || ! empty( $field['conditional_logic'] ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function is_empty_field_value( array $field, $value ): bool {
		return FieldTypes::value_is_empty( $field, $value );
	}

	/** @param mixed $raw @param mixed $sanitized */
	public static function is_valid_submission( array $field, $raw, $sanitized ): bool {
		$type = (string) ( $field['type'] ?? 'text' );
		if ( FieldTypes::is_structured_type( $type ) ) {
			if ( ! is_array( $raw ) || ! is_array( $sanitized ) ) {
				return false;
			}
			$sanitized_rows = 'group' === $type ? [ $sanitized ] : array_values( $sanitized );
			if ( 'repeater' === $type ) {
				$minimum = max( 0, (int) ( $field['repeater_min'] ?? 0 ) );
				$maximum = max( 0, (int) ( $field['repeater_max'] ?? 0 ) );
				if ( count( $sanitized_rows ) < $minimum || ( $maximum > 0 && count( $sanitized_rows ) > $maximum ) ) {
					return false;
				}
			}
			$raw_rows = 'group' === $type ? [ $raw ] : array_values( $raw );
			foreach ( $raw_rows as $raw_row ) {
				if ( ! is_array( $raw_row ) ) {
					return false;
				}
				$validated = [];
				$row_has_value = false;
				foreach ( FieldTypes::sub_fields( $field ) as $sub_field ) {
					$name = (string) ( $sub_field['name'] ?? '' );
					if ( '' === $name ) {
						continue;
					}
					$sub_raw = array_key_exists( $name, $raw_row ) ? $raw_row[ $name ] : FieldTypes::empty_input_value( $sub_field );
					$sub_value = FieldTypes::sanitize_value( $sub_field, $sub_raw );
					if ( ! self::is_valid_submission( $sub_field, $sub_raw, $sub_value ) ) {
						return false;
					}
					$validated[] = [ $sub_field, $sub_value ];
					$row_has_value = $row_has_value || ! FieldTypes::value_is_empty( $sub_field, $sub_value );
				}
				if ( 'repeater' === $type && ! $row_has_value ) {
					continue;
				}
				foreach ( $validated as [ $sub_field, $sub_value ] ) {
					if ( ! empty( $sub_field['required'] ) && FieldTypes::value_is_empty( $sub_field, $sub_value ) ) {
						return false;
					}
				}
			}
			return true;
		}
		if ( self::is_empty_value( $raw ) ) {
			return true;
		}
		if ( in_array( $type, [ 'email', 'url', 'date', 'time', 'datetime', 'color', 'select', 'radio', 'image', 'file' ], true ) && self::is_empty_field_value( $field, $sanitized ) ) {
			return false;
		}
		if ( FieldTypes::is_relation_type( $type ) ) {
			$raw_ids = FieldTypes::relation_raw_ids( $raw );
			$sanitized_ids = FieldTypes::relation_raw_ids( $sanitized );
			if ( empty( $field['relation_multiple'] ) ) {
				return 1 === count( $raw_ids ) && 1 === count( $sanitized_ids ) && $raw_ids[0] === $sanitized_ids[0];
			}
			return $raw_ids === $sanitized_ids;
		}
		if ( 'number' === $type && ! is_numeric( $raw ) ) {
			return false;
		}
		return true;
	}

	/** @param mixed $value */
	private static function is_empty_value( $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}
}
