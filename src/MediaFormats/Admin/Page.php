<?php
declare(strict_types=1);
/**
 * Media Formats admin page.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats\Admin;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\MediaFormats\Capabilities;
use CoreBlueprint\Core\MediaFormats\Environment;
use CoreBlueprint\Core\MediaFormats\FormatRegistry;
use CoreBlueprint\Core\MediaFormats\Settings;
use CoreBlueprint\Core\MediaFormats\Svg\Sanitizer as SvgSanitizer;
use CoreBlueprint\Core\UI\StateBadge;
use CoreBlueprint\Core\UI\Status;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {
	public const SLUG = 'core-blueprint-media-formats';

	public function slug(): string { return self::SLUG; }
	public function title(): string { return __( 'Media Formats', 'core-blueprint' ); }
	public function position(): ?int { return 28; }

	public function render(): void {
		$this->guard();
		$settings = Settings::all();
		$formats  = FormatRegistry::all();
		$result   = isset( $_GET['media_formats_result'] ) ? sanitize_key( wp_unslash( $_GET['media_formats_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash status.
		?>
		<div class="wrap cb-core-wrap cb-media-formats-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Media Formats', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Choose which modern image formats WordPress may accept and how generated image sizes are stored. Core Blueprint uses WordPress-native image processing wherever possible and sanitizes SVG files before they are saved.', 'core-blueprint' ); ?></p>

			<?php if ( 'saved' === $result ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Media Formats settings saved.', 'core-blueprint' ); ?></p></div>
			<?php elseif ( 'failed' === $result ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Media Formats settings could not be saved.', 'core-blueprint' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-media-formats-form cb-core-form-scope">
				<input type="hidden" name="action" value="cb_core_media_formats_save" />
				<?php wp_nonce_field( 'cb_core_media_formats_save' ); ?>

				<section class="cb-core-panel cb-media-formats-section">
					<div class="cb-media-formats-section__head">
						<div>
							<h2><?php esc_html_e( 'Upload formats', 'core-blueprint' ); ?></h2>
							<p><?php esc_html_e( 'Enable only the formats you want editors to upload. Options that require server-side image processing are disabled automatically when this server cannot handle them.', 'core-blueprint' ); ?></p>
						</div>
					</div>

					<div class="cb-media-formats-list">
						<?php $this->render_format_toggle( 'svg', $formats['svg'], $settings['svg_uploads'], __( 'Allow scalable vector graphics in the Media Library. Every uploaded SVG is sanitized and remote references are removed before WordPress stores the file.', 'core-blueprint' ) ); ?>
						<?php $this->render_format_toggle( 'webp', $formats['webp'], $settings['webp_uploads'], __( 'Allow WebP images when the active WordPress image editor can read and create WebP files.', 'core-blueprint' ) ); ?>
						<?php $this->render_format_toggle( 'avif', $formats['avif'], $settings['avif_uploads'], __( 'Allow AVIF images when the active WordPress image editor supports AVIF processing.', 'core-blueprint' ) ); ?>
						<?php $this->render_format_toggle( 'jxl', $formats['jxl'], $settings['jxl_uploads'], __( 'Allow JPEG XL uploads. Browser and WordPress processing support is still evolving, so responsive image sizes may not be generated.', 'core-blueprint' ) ); ?>
						<?php $this->render_format_toggle( 'heic', $formats['heic'], $settings['heic_imports'], __( 'Allow HEIC and HEIF source images when WordPress can process them. WordPress may convert these imports to a web-compatible format.', 'core-blueprint' ) ); ?>
					</div>
				</section>

				<section class="cb-core-panel cb-media-formats-section">
					<h2><?php esc_html_e( 'Generated images', 'core-blueprint' ); ?></h2>
					<p><?php esc_html_e( 'Choose the format WordPress should use for generated thumbnails and responsive image sizes. The original uploaded file is preserved.', 'core-blueprint' ); ?></p>
					<div class="cb-core-field">
						<label class="cb-core-field__label" for="cb-media-formats-output"><?php esc_html_e( 'Generated image format', 'core-blueprint' ); ?></label>
						<select id="cb-media-formats-output" name="output_format">
							<option value="original" <?php selected( $settings['output_format'], 'original' ); ?>><?php esc_html_e( 'Keep original format', 'core-blueprint' ); ?></option>
							<option value="webp" <?php selected( $settings['output_format'], 'webp' ); ?> <?php disabled( ! Environment::webp_supported() ); ?>>WebP<?php echo Environment::webp_supported() ? '' : ' — ' . esc_html__( 'Unavailable', 'core-blueprint' ); ?></option>
							<option value="avif" <?php selected( $settings['output_format'], 'avif' ); ?> <?php disabled( ! Environment::avif_supported() ); ?>>AVIF<?php echo Environment::avif_supported() ? '' : ' — ' . esc_html__( 'Unavailable', 'core-blueprint' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'This affects newly generated sizes only. Existing media files are not converted retroactively.', 'core-blueprint' ); ?></p>
					</div>
				</section>

				<section class="cb-core-panel cb-media-formats-section" data-cb-media-formats-svg-protection <?php echo $settings['svg_uploads'] ? '' : 'hidden'; ?>>
					<h2><?php esc_html_e( 'SVG protection', 'core-blueprint' ); ?></h2>
					<p><?php esc_html_e( 'SVG files can contain active XML content. These protections are mandatory whenever SVG uploads are enabled and cannot be switched off separately.', 'core-blueprint' ); ?></p>
					<div class="cb-media-formats-protection-grid">
						<?php $this->render_protection_fact( __( 'Sanitization', 'core-blueprint' ), __( 'Always enabled', 'core-blueprint' ) ); ?>
						<?php $this->render_protection_fact( __( 'Unsafe content', 'core-blueprint' ), __( 'Rejected or removed', 'core-blueprint' ) ); ?>
						<?php $this->render_protection_fact( __( 'Remote references', 'core-blueprint' ), __( 'Removed', 'core-blueprint' ) ); ?>
						<?php $this->render_protection_fact( __( 'Upload permission', 'core-blueprint' ), Capabilities::UPLOAD_SVG, true ); ?>
					</div>
					<?php /* translators: %s: formatted default SVG sanitization size limit. */ ?>
					<p class="description"><?php printf( esc_html__( 'The default sanitization limit is %s per SVG. The permission can be assigned to other trusted roles through Core Blueprint User Roles.', 'core-blueprint' ), esc_html( size_format( 5 * 1024 * 1024 ) ) ); ?></p>
				</section>

				<section class="cb-core-panel cb-media-formats-section">
					<h2><?php esc_html_e( 'Environment & compatibility', 'core-blueprint' ); ?></h2>
					<p><?php esc_html_e( 'This overview explains what the current server can safely process. You normally do not need to change anything here.', 'core-blueprint' ); ?></p>
					<div class="cb-media-formats-table-wrap">
						<table class="widefat striped cb-media-formats-compatibility">
							<thead><tr><th><?php esc_html_e( 'Format', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Upload', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Image processing', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $formats as $key => $format ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) $format['label'] ); ?></strong></td>
									<td><?php echo ! empty( $format['available'] ) ? esc_html__( 'Available', 'core-blueprint' ) : esc_html__( 'Unavailable', 'core-blueprint' ); ?></td>
									<td><?php echo esc_html( $this->processing_label( (string) $format['processing'] ) ); ?></td>
									<td><?php echo $this->format_badge( $key, $format ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shared StateBadge escapes output. ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<details class="cb-media-formats-details">
						<summary><?php esc_html_e( 'Show technical details', 'core-blueprint' ); ?></summary>
						<table class="cb-core-kv cb-media-formats-kv"><tbody>
							<tr><th><?php esc_html_e( 'WordPress image editor', 'core-blueprint' ); ?></th><td><?php echo esc_html( Environment::image_editor_label() ); ?></td></tr>
							<tr><th><?php esc_html_e( 'PHP', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( PHP_VERSION ); ?></code></td></tr>
							<tr><th><?php esc_html_e( 'Maximum upload size', 'core-blueprint' ); ?></th><td><?php echo esc_html( size_format( Environment::max_upload_bytes() ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'SVG XML runtime', 'core-blueprint' ); ?></th><td><?php echo Environment::svg_supported() ? esc_html__( 'DOM + libxml available', 'core-blueprint' ) : esc_html__( 'Unavailable', 'core-blueprint' ); ?></td></tr>
							<tr><th><?php esc_html_e( 'SVG sanitizer', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( SvgSanitizer::VERSION ); ?></code></td></tr>
							<tr><th><?php esc_html_e( 'Generated format', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( $settings['output_format'] ); ?></code></td></tr>
						</tbody></table>
					</details>
				</section>

				<div class="cb-core-actions cb-media-formats-actions">
					<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save Media Formats settings', 'core-blueprint' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/** @param array<string,mixed> $format */
	private function render_format_toggle( string $key, array $format, bool $checked, string $description ): void {
		$available = ! empty( $format['available'] );
		$experimental = 'jxl' === $key;
		$disabled = ! $available;
		?>
		<div class="cb-media-formats-format<?php echo $disabled ? ' is-unavailable' : ''; ?>">
			<label class="cb-core-check-row cb-media-formats-format__check">
				<input type="checkbox" name="<?php echo esc_attr( (string) $format['setting'] ); ?>" value="1" <?php checked( $checked && $available ); ?> <?php disabled( $disabled ); ?> <?php echo 'svg' === $key ? 'data-cb-media-formats-svg-toggle' : ''; ?> />
				<span class="cb-core-check-row__body">
					<strong><?php echo esc_html( (string) $format['label'] ); ?></strong>
					<small><?php echo esc_html( $description ); ?></small>
				</span>
			</label>
			<div class="cb-media-formats-format__status">
				<?php if ( $experimental ) : ?>
					<?php echo StateBadge::render( __( 'Experimental', 'core-blueprint' ), [ 'variant' => StateBadge::WARNING ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php elseif ( $available ) : ?>
					<?php echo Status::render( 'active', __( 'Available', 'core-blueprint' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<?php echo Status::render( 'idle', __( 'Not supported by this server', 'core-blueprint' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_protection_fact( string $label, string $value, bool $code = false ): void {
		?><div class="cb-media-formats-protection"><span><?php echo esc_html( $label ); ?></span><strong><?php echo $code ? '<code>' . esc_html( $value ) . '</code>' : esc_html( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></div><?php
	}

	/** @param array<string,mixed> $format */
	private function format_badge( string $key, array $format ): string {
		if ( 'jxl' === $key ) {
			return StateBadge::render( __( 'Experimental', 'core-blueprint' ), [ 'variant' => StateBadge::WARNING ] );
		}
		if ( ! empty( $format['available'] ) ) {
			return StateBadge::render( __( 'Ready', 'core-blueprint' ), [ 'variant' => StateBadge::SUCCESS ] );
		}
		return StateBadge::render( __( 'Unavailable', 'core-blueprint' ), [ 'variant' => StateBadge::NEUTRAL ] );
	}

	private function processing_label( string $processing ): string {
		return match ( $processing ) {
			'native'      => __( 'WordPress native', 'core-blueprint' ),
			'sanitized'   => __( 'Sanitized SVG', 'core-blueprint' ),
			'upload-only' => __( 'Upload only', 'core-blueprint' ),
			default       => __( 'Unavailable', 'core-blueprint' ),
		};
	}
}
