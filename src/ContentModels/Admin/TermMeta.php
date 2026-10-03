<?php
declare(strict_types=1);
/**
 * Native taxonomy-term field integration for Content Models.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Admin;

use CoreBlueprint\Core\ContentModels\FieldTypes;
use CoreBlueprint\Core\ContentModels\LocationMatcher;
use CoreBlueprint\Core\ContentModels\Repository;
use CoreBlueprint\Core\ContentModels\State;
use CoreBlueprint\Core\UI\Assets;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class TermMeta {
	public static function boot(): void {
		if ( ! State::is_enabled() ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'register_hooks' ], 20 );
		add_action( 'created_term', [ __CLASS__, 'save' ], 20, 3 );
		add_action( 'edited_term', [ __CLASS__, 'save' ], 20, 3 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function register_hooks(): void {
		foreach ( self::target_taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', [ __CLASS__, 'render_add' ] );
			add_action( $taxonomy . '_edit_form_fields', [ __CLASS__, 'render_edit' ], 10, 2 );
		}
	}

	public static function enqueue_assets(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		$taxonomy = sanitize_key( (string) ( $screen->taxonomy ?? '' ) );
		if ( '' === $taxonomy || ! in_array( $taxonomy, self::target_taxonomies(), true ) ) {
			return;
		}
		self::enqueue_field_assets( self::groups_for_taxonomy( $taxonomy ) );
	}

	public static function render_add( string $taxonomy ): void {
		$taxonomy = sanitize_key( $taxonomy );
		foreach ( self::groups_for_taxonomy( $taxonomy ) as $group_id => $group ) {
			echo '<div class="form-field cb-cm-term-group">';
			echo '<h3>' . esc_html( (string) ( $group['title'] ?? $group_id ) ) . '</h3>';
			if ( '' !== (string) ( $group['description'] ?? '' ) ) {
				echo '<p class="description">' . esc_html( (string) $group['description'] ) . '</p>';
			}
			wp_nonce_field( 'cb_cm_save_term_fields_' . $group_id, 'cb_cm_term_nonce_' . $group_id );
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) ) {
					MetaBoxes::render_stored_field( $field, $field['default_value'] ?? '', 'cb_cm_term_fields', 'cb-cm-term-new-', [
						'group_id' => (string) $group_id,
						'field_id' => (string) ( $field['id'] ?? '' ),
						'kind' => 'term',
						'taxonomy' => $taxonomy,
					] );
				}
			}
			echo '</div>';
		}
	}

	public static function render_edit( WP_Term $term, string $taxonomy ): void {
		$taxonomy = sanitize_key( $taxonomy );
		foreach ( self::groups_for_taxonomy( $taxonomy ) as $group_id => $group ) {
			echo '<tr class="form-field cb-cm-term-group"><th scope="row"><strong>' . esc_html( (string) ( $group['title'] ?? $group_id ) ) . '</strong></th><td>';
			if ( '' !== (string) ( $group['description'] ?? '' ) ) {
				echo '<p class="description">' . esc_html( (string) $group['description'] ) . '</p>';
			}
			wp_nonce_field( 'cb_cm_save_term_fields_' . $group_id, 'cb_cm_term_nonce_' . $group_id );
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				$stored = metadata_exists( 'term', $term->term_id, $name ) ? get_term_meta( $term->term_id, $name, true ) : ( $field['default_value'] ?? '' );
				MetaBoxes::render_stored_field( $field, $stored, 'cb_cm_term_fields', 'cb-cm-term-' . $term->term_id . '-', [
					'group_id' => (string) $group_id,
					'field_id' => (string) ( $field['id'] ?? '' ),
					'kind' => 'term',
					'term_id' => $term->term_id,
					'taxonomy' => $taxonomy,
				] );
			}
			echo '</td></tr>';
		}
	}

	public static function save( int $term_id, int $tt_id, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$taxonomy = sanitize_key( $taxonomy );
		if ( ! taxonomy_exists( $taxonomy ) || ! current_user_can( get_taxonomy( $taxonomy )->cap->edit_terms ) ) {
			return;
		}
		$submitted = isset( $_POST['cb_cm_term_fields'] ) && is_array( $_POST['cb_cm_term_fields'] ) ? wp_unslash( $_POST['cb_cm_term_fields'] ) : [];
		$present = isset( $_POST['cb_cm_term_fields_present'] ) && is_array( $_POST['cb_cm_term_fields_present'] ) ? wp_unslash( $_POST['cb_cm_term_fields_present'] ) : [];
		foreach ( self::groups_for_taxonomy( $taxonomy ) as $group_id => $group ) {
			$nonce_name = 'cb_cm_term_nonce_' . $group_id;
			$nonce = isset( $_POST[ $nonce_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ) : '';
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'cb_cm_save_term_fields_' . $group_id ) ) {
				continue;
			}
			self::save_group_values( $term_id, $group, $submitted, $present );
		}
	}

	/** @param array<string,mixed> $group @param array<string,mixed> $submitted @param array<string,mixed> $present */
	private static function save_group_values( int $term_id, array $group, array $submitted, array $present ): void {
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
			$raw = array_key_exists( $name, $submitted ) ? $submitted[ $name ] : FieldTypes::empty_input_value( $field );
			$value = FieldTypes::sanitize_value( $field, $raw );
			if ( ! MetaBoxes::is_valid_submission( $field, $raw, $value ) || ( ! empty( $field['required'] ) && FieldTypes::value_is_empty( $field, $value ) ) ) {
				continue;
			}
			if ( FieldTypes::value_is_empty( $field, $value ) && 'true_false' !== (string) ( $field['type'] ?? '' ) ) {
				delete_term_meta( $term_id, $name );
			} else {
				update_term_meta( $term_id, $name, $value );
			}
		}
	}

	/** @return string[] */
	private static function target_taxonomies(): array {
		$targets = [];
		foreach ( Repository::field_groups() as $group ) {
			foreach ( (array) ( $group['term_taxonomies'] ?? [] ) as $taxonomy ) {
				$taxonomy = sanitize_key( (string) $taxonomy );
				if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
					$targets[] = $taxonomy;
				}
			}
		}
		return array_values( array_unique( $targets ) );
	}

	/** @return array<string,array<string,mixed>> */
	private static function groups_for_taxonomy( string $taxonomy ): array {
		$groups = [];
		foreach ( Repository::field_groups() as $group_id => $group ) {
			if ( LocationMatcher::matches_taxonomy( $group, $taxonomy ) ) {
				$groups[ (string) $group_id ] = $group;
			}
		}
		return $groups;
	}

	/** @param array<string,array<string,mixed>> $groups */
	private static function enqueue_field_assets( array $groups ): void {
		$media = false;
		$relations = false;
		$runtime = false;
		foreach ( $groups as $group ) {
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$media = $media || FieldTypes::contains_media( $field );
				$relations = $relations || FieldTypes::contains_relations( $field );
				$runtime = $runtime || FieldTypes::is_structured_type( (string) ( $field['type'] ?? '' ) ) || ! empty( $field['conditional_logic'] );
			}
		}
		if ( $media ) {
			wp_enqueue_media();
			wp_enqueue_style( 'cb-core-content-model-media-fields', CB_CORE_URL . 'assets/css/components/content-model-media-fields-native.css', [], CB_CORE_VERSION );
			wp_enqueue_script( 'cb-core-content-model-media-fields', CB_CORE_URL . 'assets/js/features/content-model-media-fields.js', [ 'media-editor' ], CB_CORE_VERSION, true );
		}
		if ( $relations ) {
			Assets::enqueue_object_picker( Assets::OBJECT_PICKER_PRESENTATION_WP_NATIVE );
		}
		if ( $runtime ) {
			wp_enqueue_style( 'cb-core-content-model-structured-fields', CB_CORE_URL . 'assets/css/components/content-model-structured-fields-native.css', [], CB_CORE_VERSION );
			wp_enqueue_script( 'cb-core-content-model-fields', CB_CORE_URL . 'assets/js/features/content-model-fields.js', [], CB_CORE_VERSION, true );
		}
	}
}
