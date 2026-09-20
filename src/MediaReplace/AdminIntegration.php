<?php
declare(strict_types=1);
/**
 * WordPress admin integration for Media Replace.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\MediaReplace;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\Log\AuditLog;
use CB\Core\MediaReplace\Admin\Page as MediaReplacePage;
use CB\Core\MediaReplace\Strategy\PreserveFilenameStrategy;
use CB\Core\UI\Icon;
use CB\Core\UI\Notice;
use CB\Core\UI\Status;

defined( 'ABSPATH' ) || exit;

final class AdminIntegration {

	private const FORM_ACTION            = 'cb_core_replace_media';
	private const ACKNOWLEDGEMENT_FIELD = 'media_replace_acknowledgement';
	private const NOTICE_PREFIX          = 'cb_core_media_replace_notice_';

	public static function init_screen(): void {
		add_action( 'admin_notices', [ __CLASS__, 'render_admin_notice' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		add_filter( 'media_row_actions', [ __CLASS__, 'media_row_action' ], 10, 2 );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 10, 2 );
		add_action( 'attachment_submitbox_misc_actions', [ __CLASS__, 'attachment_submitbox_action' ] );
		self::register_preview_filter();
	}

	public static function init_ajax(): void {
		// The Media Library modal builds compatibility fields during its AJAX
		// attachment response, so the replacement action belongs here too.
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 10, 2 );
		self::register_preview_filter();
	}

	public static function init_admin_post(): void {
		add_action( 'admin_post_' . self::FORM_ACTION, [ __CLASS__, 'handle_replace' ] );
	}

	private static function register_preview_filter(): void {
		add_filter( 'wp_prepare_attachment_for_js', [ __CLASS__, 'bust_replaced_preview_cache' ], 10, 3 );
	}

	public static function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( MediaReplacePage::SLUG !== $page ) {
			return;
		}

		wp_enqueue_script_module(
			'@cb-core/media-replace',
			CB_CORE_URL . 'assets/js/features/media-replace.js',
			[],
			CB_CORE_VERSION
		);
	}

	/**
	 * @param string[] $actions Existing media row actions.
	 * @return string[]
	 */
	public static function media_row_action( array $actions, \WP_Post $post ): array {
		if ( ! self::can_replace( (int) $post->ID ) ) {
			return $actions;
		}

		$url = self::replace_url( (int) $post->ID, self::media_library_url() );
		$actions['cb_replace_media'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr__( 'Replace this media file', 'core-blueprint' ),
			esc_html__( 'Replace media', 'core-blueprint' )
		);
		return $actions;
	}

	/**
	 * @param array<string,array<string,mixed>> $form_fields Existing fields.
	 * @return array<string,array<string,mixed>>
	 */
	public static function attachment_field( array $form_fields, \WP_Post $post ): array {
		if ( ! self::can_replace( (int) $post->ID ) ) {
			return $form_fields;
		}

		// The classic attachment edit page has a dedicated submitbox action below;
		// avoid rendering the same feature twice there.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'attachment' === $screen->id ) {
			return $form_fields;
		}

		$return_url = add_query_arg( 'item', (int) $post->ID, self::media_library_url() );
		$url        = self::replace_url( (int) $post->ID, $return_url );
		$form_fields['cb-media-replace'] = [
			'label' => __( 'Replace media', 'core-blueprint' ),
			'input' => 'html',
			'html'  => sprintf(
				'<a class="button-secondary" href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html__( 'Upload replacement', 'core-blueprint' )
			),
			'helps' => __( 'Keeps the attachment ID, filename and URL.', 'core-blueprint' ),
		];
		return $form_fields;
	}

	public static function attachment_submitbox_action( \WP_Post $post ): void {
		if ( ! self::can_replace( (int) $post->ID ) ) {
			return;
		}
		$url = self::replace_url( (int) $post->ID, get_edit_post_link( (int) $post->ID, 'raw' ) ?: self::media_library_url() );
		?>
		<div class="misc-pub-section cb-media-replace-submitbox">
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Replace media file', 'core-blueprint' ); ?></a>
		</div>
		<?php
		$replaced_at = (string) get_post_meta( (int) $post->ID, '_cb_media_replaced_at', true );
		$replaced_by = (int) get_post_meta( (int) $post->ID, '_cb_media_replaced_by', true );
		if ( '' !== $replaced_at ) {
			$user = $replaced_by > 0 ? get_user_by( 'id', $replaced_by ) : false;
			$who  = $user instanceof \WP_User ? $user->display_name : __( 'Unknown user', 'core-blueprint' );
			$time = get_date_from_gmt( $replaced_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
			?>
			<div class="misc-pub-section cb-media-replace-history">
				<?php
				printf(
					/* translators: 1: replacement date/time, 2: user display name */
					esc_html__( 'Last replaced: %1$s by %2$s', 'core-blueprint' ),
					esc_html( $time ),
					esc_html( $who )
				);
				?>
			</div>
			<?php
		}
	}

	public static function render_page(): void {
		if ( ! State::is_enabled() ) {
			wp_die(
				esc_html__( 'Media Replace is disabled. Enable it from the Core Blueprint Dashboard.', 'core-blueprint' ),
				esc_html__( 'Module disabled', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		if ( 0 === $attachment_id ) {
			self::render_overview();
			return;
		}

		if ( ! self::can_replace( $attachment_id ) ) {
			wp_die(
				esc_html__( 'You do not have permission to replace this media item.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		$attachment = get_post( $attachment_id );
		if ( ! ( $attachment instanceof \WP_Post ) || 'attachment' !== $attachment->post_type ) {
			wp_die(
				esc_html__( 'The requested media item no longer exists.', 'core-blueprint' ),
				esc_html__( 'Media not found', 'core-blueprint' ),
				[ 'response' => 404 ]
			);
		}

		$file       = get_attached_file( $attachment_id, true );
		$url        = wp_get_attachment_url( $attachment_id );
		$return_url = isset( $_GET['return'] ) ? self::sanitize_return_url( wp_unslash( (string) $_GET['return'] ) ) : self::media_library_url();
		$notice     = self::pop_notice();
		?>
		<div class="wrap cb-core-wrap cb-media-replace-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Replace media', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Upload a new file for this attachment. Core Blueprint keeps the attachment ID, filename and public URL unchanged.', 'core-blueprint' ); ?></p>

			<?php
			if ( $notice ) {
				echo Notice::render( [
					'variant' => $notice['type'],
					'message' => $notice['message'],
				] );
			}
			?>

			<div class="cb-media-replace-grid">
				<section class="cb-core-section cb-media-replace-section">
					<h2 class="cb-core-section-title"><?php esc_html_e( 'Current attachment', 'core-blueprint' ); ?></h2>
					<div class="cb-media-replace-current">
						<?php if ( wp_attachment_is_image( $attachment_id ) ) : ?>
							<div class="cb-media-replace-preview"><?php echo wp_get_attachment_image( $attachment_id, 'medium', false, [ 'alt' => '' ] ); ?></div>
						<?php else : ?>
							<div class="cb-media-replace-file-icon" aria-hidden="true"><?php echo Icon::render( 'file', [ 'class' => 'cb-media-replace-file-glyph' ] ); ?></div>
						<?php endif; ?>
						<table class="cb-core-kv cb-media-replace-facts">
							<tbody>
								<tr><th scope="row"><?php esc_html_e( 'Title', 'core-blueprint' ); ?></th><td><?php echo esc_html( get_the_title( $attachment_id ) ); ?></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Filename', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( is_string( $file ) ? wp_basename( $file ) : '' ); ?></code></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'File type', 'core-blueprint' ); ?></th><td><?php echo esc_html( (string) $attachment->post_mime_type ); ?></td></tr>
								<?php if ( is_string( $url ) && '' !== $url ) : ?><tr><th scope="row"><?php esc_html_e( 'URL', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( $url ); ?></code></td></tr><?php endif; ?>
							</tbody>
						</table>
					</div>
				</section>

				<section class="cb-core-section cb-media-replace-section">
					<h2 class="cb-core-section-title"><?php esc_html_e( 'Replacement file', 'core-blueprint' ); ?></h2>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data" data-cb-media-replace-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::FORM_ACTION ); ?>" />
						<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
						<input type="hidden" name="return" value="<?php echo esc_attr( $return_url ); ?>" />
						<?php wp_nonce_field( 'cb_core_replace_media_' . $attachment_id ); ?>

						<label class="cb-media-replace-upload">
							<span><?php esc_html_e( 'Choose replacement', 'core-blueprint' ); ?></span>
							<input type="file" name="replacement_file"<?php echo '' !== (string) $attachment->post_mime_type ? ' accept="' . esc_attr( (string) $attachment->post_mime_type ) . '"' : ''; ?> required data-cb-media-replace-input />
						</label>

						<?php if ( wp_attachment_is_image( $attachment_id ) ) : ?>
							<div class="cb-media-replace-preview cb-media-replace-preview--replacement" data-cb-media-replace-preview hidden>
								<img src="" alt="<?php esc_attr_e( 'Replacement preview', 'core-blueprint' ); ?>" data-cb-media-replace-preview-image />
							</div>
						<?php endif; ?>

						<p class="description"><?php esc_html_e( 'The replacement must currently use the same file type as the original. WordPress thumbnails and attachment metadata are regenerated automatically.', 'core-blueprint' ); ?></p>

						<?php
						echo Notice::render( [
							'variant' => Notice::INFO,
							'title'   => __( 'Preserved', 'core-blueprint' ),
							'items'   => [
								__( 'Attachment ID', 'core-blueprint' ),
								__( 'Current filename', 'core-blueprint' ),
								__( 'Current public URL', 'core-blueprint' ),
							],
						] );
						MutationAcknowledgement::render(
							self::ACKNOWLEDGEMENT_FIELD,
							'cb-media-replace-acknowledgement',
							__( "I understand that replacing this media file changes the attachment's stored files and that I am responsible for having a recent backup or other recovery option available.", 'core-blueprint' ),
							__( 'Generated sizes, caches, themes, plugins and external references may affect the result.', 'core-blueprint' )
						);
						?>

						<div class="cb-media-replace-actions">
							<a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $return_url ); ?>"><?php esc_html_e( 'Cancel', 'core-blueprint' ); ?></a>
							<button type="submit" class="button cb-core-button cb-core-button--primary"><?php esc_html_e( 'Replace file', 'core-blueprint' ); ?></button>
						</div>
					</form>
				</section>
			</div>
		</div>
		<?php
	}

	public static function handle_replace(): void {
		if ( ! State::is_enabled() ) {
			wp_die(
				esc_html__( 'Media Replace is disabled.', 'core-blueprint' ),
				esc_html__( 'Module disabled', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$return_url     = isset( $_POST['return'] ) ? self::sanitize_return_url( wp_unslash( (string) $_POST['return'] ) ) : self::media_library_url();

		if ( ! self::can_replace( $attachment_id ) ) {
			wp_die(
				esc_html__( 'You do not have permission to replace this media item.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
		check_admin_referer( 'cb_core_replace_media_' . $attachment_id );

		try {
			MutationAcknowledgement::require_confirmed(
				$_POST[ self::ACKNOWLEDGEMENT_FIELD ] ?? null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above; strict literal confirmation only.
				__( 'Confirm your responsibility for backup and recovery before replacing the media file.', 'core-blueprint' )
			);
		} catch ( \InvalidArgumentException $error ) {
			self::set_notice( 'error', $error->getMessage() );
			wp_safe_redirect( self::replace_url( $attachment_id, $return_url ) );
			exit;
		}

		$upload = isset( $_FILES['replacement_file'] ) && is_array( $_FILES['replacement_file'] )
			? $_FILES['replacement_file']
			: [];

		AuditLog::log( 'media.replace_acknowledged', 'notice', [
			'attachment_id'          => $attachment_id,
			'recovery_responsibility' => true,
		] );

		try {
			$service = new ReplaceService( new PreserveFilenameStrategy() );
			$result  = $service->replace( $attachment_id, $upload );
		} catch ( ReplaceException $e ) {
			$context = [
				'attachment_id' => $attachment_id,
				'reason'        => $e->reason(),
				'strategy'      => 'preserve_filename',
			];
			$previous = $e->getPrevious();
			if ( $previous instanceof \Throwable ) {
				$context['exception_class']   = get_class( $previous );
				$context['exception_message'] = $previous->getMessage();
			}
			AuditLog::log( 'media.replace_failed', 'warning', $context );
			self::set_notice( 'error', $e->getMessage() );
			wp_safe_redirect( self::replace_url( $attachment_id, $return_url ) );
			exit;
		} catch ( \Throwable $e ) {
			AuditLog::log( 'media.replace_failed', 'warning', [
				'attachment_id' => $attachment_id,
				'reason'        => 'unexpected_error',
				'strategy'      => 'preserve_filename',
			] );
			self::set_notice( 'error', __( 'The media replacement failed. The original attachment was kept.', 'core-blueprint' ) );
			wp_safe_redirect( self::replace_url( $attachment_id, $return_url ) );
			exit;
		}

		// The physical transaction is committed now. Post-commit integrations are
		// isolated so a hook failure can never be reported as a rolled-back file.
		AuditLog::log( 'media.file_replaced', 'notice', [
			'attachment_id' => $result['attachment_id'],
			'filename'      => $result['filename'],
			'mime'          => $result['mime'],
			'bytes'         => $result['bytes'],
			'strategy'      => $result['strategy'],
		] );

		try {
			do_action( 'cb_core_media_replaced', $attachment_id, $result );
		} catch ( \Throwable $e ) {
			AuditLog::log( 'media.post_replace_hook_failed', 'warning', [
				'attachment_id' => $attachment_id,
				'error'         => $e->getMessage(),
			] );
		}

		self::set_notice( 'success', __( 'Media file replaced successfully. The attachment ID, filename and URL were preserved.', 'core-blueprint' ) );
		wp_safe_redirect( add_query_arg( 'cb_media_replaced', $attachment_id, $return_url ) );
		exit;
	}

	public static function render_admin_notice(): void {
		if ( empty( $_GET['cb_media_replaced'] ) ) {
			return;
		}
		$attachment_id = absint( $_GET['cb_media_replaced'] );
		if ( $attachment_id <= 0 ) {
			return;
		}
		$notice = self::pop_notice();
		if ( ! $notice ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Cache-bust Media Library attachment previews after a same-URL replacement.
	 *
	 * Media modal/grid data is commonly loaded by a separate AJAX request after
	 * the replacement redirect. Do not depend on a query flag from the original
	 * request: once an attachment has been replaced, its admin preview URLs carry
	 * the stored revision on every wp_prepare_attachment_for_js() response. The
	 * canonical/stored public URL remains unchanged.
	 *
	 * @param array<string,mixed>|false $response
	 * @param array<string,mixed>|false $meta
	 * @return array<string,mixed>|false
	 */
	public static function bust_replaced_preview_cache( array|false $response, \WP_Post $attachment, array|false $meta ): array|false {
		if ( ! is_array( $response ) ) {
			return $response;
		}

		$token = self::replacement_cache_token( (int) $attachment->ID );
		if ( '' === $token ) {
			return $response;
		}

		if ( ! empty( $response['url'] ) && is_string( $response['url'] ) ) {
			$response['url'] = add_query_arg( 'cb-replaced', $token, $response['url'] );
		}
		if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as &$size ) {
				if ( is_array( $size ) && ! empty( $size['url'] ) && is_string( $size['url'] ) ) {
					$size['url'] = add_query_arg( 'cb-replaced', $token, $size['url'] );
				}
			}
			unset( $size );
		}
		return $response;
	}

	private static function replacement_cache_token( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$revision = (string) get_post_meta( $attachment_id, '_cb_media_replace_revision', true );
		if ( '' !== $revision ) {
			return sanitize_key( $revision );
		}

		// v1.9.0-v1.9.2 replacements predate the UUID revision. Keep their admin
		// previews cache-safe by falling back to the recorded replacement time.
		$stamp = (string) get_post_meta( $attachment_id, '_cb_media_replaced_at', true );
		if ( '' === $stamp ) {
			return '';
		}

		$timestamp = strtotime( $stamp . ' UTC' );
		return false !== $timestamp ? (string) $timestamp : sanitize_key( $stamp );
	}

	private static function render_overview(): void {
		?>
		<div class="wrap cb-core-wrap cb-media-replace-wrap cb-media-replace-overview">
			<h1 class="cb-core-title"><?php esc_html_e( 'Media Replace', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Replace files in the WordPress Media Library without changing the attachment identity used by your site.', 'core-blueprint' ); ?></p>

			<section class="cb-core-section cb-media-replace-overview-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Module', 'core-blueprint' ); ?></h2>
				<p class="cb-media-replace-overview-copy"><?php esc_html_e( 'The current replacement method keeps the attachment ID, filename, public URL, and upload location unchanged while WordPress metadata and generated image sizes are rebuilt.', 'core-blueprint' ); ?></p>

				<table class="cb-core-kv cb-media-replace-module-facts">
					<tbody>
						<tr><th scope="row"><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th><td><?php echo Status::render( 'active', __( 'Active', 'core-blueprint' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Method', 'core-blueprint' ); ?></th><td><?php esc_html_e( 'Preserve filename', 'core-blueprint' ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Capability', 'core-blueprint' ); ?></th><td><code><?php echo esc_html( Capabilities::REPLACE_MEDIA ); ?></code></td></tr>
					</tbody>
				</table>

				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice escapes supplied content.
				echo Notice::render( [
					'variant' => Notice::INFO,
					'message' => __( 'A future replacement method can use a new filename and update references; the transaction layer is already designed for that extension, but it is not enabled yet.', 'core-blueprint' ),
					'class'   => 'cb-media-replace-module-note',
				] );
				?>

				<div class="cb-media-replace-overview-actions">
					<a class="button cb-core-button cb-core-button--primary" href="<?php echo esc_url( self::media_library_url() ); ?>"><?php esc_html_e( 'Open Media Library', 'core-blueprint' ); ?></a>
				</div>
			</section>
		</div>
		<?php
	}

	private static function can_replace( int $attachment_id ): bool {
		return $attachment_id > 0 && current_user_can( Capabilities::REPLACE_MEDIA, $attachment_id );
	}

	private static function replace_url( int $attachment_id, string $return_url = '' ): string {
		$args = [
			'page'          => MediaReplacePage::SLUG,
			'attachment_id' => $attachment_id,
		];
		if ( '' !== $return_url ) {
			$args['return'] = self::sanitize_return_url( $return_url );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function media_library_url(): string {
		return admin_url( 'upload.php' );
	}

	private static function sanitize_return_url( string $url ): string {
		$fallback = self::media_library_url();
		$url      = esc_url_raw( $url );
		if ( '' === $url ) {
			return $fallback;
		}
		return wp_validate_redirect( $url, $fallback );
	}

	private static function set_notice( string $type, string $message ): void {
		$type = in_array( $type, [ 'success', 'error', 'warning', 'info' ], true ) ? $type : 'info';
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			[ 'type' => $type, 'message' => sanitize_text_field( $message ) ],
			MINUTE_IN_SECONDS
		);
	}

	/** @return array{type:string,message:string}|null */
	private static function pop_notice(): ?array {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( false === $notice || ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( $key );
		if ( empty( $notice['message'] ) ) {
			return null;
		}
		return [
			'type'    => in_array( (string) ( $notice['type'] ?? '' ), [ 'success', 'error', 'warning', 'info' ], true ) ? (string) $notice['type'] : 'info',
			'message' => (string) $notice['message'],
		];
	}
}
