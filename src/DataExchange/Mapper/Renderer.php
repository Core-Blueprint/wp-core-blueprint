<?php
declare(strict_types=1);
/**
 * Shared Designer Shell renderer for the Core Blueprint Data Mapper.
 *
 * Consumers own routing, upload/download transport, provider selection and the
 * final domain operation. Base owns this consistent mapping workspace.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange\Mapper;

use CB\Core\DataExchange\Foundation;
use CB\Core\DataExchange\Mapper;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Render one reusable Data Mapper workspace.
	 *
	 * `intake` permits an initially empty source schema. The browser keeps the
	 * chosen File object request-local and asks the consumer to inspect it through
	 * its own authorized transport before calling `setSourceFields()`.
	 *
	 * @param array<string,mixed> $configuration
	 */
	public static function render( array $configuration ): string|WP_Error {
		$direction = isset( $configuration['direction'] ) && is_string( $configuration['direction'] )
			? trim( $configuration['direction'] )
			: '';
		if ( ! Foundation::is_direction( $direction ) ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_direction', 'Data Mapper direction is invalid.' );
		}
		$intake = Foundation::DIRECTION_IMPORT === $direction && true === ( $configuration['intake'] ?? false );

		$source_input = isset( $configuration['source_fields'] ) && is_array( $configuration['source_fields'] )
			? $configuration['source_fields']
			: [];
		if ( [] === $source_input && $intake ) {
			$source_fields = [];
		} elseif ( [] === $source_input ) {
			$source_fields = new WP_Error( 'cb_core_data_mapper_missing_source_schema', 'Data Mapper source schema is missing.' );
		} else {
			$source_fields = Mapper::normalize_schema( $source_input );
		}

		$target_fields = isset( $configuration['target_fields'] ) && is_array( $configuration['target_fields'] )
			? Mapper::normalize_schema( $configuration['target_fields'] )
			: new WP_Error( 'cb_core_data_mapper_missing_target_schema', 'Data Mapper target schema is missing.' );
		if ( is_wp_error( $source_fields ) || is_wp_error( $target_fields ) ) {
			return is_wp_error( $source_fields ) ? $source_fields : $target_fields;
		}

		if ( [] === $source_fields ) {
			$plan = [ 'valid' => true, 'mapping' => [] ];
		} else {
			$mapping = isset( $configuration['mapping'] ) && is_array( $configuration['mapping'] )
				? $configuration['mapping']
				: Mapper::suggest( $source_fields, $target_fields );
			if ( is_wp_error( $mapping ) ) {
				return $mapping;
			}
			$plan = Mapper::plan( $source_fields, $target_fields, $mapping, false );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( ! $plan['valid'] ) {
				return new WP_Error(
					'cb_core_data_mapper_invalid_mapping',
					'Data Mapper renderer received an invalid initial mapping.',
					[ 'plan' => $plan ]
				);
			}
		}

		$title = self::label( $configuration['title'] ?? null, __( 'Data Mapper', 'core-blueprint' ) );
		$source_label = self::label( $configuration['source_label'] ?? null, __( 'Source', 'core-blueprint' ) );
		$target_label = self::label( $configuration['target_label'] ?? null, __( 'Target', 'core-blueprint' ) );
		$primary_label = self::label(
			$configuration['primary_label'] ?? null,
			Foundation::DIRECTION_IMPORT === $direction
				? __( 'Validate mapping', 'core-blueprint' )
				: __( 'Preview export', 'core-blueprint' )
		);
		$accept = isset( $configuration['accept'] ) && is_string( $configuration['accept'] )
			? trim( $configuration['accept'] )
			: '.csv,text/csv';
		if ( strlen( $accept ) > 191 || str_contains( $accept, "\0" ) ) {
			$accept = '.csv,text/csv';
		}

		$launch_mode = isset( $configuration['launch_mode'] ) && 'direct' === $configuration['launch_mode'] ? 'direct' : 'manual';
		$exit_url = isset( $configuration['exit_url'] ) && is_string( $configuration['exit_url'] )
			? esc_url_raw( trim( $configuration['exit_url'] ) )
			: '';
		if ( 'direct' === $launch_mode && '' === $exit_url ) {
			$launch_mode = 'manual';
		}

		$instance_id = wp_unique_id( 'cb-data-mapper-' );
		$client_config = [
			'direction'    => $direction,
			'intake'       => $intake,
			'sourceLabel'  => $source_label,
			'targetLabel'  => $target_label,
			'sourceFields' => $source_fields,
			'targetFields' => $target_fields,
			'mapping'      => $plan['mapping'],
			'labels'       => [
				'mapping'          => __( 'Mapping', 'core-blueprint' ),
				'preview'          => __( 'Preview', 'core-blueprint' ),
				'searchFields'     => __( 'Search fields', 'core-blueprint' ),
				'direct'           => __( 'Direct', 'core-blueprint' ),
				'ignore'           => __( 'Ignore', 'core-blueprint' ),
				'constant'         => __( 'Constant', 'core-blueprint' ),
				'targetField'      => __( 'Target field', 'core-blueprint' ),
				'transform'        => __( 'Transform', 'core-blueprint' ),
				'constantValue'    => __( 'Constant value', 'core-blueprint' ),
				'noSelection'      => __( 'Select a field mapping to inspect it.', 'core-blueprint' ),
				'noPreview'        => __( 'No validated preview is available yet.', 'core-blueprint' ),
				'allMapped'        => __( 'Mapping is complete.', 'core-blueprint' ),
				'needsAttention'   => __( 'Mapping needs attention.', 'core-blueprint' ),
				'unmappedRequired' => __( 'Required target fields are still unmapped.', 'core-blueprint' ),
				'chooseFile'       => __( 'Choose source file', 'core-blueprint' ),
				'fileSelected'     => __( 'Source file selected.', 'core-blueprint' ),
				'inspectingFile'   => __( 'Inspecting source file…', 'core-blueprint' ),
				'fileNeeded'       => __( 'Choose a source file to begin mapping.', 'core-blueprint' ),
			],
		];
		$json = wp_json_encode( $client_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'cb_core_data_mapper_render_failed', 'Data Mapper client configuration could not be encoded.' );
		}

		Assets::enqueue( $title );

		$root_attributes = sprintf(
			'id="%1$s" data-cb-design-launch-root data-cb-data-mapper-root data-cb-design-title="%2$s"',
			esc_attr( $instance_id ),
			esc_attr( $title )
		);
		if ( 'direct' === $launch_mode ) {
			$root_attributes .= ' data-cb-design-launch-mode="direct" data-cb-design-exit-url="' . esc_attr( $exit_url ) . '"';
		}

		ob_start();
		?>
		<div <?php echo $root_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped values. ?>>
			<div data-cb-design-launch-context class="cb-core-data-mapper__launch-context">
				<p><?php echo esc_html__( 'Map source fields to a target structure, validate the result, and review what will happen before data moves.', 'core-blueprint' ); ?></p>
			</div>

			<div class="cb-core-design-shell cb-core-data-mapper" data-cb-design-shell<?php echo 'manual' === $launch_mode ? ' hidden' : ''; ?>>
				<div class="cb-core-design-shell__toolbar">
					<div class="cb-core-design-shell__toolbar-group">
						<span data-cb-design-shell-group-label><?php echo esc_html__( 'History', 'core-blueprint' ); ?></span>
						<button type="button" class="button cb-core-button" data-cb-design-shell-undo><?php echo esc_html__( 'Undo', 'default' ); ?></button>
						<button type="button" class="button cb-core-button" data-cb-design-shell-redo><?php echo esc_html__( 'Redo', 'default' ); ?></button>
					</div>
					<span data-cb-design-shell-status aria-live="polite"></span>
					<button
						type="button"
						class="button cb-core-button"
						data-cb-design-shell-fullscreen
						data-cb-design-shell-fullscreen-enter-label="<?php echo esc_attr__( 'Fullscreen mode', 'default' ); ?>"
						data-cb-design-shell-fullscreen-exit-label="<?php echo esc_attr__( 'Exit fullscreen', 'default' ); ?>"
					><?php echo esc_html__( 'Fullscreen mode', 'default' ); ?></button>
					<button type="button" class="button button-primary cb-core-button cb-core-button--primary" data-cb-design-shell-primary-action data-cb-data-mapper-primary><?php echo esc_html( $primary_label ); ?></button>
				</div>

				<div class="cb-core-design-shell__workspace cb-core-data-mapper__workspace">
					<aside class="cb-core-design-shell__palette cb-core-data-mapper__source" aria-label="<?php echo esc_attr( $source_label ); ?>">
						<div class="cb-core-data-mapper__panel-heading">
							<strong><?php echo esc_html( $source_label ); ?></strong>
							<?php if ( $intake ) : ?>
								<label class="cb-core-data-mapper__file-control">
									<span><?php echo esc_html__( 'Source file', 'core-blueprint' ); ?></span>
									<input type="file" data-cb-data-mapper-file accept="<?php echo esc_attr( $accept ); ?>">
								</label>
								<span class="description" data-cb-data-mapper-file-name><?php echo esc_html__( 'Choose a source file to begin mapping.', 'core-blueprint' ); ?></span>
							<?php endif; ?>
							<input type="search" class="regular-text" data-cb-data-mapper-search placeholder="<?php echo esc_attr__( 'Search fields', 'core-blueprint' ); ?>"<?php echo [] === $source_fields ? ' disabled' : ''; ?>>
						</div>
						<div data-cb-data-mapper-source-fields></div>
					</aside>

					<main class="cb-core-design-shell__canvas cb-core-data-mapper__canvas">
						<div class="cb-core-data-mapper__canvas-header">
							<div>
								<strong><?php echo esc_html__( 'Field mapping', 'core-blueprint' ); ?></strong>
								<span data-cb-data-mapper-summary></span>
							</div>
							<button type="button" class="button cb-core-button" data-cb-data-mapper-auto<?php echo [] === $source_fields ? ' disabled' : ''; ?>><?php echo esc_html__( 'Auto-match', 'core-blueprint' ); ?></button>
						</div>
						<div class="cb-core-data-mapper__mapping-list" data-cb-data-mapper-mappings></div>
					</main>

					<aside class="cb-core-design-shell__sidebar cb-core-data-mapper__sidebar" aria-label="<?php echo esc_attr__( 'Data Mapper details', 'core-blueprint' ); ?>">
						<div class="cb-core-design-shell__sidebar-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Data Mapper details', 'core-blueprint' ); ?>">
							<button type="button" class="cb-core-design-shell__sidebar-tab is-active" role="tab" aria-selected="true" data-cb-design-shell-group="mapper-details" data-cb-design-shell-tab="mapping"><?php echo esc_html__( 'Mapping', 'core-blueprint' ); ?></button>
							<button type="button" class="cb-core-design-shell__sidebar-tab" role="tab" aria-selected="false" data-cb-design-shell-group="mapper-details" data-cb-design-shell-tab="preview"><?php echo esc_html__( 'Preview', 'core-blueprint' ); ?></button>
						</div>
						<section class="cb-core-design-shell__sidebar-panel" role="tabpanel" data-cb-design-shell-group="mapper-details" data-cb-design-shell-panel="mapping">
							<div data-cb-data-mapper-inspector></div>
						</section>
						<section class="cb-core-design-shell__sidebar-panel" role="tabpanel" data-cb-design-shell-group="mapper-details" data-cb-design-shell-panel="preview" hidden>
							<div data-cb-data-mapper-preview></div>
						</section>
					</aside>
				</div>

				<script type="application/json" data-cb-data-mapper-config><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX encoded. ?></script>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function label( mixed $value, string $fallback ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$value = trim( wp_strip_all_tags( $value ) );
		return '' !== $value && strlen( $value ) <= 120 ? $value : $fallback;
	}

	private function __construct() {}
}
