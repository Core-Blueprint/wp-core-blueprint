<?php
declare(strict_types=1);

namespace CB\Core\AdminColumns\Admin;

use CB\Core\AdminColumns\PolicyRepository;
use CB\Core\AdminColumns\RegisteredMetaColumns;
use CB\Core\AdminColumns\Runtime;
use CB\Core\AdminColumns\SupportedScreen;
use CB\Core\AdminColumns\TaxonomyColumns;
use CB\Core\UI\Assets;

defined( 'ABSPATH' ) || exit;

final class ScreenSettings {
	private static bool $attached = false;

	public static function attach( \WP_Screen $screen ): void {
		if ( ! SupportedScreen::is_supported( $screen ) || ! current_user_can( 'manage_options' ) || self::$attached ) {
			return;
		}
		self::$attached = true;
		add_filter( 'screen_settings', [ self::class, 'render' ], 20, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! current_user_can( 'manage_options' ) || ! SupportedScreen::is_supported( $screen ) ) {
			return;
		}
		Assets::enqueue_reorder( Assets::REORDER_PRESENTATION_WP_NATIVE );
		wp_enqueue_style(
			'cb-core-admin-columns',
			CB_CORE_URL . 'assets/css/features/admin-columns.css',
			[ 'cb-core-css-reorder-native' ],
			CB_CORE_VERSION
		);
		wp_enqueue_script_module(
			'@cb-core/admin-columns',
			CB_CORE_URL . 'assets/js/features/admin-columns.js',
			[ '@cb-core/reorder' ],
			CB_CORE_VERSION
		);
	}

	public static function render( string $settings, \WP_Screen $screen ): string {
		if ( ! current_user_can( 'manage_options' ) || ! SupportedScreen::is_supported( $screen ) ) {
			return $settings;
		}

		$screen_id = (string) $screen->id;
		$post_type = (string) $screen->post_type;
		$policy = PolicyRepository::screen( $screen_id ) ?? [
			'order' => [], 'hidden' => [], 'taxonomies' => [], 'meta' => [],
		];
		$discovered = Runtime::discovered_columns( $screen_id );
		$column_ids = array_map( 'strval', array_keys( $discovered ) );
		foreach ( $policy['order'] as $column_id ) {
			if ( ! in_array( $column_id, $column_ids, true ) ) {
				$column_ids[] = $column_id;
			}
		}

		$source_map = [];
		foreach ( $policy['taxonomies'] as $taxonomy ) {
			$source_map[ TaxonomyColumns::column_id( $taxonomy ) ] = [ 'kind' => 'taxonomy', 'key' => $taxonomy ];
		}
		foreach ( $policy['meta'] as $meta_key ) {
			$source_map[ RegisteredMetaColumns::column_id( $meta_key ) ] = [ 'kind' => 'meta', 'key' => $meta_key ];
		}

		$taxonomy_catalog = TaxonomyColumns::catalog( $post_type );
		foreach ( $policy['taxonomies'] as $taxonomy ) {
			$taxonomy_catalog[ $taxonomy ] ??= [
				'label' => $taxonomy,
				'column_id' => TaxonomyColumns::column_id( $taxonomy ),
			];
		}
		$meta_catalog = RegisteredMetaColumns::catalog( $post_type );
		foreach ( $policy['meta'] as $meta_key ) {
			$meta_catalog[ $meta_key ] ??= [
				'meta_key' => $meta_key,
				'column_id' => RegisteredMetaColumns::column_id( $meta_key ),
				'label' => $meta_key,
				'type' => '',
				'content_model' => false,
			];
		}

		ob_start();
		?>
		<div
			class="cb-admin-columns-governance"
			data-cb-admin-columns-governance
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( Ajax::ACTION ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( Ajax::NONCE_ACTION ) ); ?>"
			data-screen-id="<?php echo esc_attr( $screen_id ); ?>"
			data-saving="<?php echo esc_attr__( 'Saving…', 'core-blueprint' ); ?>"
			data-error="<?php echo esc_attr__( 'The site-wide column policy could not be saved.', 'core-blueprint' ); ?>"
		>
			<h5><?php esc_html_e( 'Site-wide Admin Columns Governance', 'core-blueprint' ); ?></h5>
			<p class="description"><?php esc_html_e( 'This Core Blueprint policy applies to this list screen site-wide. WordPress personal Screen Options remain independent for each user.', 'core-blueprint' ); ?></p>

			<div class="cb-admin-columns-governance__editor" data-cb-core-reorder>
				<div class="cb-admin-columns-governance__list" data-cb-core-reorder-list="columns" data-cb-core-reorder-list-label="<?php echo esc_attr__( 'Governed columns', 'core-blueprint' ); ?>">
					<?php foreach ( $column_ids as $column_id ) :
						$raw_label = $discovered[ $column_id ] ?? '';
						$label = self::plain_label( $raw_label, $column_id );
						$source = $source_map[ $column_id ] ?? null;
						$hidden = in_array( $column_id, $policy['hidden'], true );
						$protected = in_array( $column_id, [ 'cb', 'title' ], true );
						?>
						<div
							class="cb-admin-columns-governance__item"
							data-cb-core-reorder-item="<?php echo esc_attr( $column_id ); ?>"
							data-cb-core-reorder-label="<?php echo esc_attr( $label ); ?>"
							<?php if ( is_array( $source ) ) : ?>
								data-source-kind="<?php echo esc_attr( $source['kind'] ); ?>"
								data-source-key="<?php echo esc_attr( $source['key'] ); ?>"
							<?php endif; ?>
						>
							<button type="button" class="button-link cb-admin-columns-governance__handle" data-cb-core-reorder-handle aria-label="<?php echo esc_attr( sprintf( __( 'Reorder %s', 'core-blueprint' ), $label ) ); ?>" <?php disabled( 'cb' === $column_id ); ?>><?php esc_html_e( 'Move', 'core-blueprint' ); ?></button>
							<label class="cb-admin-columns-governance__visibility">
								<input type="checkbox" data-column-visible <?php checked( ! $hidden ); ?> <?php disabled( $protected ); ?> />
								<span><?php echo esc_html( $label ); ?></span>
							</label>
							<code><?php echo esc_html( $column_id ); ?></code>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="cb-admin-columns-governance__sources">
				<div>
					<strong><?php esc_html_e( 'Taxonomy columns', 'core-blueprint' ); ?></strong>
					<?php if ( [] === $taxonomy_catalog ) : ?>
						<p class="description"><?php esc_html_e( 'No registered taxonomies are available for this post type.', 'core-blueprint' ); ?></p>
					<?php else : foreach ( $taxonomy_catalog as $taxonomy => $entry ) : ?>
						<label><input type="checkbox" data-source-toggle="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" <?php checked( in_array( $taxonomy, $policy['taxonomies'], true ) ); ?> /> <?php echo esc_html( (string) $entry['label'] ); ?></label>
					<?php endforeach; endif; ?>
				</div>
				<div>
					<strong><?php esc_html_e( 'Registered meta columns', 'core-blueprint' ); ?></strong>
					<?php if ( [] === $meta_catalog ) : ?>
						<p class="description"><?php esc_html_e( 'No supported registered scalar post meta is available for this post type.', 'core-blueprint' ); ?></p>
					<?php else : foreach ( $meta_catalog as $meta_key => $entry ) : ?>
						<label><input type="checkbox" data-source-toggle="meta" value="<?php echo esc_attr( $meta_key ); ?>" <?php checked( in_array( $meta_key, $policy['meta'], true ) ); ?> /> <?php echo esc_html( (string) $entry['label'] ); ?> <code><?php echo esc_html( $meta_key ); ?></code></label>
					<?php endforeach; endif; ?>
				</div>
			</div>

			<p class="description"><?php esc_html_e( 'Newly enabled additional columns appear after saving and reloading. Reopen Screen Options to position them.', 'core-blueprint' ); ?></p>
			<div class="cb-admin-columns-governance__actions">
				<button type="button" class="button button-primary" data-admin-columns-save><?php esc_html_e( 'Save site-wide policy', 'core-blueprint' ); ?></button>
				<button type="button" class="button" data-admin-columns-reset><?php esc_html_e( 'Reset this screen', 'core-blueprint' ); ?></button>
				<span class="spinner" data-admin-columns-spinner></span>
				<span class="cb-admin-columns-governance__status" role="status" aria-live="polite" data-admin-columns-status></span>
			</div>
		</div>
		<?php
		return $settings . (string) ob_get_clean();
	}

	private static function plain_label( mixed $raw, string $fallback ): string {
		if ( ! is_scalar( $raw ) ) {
			return $fallback;
		}
		$label = trim( wp_strip_all_tags( wp_specialchars_decode( (string) $raw ), true ) );
		return '' !== $label ? $label : $fallback;
	}

	private function __construct() {}
}
