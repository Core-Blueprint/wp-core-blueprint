<?php
declare(strict_types=1);
/**
 * Runtime admin pages for Content Models Option Pages.
 *
 * Definitions are governed by Content Models. Values are stored as individual
 * native WordPress options using deterministic page + field keys.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels\Admin;

use CB\Core\UI\Assets;
use CB\Core\UI\Icon;
use CB\Core\ContentModels\FieldTypes;
use CB\Core\ContentModels\LocationMatcher;
use CB\Core\ContentModels\Repository;
use CB\Core\ContentModels\State;
use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class OptionPages {
	/** @var array<string,string> hook suffix => page slug */
	private static array $hooks = [];

	public static function boot(): void {
		if ( ! State::is_enabled() ) {
			return;
		}
		add_action( 'admin_menu', [ __CLASS__, 'register_menus' ], 30 );
		add_action( 'admin_post_cb_core_content_models_save_option_values', [ __CLASS__, 'save_values' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function register_menus(): void {
		$pages = Repository::option_pages();
		if ( empty( $pages ) ) {
			return;
		}

		// Register top-level pages first so another CB Option Page may safely use
		// one of them as its submenu parent in the same admin_menu pass.
		foreach ( $pages as $slug => $page ) {
			if ( '' !== (string) ( $page['parent_slug'] ?? '' ) ) {
				continue;
			}
			self::register_page( (string) $slug, $page );
		}
		foreach ( $pages as $slug => $page ) {
			if ( '' === (string) ( $page['parent_slug'] ?? '' ) ) {
				continue;
			}
			self::register_page( (string) $slug, $page );
		}
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		$page_slug = self::$hooks[ $hook_suffix ] ?? '';
		if ( '' === $page_slug ) {
			return;
		}
		$has_media = self::page_has_media_fields( $page_slug );
		$has_relations = self::page_has_relation_fields( $page_slug );
		$needs_runtime = self::page_needs_runtime_fields( $page_slug );
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

	public static function render_current(): void {
		$page_slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = Repository::option_page( $page_slug );
		if ( null === $page ) {
			wp_die( esc_html__( 'Option Page definition not found.', 'core-blueprint' ) );
		}
		$capability = (string) ( $page['capability'] ?? 'manage_options' );
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this Option Page.', 'core-blueprint' ), esc_html__( 'Forbidden', 'core-blueprint' ), [ 'response' => 403 ] );
		}

		$groups = self::groups_for_page( $page_slug );
		?>
		<div class="wrap cb-cm-option-page">
			<h1><?php echo esc_html( (string) ( $page['title'] ?? $page_slug ) ); ?></h1>
			<?php if ( '' !== (string) ( $page['description'] ?? '' ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $page['description'] ); ?></p>
			<?php endif; ?>
			<?php self::render_notice( $page_slug ); ?>
			<?php if ( empty( $groups ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No Field Groups are assigned to this Option Page yet.', 'core-blueprint' ); ?></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cb_core_content_models_save_option_values" />
					<input type="hidden" name="option_page" value="<?php echo esc_attr( $page_slug ); ?>" />
					<?php wp_nonce_field( 'cb_cm_save_option_page_' . $page_slug ); ?>
					<?php foreach ( $groups as $group_id => $group ) : ?>
						<div class="postbox" style="margin-top:16px;max-width:1100px;">
							<div class="postbox-header"><h2 class="hndle"><span><?php echo esc_html( (string) ( $group['title'] ?? $group_id ) ); ?></span></h2></div>
							<div class="inside">
								<?php if ( '' !== (string) ( $group['description'] ?? '' ) ) : ?><p class="description"><?php echo esc_html( (string) $group['description'] ); ?></p><?php endif; ?>
								<?php foreach ( (array) ( $group['fields'] ?? [] ) as $field ) : ?>
									<?php if ( is_array( $field ) ) :
										$name = (string) ( $field['name'] ?? '' );
										$option_key = Repository::option_value_key( $page_slug, $name );
										$stored = get_option( $option_key, $field['default_value'] ?? '' );
										MetaBoxes::render_stored_field( $field, $stored, 'cb_cm_option_fields', 'cb-cm-option-' . $page_slug . '-', [ 'group_id' => (string) $group_id, 'field_id' => (string) ( $field['id'] ?? '' ), 'kind' => 'option_page', 'page_slug' => $page_slug ] );
									endif; ?>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
					<?php submit_button( __( 'Save Options', 'core-blueprint' ) ); ?>
				</form>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Values are stored through the native WordPress Options API. Deleting the Option Page definition does not delete stored values.', 'core-blueprint' ); ?></p>
		</div>
		<?php
	}

	public static function save_values(): void {
		if ( ! State::is_enabled() ) {
			wp_die( esc_html__( 'Content Models is disabled.', 'core-blueprint' ) );
		}
		$page_slug = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
		$page = Repository::option_page( $page_slug );
		if ( null === $page ) {
			wp_die( esc_html__( 'Option Page definition not found.', 'core-blueprint' ) );
		}
		$capability = (string) ( $page['capability'] ?? 'manage_options' );
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this Option Page.', 'core-blueprint' ), esc_html__( 'Forbidden', 'core-blueprint' ), [ 'response' => 403 ] );
		}
		check_admin_referer( 'cb_cm_save_option_page_' . $page_slug );

		$submitted = isset( $_POST['cb_cm_option_fields'] ) && is_array( $_POST['cb_cm_option_fields'] ) ? wp_unslash( $_POST['cb_cm_option_fields'] ) : [];
		$present = isset( $_POST['cb_cm_option_fields_present'] ) && is_array( $_POST['cb_cm_option_fields_present'] ) ? wp_unslash( $_POST['cb_cm_option_fields_present'] ) : [];
		$errors = [];
		$changed = [];

		foreach ( self::groups_for_page( $page_slug ) as $group ) {
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

				if ( ! MetaBoxes::is_valid_submission( $field, $raw, $value ) ) {
					/* translators: %s: field label. */
			$errors[] = sprintf( __( '%s contains an invalid value and was not changed.', 'core-blueprint' ), (string) ( $field['label'] ?? $name ) );
					continue;
				}
				if ( ! empty( $field['required'] ) && MetaBoxes::is_empty_field_value( $field, $value ) ) {
					/* translators: %s: field label. */
			$errors[] = sprintf( __( '%s is required and was not changed.', 'core-blueprint' ), (string) ( $field['label'] ?? $name ) );
					continue;
				}

				$option_key = Repository::option_value_key( $page_slug, $name );
				$before = get_option( $option_key, '__cb_cm_option_missing__' );
				if ( MetaBoxes::is_empty_field_value( $field, $value ) && 'true_false' !== $type ) {
					delete_option( $option_key );
					$after = '__cb_cm_option_missing__';
				} else {
					update_option( $option_key, $value, false );
					$after = get_option( $option_key, '__cb_cm_option_missing__' );
				}
				if ( $before !== $after ) {
					$changed[] = $name;
				}
			}
		}

		if ( ! empty( $changed ) && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'content_models_option_values_updated', 'notice', [
				'option_page'   => $page_slug,
				'changed_fields'=> array_values( array_unique( $changed ) ),
			] );
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'cb_cm_option_errors_' . get_current_user_id() . '_' . $page_slug, array_values( array_unique( $errors ) ), MINUTE_IN_SECONDS );
			self::redirect_to_page( $page, [ 'cb_cm_options_error' => '1' ] );
		}
		self::redirect_to_page( $page, [ 'cb_cm_options_saved' => '1' ] );
	}

	/** @return array<string,array<string,mixed>> */
	private static function groups_for_page( string $page_slug ): array {
		$groups = [];
		foreach ( Repository::field_groups() as $group_id => $group ) {
			if ( LocationMatcher::matches_option_page( $group, $page_slug ) ) {
				$groups[ (string) $group_id ] = $group;
			}
		}
		return $groups;
	}

	private static function page_needs_runtime_fields( string $page_slug ): bool {
		foreach ( self::groups_for_page( $page_slug ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && ( FieldTypes::is_structured_type( (string) ( $field['type'] ?? '' ) ) || ! empty( $field['conditional_logic'] ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function register_page( string $slug, array $page ): void {
		$title = (string) ( $page['title'] ?? $slug );
		$menu_label = (string) ( $page['menu_label'] ?? $title );
		$capability = (string) ( $page['capability'] ?? 'manage_options' );
		$parent = (string) ( $page['parent_slug'] ?? '' );
		$position = is_int( $page['position'] ?? null ) ? $page['position'] : null;
		$hook = '';
		if ( '' === $parent ) {
			$hook = (string) add_menu_page( $title, $menu_label, $capability, $slug, [ __CLASS__, 'render_current' ], Icon::menu_icon_argument( (string) ( $page['icon'] ?? 'dashicons-admin-generic' ), 'dashicons-admin-generic' ), $position );
		} else {
			$hook = (string) add_submenu_page( $parent, $title, $menu_label, $capability, $slug, [ __CLASS__, 'render_current' ], $position );
		}
		if ( '' !== $hook ) {
			self::$hooks[ $hook ] = $slug;
		}
	}

	private static function page_has_media_fields( string $page_slug ): bool {
		foreach ( self::groups_for_page( $page_slug ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && FieldTypes::contains_media( $field ) ) {
					return true;
				}
			}
		}
		return false;
	}


	private static function page_has_relation_fields( string $page_slug ): bool {
		foreach ( self::groups_for_page( $page_slug ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && FieldTypes::contains_relations( $field ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function render_notice( string $page_slug ): void {
		if ( ! empty( $_GET['cb_cm_options_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Options saved.', 'core-blueprint' ) . '</p></div>';
		}
		if ( empty( $_GET['cb_cm_options_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$key = 'cb_cm_option_errors_' . get_current_user_id() . '_' . $page_slug;
		$errors = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $errors ) || empty( $errors ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Some option fields need attention:', 'core-blueprint' ) . '</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( (string) $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/** @param array<string,string> $args */
	private static function redirect_to_page( array $page, array $args ): never {
		$slug = (string) ( $page['slug'] ?? '' );
		$parent = (string) ( $page['parent_slug'] ?? '' );
		if ( '' !== $parent && str_contains( $parent, '.php' ) ) {
			$url = add_query_arg( [ 'page' => $slug ], admin_url( $parent ) );
		} else {
			$url = add_query_arg( [ 'page' => $slug ], admin_url( 'admin.php' ) );
		}
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}
}
