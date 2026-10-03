<?php
declare(strict_types=1);
/**
 * Native WordPress admin integration for package downloads.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\PackageDownload;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\UI\Icon;
use CoreBlueprint\Core\UI\Notice;

defined( 'ABSPATH' ) || exit;

final class AdminIntegration {

	private const ACTION = 'cb_core_download_package';

	public static function init_screen(): void {
		add_filter( 'plugin_action_links', [ __CLASS__, 'plugin_action_link' ], 20, 4 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_theme_assets' ] );
	}

	public static function init_admin_post(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_download' ] );
	}

	/**
	 * @param string[]            $actions Existing plugin actions.
	 * @param string              $plugin_file Plugin basename.
	 * @param array<string,mixed> $plugin_data Parsed plugin headers.
	 * @param string              $context Current list-table context.
	 * @return string[]
	 */
	public static function plugin_action_link( array $actions, string $plugin_file, array $plugin_data, string $context ): array {
		unset( $plugin_data, $context );

		if ( ! current_user_can( 'install_plugins' ) ) {
			return $actions;
		}

		$actions['cb_download_plugin'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::download_url( 'plugin', $plugin_file ) ),
			esc_attr__( 'Download this plugin as an installable ZIP archive', 'core-blueprint' ),
			esc_html__( 'Download', 'core-blueprint' )
		);
		return $actions;
	}

	public static function enqueue_theme_assets( string $hook ): void {
		if ( 'themes.php' !== $hook || ! current_user_can( 'install_themes' ) ) {
			return;
		}

		$urls = [];
		foreach ( wp_get_themes( [ 'allowed' => true ] ) as $stylesheet => $theme ) {
			unset( $theme );
			$stylesheet = (string) $stylesheet;
			$urls[ $stylesheet ] = self::download_url( 'theme', $stylesheet );
		}

		wp_enqueue_script_module(
			'@cb-core/package-download',
			CB_CORE_URL . 'assets/js/features/package-download.js',
			[],
			CB_CORE_VERSION
		);

		add_filter( 'script_module_data_@cb-core/package-download', static function ( array $existing ) use ( $urls ): array {
			return array_merge( $existing, [
				'themeUrls' => $urls,
				'i18n'      => [
					'download' => __( 'Download', 'core-blueprint' ),
				],
			] );
		} );
	}

	public static function handle_download(): void {
		if ( ! State::is_enabled() ) {
			wp_die(
				esc_html__( 'Package Downloads is disabled.', 'core-blueprint' ),
				esc_html__( 'Module disabled', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '';
		$id   = isset( $_GET['package'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['package'] ) ) : '';

		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || '' === $id ) {
			wp_die(
				esc_html__( 'The requested package download is invalid.', 'core-blueprint' ),
				esc_html__( 'Invalid download', 'core-blueprint' ),
				[ 'response' => 400 ]
			);
		}

		if ( 'plugin' === $type && ! current_user_can( 'install_plugins' ) ) {
			self::forbidden();
		}
		if ( 'theme' === $type && ! current_user_can( 'install_themes' ) ) {
			self::forbidden();
		}

		check_admin_referer( self::nonce_action( $type, $id ) );

		$service = new ArchiveService();
		$archive = '';
		$slug    = '';

		try {
			if ( 'plugin' === $type ) {
				[ $archive, $slug ] = self::build_plugin_archive( $service, $id );
			} else {
				[ $archive, $slug ] = self::build_theme_archive( $service, $id );
			}

			$bytes = $service->stream_and_delete( $archive, $slug . '.zip' );
			$archive = '';

			// The response body has already been streamed. Audit failures must never
			// append an error page to an otherwise valid ZIP download.
			self::audit_safely( 'package.' . $type . '_downloaded', 'info', [
				'package' => $id,
				'slug'    => $slug,
				'bytes'   => $bytes,
			] );
			exit;
		} catch ( \Throwable $e ) {
			if ( '' !== $archive && is_file( $archive ) ) {
				wp_delete_file( $archive );
			}

			self::audit_safely( 'package.download_failed', 'warning', [
				'type'      => $type,
				'package'   => $id,
				'exception' => get_class( $e ),
				'message'   => $e->getMessage(),
			] );

			// If streaming had already started, emitting wp_die() would corrupt the
			// binary response even further. End the request cleanly instead.
			if ( headers_sent() ) {
				exit;
			}

			wp_die(
				esc_html( $e->getMessage() ),
				esc_html__( 'Package download failed', 'core-blueprint' ),
				[ 'response' => 500 ]
			);
		}
	}

	public static function render_overview(): void {
		?>
		<div class="wrap cb-core-wrap cb-core-wrap--narrow cb-package-download-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Package Downloads', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Download installed WordPress plugins and themes as installable ZIP archives without modifying their source directories.', 'core-blueprint' ); ?></p>

			<section class="cb-core-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Download locations', 'core-blueprint' ); ?></h2>
				<div class="cb-core-tab-cards">
					<?php if ( current_user_can( 'install_plugins' ) ) : ?>
						<a class="cb-core-tab-card" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
							<span class="cb-core-tab-card__icon" aria-hidden="true"><?php echo Icon::render( 'file' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="cb-core-tab-card__body">
								<span class="cb-core-tab-card__label"><?php esc_html_e( 'Plugins', 'core-blueprint' ); ?></span>
								<span class="cb-core-tab-card__desc"><?php esc_html_e( 'Use Download beside any installed plugin', 'core-blueprint' ); ?></span>
							</span>
							<span class="cb-core-tab-card__arrow" aria-hidden="true"><?php echo Icon::render( 'chevron-right', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</a>
					<?php endif; ?>

					<?php if ( current_user_can( 'install_themes' ) ) : ?>
						<a class="cb-core-tab-card" href="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>">
							<span class="cb-core-tab-card__icon" aria-hidden="true"><?php echo Icon::render( 'grid-2x2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="cb-core-tab-card__body">
								<span class="cb-core-tab-card__label"><?php esc_html_e( 'Themes', 'core-blueprint' ); ?></span>
								<span class="cb-core-tab-card__desc"><?php esc_html_e( 'Use Download on any installed theme card', 'core-blueprint' ); ?></span>
							</span>
							<span class="cb-core-tab-card__arrow" aria-hidden="true"><?php echo Icon::render( 'chevron-right', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</a>
					<?php endif; ?>
				</div>
			</section>

			<section class="cb-core-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Archive policy', 'core-blueprint' ); ?></h2>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice escapes supplied content.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
				echo Notice::render( [
					'variant' => Notice::INFO,
					'title'   => __( 'Safe temporary archives', 'core-blueprint' ),
					'message' => __( 'Core Blueprint builds each ZIP in the WordPress temporary directory, streams it once, and removes it immediately. Plugin and theme source directories are never used for temporary ZIP files.', 'core-blueprint' ),
					'items'   => [
						__( 'Directory packages keep their existing root folder. Single-file plugins are wrapped in a matching root folder inside the archive so the ZIP remains installable.', 'core-blueprint' ),
					],
				] );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</section>
		</div>
		<?php
	}

	/** @return array{0:string,1:string} */
	private static function build_plugin_archive( ArchiveService $service, string $plugin_file ): array {
		if ( ! self::plugin_exists( $plugin_file ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'The requested plugin is not installed.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$dirname = dirname( $plugin_file );
		if ( '.' === $dirname ) {
			$source = WP_PLUGIN_DIR . '/' . $plugin_file;
			$slug   = pathinfo( $plugin_file, PATHINFO_FILENAME );
			return [ $service->create_from_file( $source, WP_PLUGIN_DIR, $slug ), $slug ];
		}

		$slug   = wp_basename( $dirname );
		$source = WP_PLUGIN_DIR . '/' . $dirname;
		return [ $service->create_from_directory( $source, WP_PLUGIN_DIR, $slug ), $slug ];
	}

	/** @return array{0:string,1:string} */
	private static function build_theme_archive( ArchiveService $service, string $stylesheet ): array {
		$themes = wp_get_themes( [ 'allowed' => true ] );
		if ( ! isset( $themes[ $stylesheet ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'The requested theme is not installed or is not available to this site.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$root   = get_theme_root( $stylesheet );
		$source = trailingslashit( $root ) . $stylesheet;
		$slug   = wp_basename( $stylesheet );
		return [ $service->create_from_directory( $source, $root, $slug ), $slug ];
	}

	private static function plugin_exists( string $plugin_file ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return isset( get_plugins()[ $plugin_file ] );
	}

	private static function download_url( string $type, string $package ): string {
		$url = add_query_arg( [
			'action'  => self::ACTION,
			'type'    => $type,
			'package' => $package,
		], admin_url( 'admin-post.php' ) );

		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( self::nonce_action( $type, $package ) ),
			$url
		);
	}

	private static function nonce_action( string $type, string $package ): string {
		return self::ACTION . ':' . $type . ':' . $package;
	}

	/**
	 * Audit package-download events without allowing logging failures to break
	 * an admin-post response, especially after binary streaming has started.
	 *
	 * @param array<string,mixed> $context
	 */
	private static function audit_safely( string $event, string $severity, array $context ): void {
		try {
			AuditLog::log( $event, $severity, $context );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Core Blueprint package-download audit failure: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only fallback after AuditLog itself failed.
			}
		}
	}

	private static function forbidden(): void {
		wp_die(
			esc_html__( 'You do not have permission to download this package.', 'core-blueprint' ),
			esc_html__( 'Forbidden', 'core-blueprint' ),
			[ 'response' => 403 ]
		);
	}
}
