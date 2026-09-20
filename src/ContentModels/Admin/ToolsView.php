<?php
declare(strict_types=1);
/**
 * Content Models admin view module: ToolsView.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\ContentModels\Admin;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\ContentModels\FieldTypes;
use CB\Core\ContentModels\Repository;
use CB\Core\ContentModels\Runtime;
use CB\Core\ContentModels\State;
use CB\Core\ContentModels\Importers\NativeWordPress\Bootstrap as NativeImporter;
use CB\Core\UI\ChoiceGroup;
use CB\Core\UI\Icon;
use CB\Core\UI\Status as StatusUi;

defined( 'ABSPATH' ) || exit;

trait ToolsView {

	private function render_tools(): void {
		$this->render_header( __( 'Export or import Content Models schema definitions. Customer content values are never included in schema transfers.', 'core-blueprint' ) );
		if ( ! State::is_enabled() ) {
			$this->render_disabled_panel();
			echo '</div>';
			return;
		}
		$preview = Transfer::current_preview();
		$error = isset( $_GET['cb_cm_import_error'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['cb_cm_import_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( ! empty( $_GET['cb_cm_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Content Models schema imported successfully. Existing content values were not changed.', 'core-blueprint' ) . '</p></div>';
		}
		?>
		<div class="cb-core-stack cb-core-stack--loose cb-content-models-tools-stack">
		<div class="cb-content-models-transfer-grid">
			<section class="cb-core-section">
				<h2><?php esc_html_e( 'Export Schema', 'core-blueprint' ); ?></h2>
				<p><?php esc_html_e( 'Download user-managed post types, taxonomies, Option Pages, Field Groups and field definitions as JSON. Stored posts, terms, users, metadata and option values are excluded.', 'core-blueprint' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cb_core_content_models_export_schema" />
					<?php wp_nonce_field( 'cb_core_content_models_export_schema' ); ?>
					<button class="button cb-core-button cb-core-button--secondary" type="submit"><?php esc_html_e( 'Download JSON', 'core-blueprint' ); ?></button>
				</form>
			</section>
			<section class="cb-core-section">
				<h2><?php esc_html_e( 'Import Schema', 'core-blueprint' ); ?></h2>
				<p><?php esc_html_e( 'Upload a Core Blueprint Content Models JSON document for validation and conflict preview. Import is merge-based and never deletes definitions that are absent from the file.', 'core-blueprint' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cb_core_content_models_preview_import" />
					<?php wp_nonce_field( 'cb_core_content_models_preview_import' ); ?>
					<input type="file" name="schema_file" accept="application/json,.json" required />
					<button class="button cb-core-button cb-core-button--secondary" type="submit"><?php esc_html_e( 'Analyse Import', 'core-blueprint' ); ?></button>
				</form>
			</section>
		</div>
		<?php if ( is_array( $preview ) && is_array( $preview['analysis'] ?? null ) ) :
			$analysis = $preview['analysis'];
			$counts = is_array( $analysis['counts'] ?? null ) ? $analysis['counts'] : [];
			$conflicts = is_array( $analysis['conflicts'] ?? null ) ? $analysis['conflicts'] : [];
			$locked = is_array( $analysis['locked'] ?? null ) ? $analysis['locked'] : [];
		?>
			<section class="cb-core-section cb-content-models-import-preview">
				<h2><?php esc_html_e( 'Import Preview', 'core-blueprint' ); ?></h2>
				<p><?php echo esc_html( sprintf( __( '%1$d post types, %2$d taxonomies, %3$d Option Pages and %4$d Field Groups passed schema validation.', 'core-blueprint' ), (int) ( $counts['post_types'] ?? 0 ), (int) ( $counts['taxonomies'] ?? 0 ), (int) ( $counts['option_pages'] ?? 0 ), (int) ( $counts['field_groups'] ?? 0 ) ) ); ?></p>
				<?php if ( ! empty( $locked ) ) : ?>
					<div class="notice notice-error inline"><p><?php esc_html_e( 'Import is blocked because one or more matching definitions are owned and locked by another plugin.', 'core-blueprint' ); ?></p></div>
					<ul><?php foreach ( $locked as $item ) : ?><li><code><?php echo esc_html( (string) ( $item['section'] ?? '' ) . ':' . (string) ( $item['key'] ?? '' ) ); ?></code> — <?php echo esc_html( (string) ( $item['owner'] ?? '' ) ); ?></li><?php endforeach; ?></ul>
				<?php else : ?>
					<?php if ( ! empty( $conflicts ) ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( _n( '%d existing user-managed definition has the same key.', '%d existing user-managed definitions have the same key.', count( $conflicts ), 'core-blueprint' ), count( $conflicts ) ) ); ?></p></div><?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cb_core_content_models_apply_import" />
						<?php wp_nonce_field( 'cb_core_content_models_apply_import' ); ?>
						<?php if ( ! empty( $conflicts ) ) : ?><label class="cb-content-models-import-overwrite"><input type="checkbox" name="overwrite" value="1" /> <?php esc_html_e( 'Replace matching user-managed definitions with the imported definitions', 'core-blueprint' ); ?></label><?php endif; ?>
						<?php MutationAcknowledgement::render(
							'content_models_import_acknowledgement',
							'cb-content-models-import-acknowledgement',
							__( "I understand that importing this schema changes this site's Content Models configuration and that I am responsible for having a recent backup or other recovery option available.", 'core-blueprint' ),
							__( 'Imported definitions can affect registered post types, taxonomies, Option Pages and fields. Existing definitions may be replaced when overwrite is selected.', 'core-blueprint' )
						); ?>
						<button class="button cb-core-button cb-core-button--primary" type="submit"<?php echo ! empty( $conflicts ) ? ' data-requires-overwrite' : ''; ?>><?php esc_html_e( 'Import Schema', 'core-blueprint' ); ?></button>
					</form>
				<?php endif; ?>
			</section>
		<?php endif; ?>
		<?php NativeImporter::render(); ?>
		</div>
		</div>
		<?php
	}

}
