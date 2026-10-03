<?php
declare(strict_types=1);
/**
 * Content Models admin view module: CommonView.
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

trait CommonView {

	private function render_header( string $intro ): void {
		?>
		<div class="wrap cb-core-wrap cb-content-models-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Content Models', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php echo esc_html( $intro ); ?></p>
			<?php $this->render_notice(); ?>
			<?php $this->render_runtime_errors(); ?>
		<?php
	}

	private function render_disabled_panel(): void {
		$url = add_query_arg( 'tab', 'content-models', admin_url( 'admin.php?page=core-blueprint-preferences' ) );
		?>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Content Models is disabled.', 'core-blueprint' ); ?></strong> <?php esc_html_e( 'Saved definitions and content are preserved, but Core Blueprint is not registering custom post types, taxonomies, Option Pages or custom fields.', 'core-blueprint' ); ?> <a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open Dashboard', 'core-blueprint' ); ?></a></p></div>
		<?php
	}

	private function render_runtime_errors(): void {
		foreach ( Runtime::errors() as $error ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s:</strong> %s</p></div>',
				esc_html( (string) ( $error['key'] ?? '' ) ),
				esc_html( (string) ( $error['message'] ?? '' ) )
			);
		}
	}

	private function render_notice(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $status ) {
			return;
		}

		$type = 'success';
		$message = match ( $status ) {
			'saved'     => __( 'Content model saved.', 'core-blueprint' ),
			'deleted'       => __( 'Content model definition deleted. Stored content was left untouched.', 'core-blueprint' ),
			'field-saved'   => __( 'Field definition saved. Values use native WordPress post meta.', 'core-blueprint' ),
			'field-deleted' => __( 'Field definition deleted. Existing post meta values were preserved.', 'core-blueprint' ),
			'field-order-saved' => __( 'Field order saved. The new sequence is now used when rendering this field group.', 'core-blueprint' ),
			'not-found' => __( 'The requested content model could not be found.', 'core-blueprint' ),
			'in-use'    => __( 'The post type cannot be deleted while a Core Blueprint taxonomy or field group still depends on it.', 'core-blueprint' ),
			'option-page-in-use' => __( 'The Option Page cannot be deleted while a Field Group or child Option Page still depends on it.', 'core-blueprint' ),
			'disabled'  => __( 'Enable Content Models before changing model definitions.', 'core-blueprint' ),
			'error'     => isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : __( 'The content model could not be saved.', 'core-blueprint' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			default     => '',
		};
		if ( in_array( $status, [ 'error', 'not-found', 'in-use', 'option-page-in-use', 'disabled' ], true ) ) {
			$type = 'error';
		}
		if ( '' !== $message ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	private function copy_label( string $label ): string {
		/* translators: %s: source definition label. */
		return sprintf( __( '%s Copy', 'core-blueprint' ), $label );
	}

	private function suggest_unique_model_key( string $source_key, string $kind, int $max_length ): string {
		$base = sanitize_key( $source_key );
		if ( '' === $base ) {
			$base = 'model';
		}
		$suffix_number = 1;
		do {
			$suffix = 1 === $suffix_number ? '-copy' : '-copy-' . $suffix_number;
			$candidate = substr( $base, 0, max( 1, $max_length - strlen( $suffix ) ) ) . $suffix;
			$in_use = 'taxonomy' === $kind
				? ( null !== Repository::taxonomy( $candidate ) || taxonomy_exists( $candidate ) )
				: ( null !== Repository::post_type( $candidate ) || post_type_exists( $candidate ) );
			++$suffix_number;
		} while ( $in_use );
		return $candidate;
	}

	private function render_icon_picker( string $id, string $name, string $value, string $description = '' ): void {
		$value = Icon::normalize_menu_icon( $value, 'dashicons-admin-generic' );
		?>
		<div class="cb-core-picker cb-core-icon-picker" data-cb-core-icon-picker>
			<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" type="text" class="regular-text code cb-core-picker__input" value="<?php echo esc_attr( $value ); ?>" data-cb-core-icon-picker-input />
			<div class="cb-core-picker__enhanced" data-cb-core-icon-picker-enhanced hidden>
				<button type="button" class="button cb-core-picker__toggle" data-cb-core-icon-picker-toggle aria-expanded="false" aria-haspopup="dialog">
					<span class="cb-core-picker__toggle-main">
						<span class="cb-core-picker__toggle-icon" data-cb-core-icon-picker-preview aria-hidden="true"></span>
						<span class="cb-core-picker__toggle-copy">
							<span class="cb-core-picker__toggle-text" data-cb-core-icon-picker-label></span>
							<span class="cb-core-picker__toggle-meta" data-cb-core-icon-picker-meta></span>
						</span>
					</span>
					<?php echo Icon::render( 'chevron-down', [ 'class' => 'cb-core-picker__toggle-chevron' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted Icon Foundation renderer. ?>
				</button>
				<div class="cb-core-picker__panel" data-cb-core-icon-picker-panel hidden>
					<div class="cb-core-picker__toolbar"><input type="search" class="regular-text cb-core-picker__search" data-cb-core-icon-picker-search placeholder="<?php esc_attr_e( 'Search icons…', 'core-blueprint' ); ?>" /></div>
					<div class="cb-core-picker__families">
						<button type="button" class="button cb-core-picker__family" data-cb-core-icon-picker-family="dashicons"><?php esc_html_e( 'Dashicons', 'core-blueprint' ); ?></button>
						<button type="button" class="button cb-core-picker__family" data-cb-core-icon-picker-family="lucide"><?php esc_html_e( 'Lucide', 'core-blueprint' ); ?></button>
					</div>
					<div class="cb-core-picker__results cb-core-icon-picker__results" data-cb-core-icon-picker-results></div>
				</div>
			</div>
			<?php if ( '' !== $description ) : ?><p class="description"><?php echo esc_html( $description ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private function render_capability_picker( string $id, string $name, string $value, string $description = '' ): void {
		$value = sanitize_key( $value );
		if ( '' === $value ) {
			$value = 'manage_options';
		}
		?>
		<div class="cb-core-picker cb-core-capability-picker" data-cb-core-capability-picker>
			<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" type="text" class="regular-text code cb-core-picker__input" value="<?php echo esc_attr( $value ); ?>" data-cb-core-capability-picker-input />
			<div class="cb-core-picker__enhanced" data-cb-core-capability-picker-enhanced hidden>
				<button type="button" class="button cb-core-picker__toggle" data-cb-core-capability-picker-toggle aria-expanded="false" aria-haspopup="dialog">
					<span class="cb-core-picker__toggle-main">
						<span class="cb-core-picker__toggle-copy">
							<span class="cb-core-picker__toggle-text" data-cb-core-capability-picker-label></span>
							<span class="cb-core-picker__toggle-meta"><?php esc_html_e( 'Capability', 'core-blueprint' ); ?></span>
						</span>
					</span>
					<?php echo Icon::render( 'chevron-down', [ 'class' => 'cb-core-picker__toggle-chevron' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted Icon Foundation renderer. ?>
				</button>
				<div class="cb-core-picker__panel" data-cb-core-capability-picker-panel hidden>
					<div class="cb-core-picker__toolbar"><input type="search" class="regular-text cb-core-picker__search" data-cb-core-capability-picker-search placeholder="<?php esc_attr_e( 'Search capabilities…', 'core-blueprint' ); ?>" /></div>
					<div class="cb-core-picker__results cb-core-capability-picker__results" data-cb-core-capability-picker-results></div>
				</div>
			</div>
			<?php if ( '' !== $description ) : ?><p class="description"><?php echo esc_html( $description ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );
		return null !== $object ? (string) $object->labels->name : $post_type;
	}

	private function option_page_label( string $slug ): string {
		$page = Repository::option_page( $slug );
		return null !== $page ? (string) ( $page['title'] ?? $slug ) : $slug;
	}

	/** @return array<string,string> */

}
