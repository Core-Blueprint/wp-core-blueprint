<?php
declare(strict_types=1);
/**
 * Media Formats admin mutations.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats\Admin;

use CoreBlueprint\Core\MediaFormats\Environment;
use CoreBlueprint\Core\MediaFormats\Settings;
use CoreBlueprint\Core\MediaFormats\State;

defined( 'ABSPATH' ) || exit;

final class Actions {
	public static function boot(): void {
		add_action( 'admin_post_cb_core_media_formats_save', [ __CLASS__, 'save' ] );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Media Formats settings.', 'core-blueprint' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'cb_core_media_formats_save' );

		if ( ! State::is_enabled() ) {
			wp_die(
				esc_html__( 'Media Formats is disabled. Enable it from the Dashboard before changing format settings.', 'core-blueprint' ),
				esc_html__( 'Media Formats disabled', 'core-blueprint' ),
				[ 'response' => 409 ]
			);
		}

		$output = isset( $_POST['output_format'] ) ? sanitize_key( wp_unslash( $_POST['output_format'] ) ) : 'original';
		if ( 'webp' === $output && ! Environment::webp_supported() ) {
			$output = 'original';
		}
		if ( 'avif' === $output && ! Environment::avif_supported() ) {
			$output = 'original';
		}

		$ok = Settings::save( [
			'svg_uploads'   => ! empty( $_POST['svg_uploads'] ) && Environment::svg_supported(),
			'webp_uploads'  => ! empty( $_POST['webp_uploads'] ) && Environment::webp_supported(),
			'avif_uploads'  => ! empty( $_POST['avif_uploads'] ) && Environment::avif_supported(),
			'jxl_uploads'   => ! empty( $_POST['jxl_uploads'] ),
			'heic_imports'  => ! empty( $_POST['heic_imports'] ) && Environment::heic_supported(),
			'output_format' => $output,
		], 'admin:' . get_current_user_id() );

		$url = add_query_arg(
			'media_formats_result',
			$ok ? 'saved' : 'failed',
			admin_url( 'admin.php?page=' . Page::SLUG )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
