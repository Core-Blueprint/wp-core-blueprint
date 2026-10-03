<?php
declare(strict_types=1);
/**
 * Native user-profile field integration for Content Models.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Admin;

use CoreBlueprint\Core\ContentModels\FieldTypes;
use CoreBlueprint\Core\ContentModels\LocationMatcher;
use CoreBlueprint\Core\ContentModels\Repository;
use CoreBlueprint\Core\ContentModels\State;
use CoreBlueprint\Core\UI\Assets;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class UserMeta {
	public static function boot(): void {
		if ( ! State::is_enabled() ) {
			return;
		}
		add_action( 'show_user_profile', [ __CLASS__, 'render' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'render' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'save' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function enqueue_assets(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( (string) $screen->base, [ 'profile', 'user-edit' ], true ) ) {
			return;
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : get_current_user_id(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$groups = self::groups_for_user( $user );
		$media = false;
		$relations = false;
		$runtime = false;
		foreach ( $groups as $group ) {
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) ) {
					$media = $media || FieldTypes::contains_media( $field );
					$relations = $relations || FieldTypes::contains_relations( $field );
					$runtime = $runtime || FieldTypes::is_structured_type( (string) ( $field['type'] ?? '' ) ) || ! empty( $field['conditional_logic'] );
				}
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

	public static function render( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		foreach ( self::groups_for_user( $user ) as $group_id => $group ) {
			echo '<h2>' . esc_html( (string) ( $group['title'] ?? $group_id ) ) . '</h2>';
			if ( '' !== (string) ( $group['description'] ?? '' ) ) {
				echo '<p class="description">' . esc_html( (string) $group['description'] ) . '</p>';
			}
			wp_nonce_field( 'cb_cm_save_user_fields_' . $group_id . '_' . $user->ID, 'cb_cm_user_nonce_' . $group_id );
			echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Custom fields', 'core-blueprint' ) . '</th><td>';
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				$stored = metadata_exists( 'user', $user->ID, $name ) ? get_user_meta( $user->ID, $name, true ) : ( $field['default_value'] ?? '' );
				MetaBoxes::render_stored_field( $field, $stored, 'cb_cm_user_fields', 'cb-cm-user-' . $user->ID . '-', [
					'group_id' => (string) $group_id,
					'field_id' => (string) ( $field['id'] ?? '' ),
					'kind' => 'user',
					'user_id' => $user->ID,
				] );
			}
			echo '</td></tr></tbody></table>';
		}
	}

	public static function save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$submitted = isset( $_POST['cb_cm_user_fields'] ) && is_array( $_POST['cb_cm_user_fields'] ) ? wp_unslash( $_POST['cb_cm_user_fields'] ) : [];
		$present = isset( $_POST['cb_cm_user_fields_present'] ) && is_array( $_POST['cb_cm_user_fields_present'] ) ? wp_unslash( $_POST['cb_cm_user_fields_present'] ) : [];
		foreach ( self::groups_for_user( $user ) as $group_id => $group ) {
			$nonce_name = 'cb_cm_user_nonce_' . $group_id;
			$nonce = isset( $_POST[ $nonce_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ) : '';
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'cb_cm_save_user_fields_' . $group_id . '_' . $user_id ) ) {
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
				$raw = array_key_exists( $name, $submitted ) ? $submitted[ $name ] : FieldTypes::empty_input_value( $field );
				$value = FieldTypes::sanitize_value( $field, $raw );
				if ( ! MetaBoxes::is_valid_submission( $field, $raw, $value ) || ( ! empty( $field['required'] ) && FieldTypes::value_is_empty( $field, $value ) ) ) {
					continue;
				}
				if ( FieldTypes::value_is_empty( $field, $value ) && 'true_false' !== (string) ( $field['type'] ?? '' ) ) {
					delete_user_meta( $user_id, $name );
				} else {
					update_user_meta( $user_id, $name, $value );
				}
			}
		}
	}

	/** @return array<string,array<string,mixed>> */
	private static function groups_for_user( WP_User $user ): array {
		$groups = [];
		foreach ( Repository::field_groups() as $group_id => $group ) {
			if ( LocationMatcher::matches_user( $group, $user ) ) {
				$groups[ (string) $group_id ] = $group;
			}
		}
		return $groups;
	}
}
