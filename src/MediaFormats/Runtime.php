<?php
declare(strict_types=1);
/**
 * Media Formats upload/runtime policy.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\MediaFormats;

use CB\Core\Log\AuditLog;
use CB\Core\MediaFormats\Svg\Metadata as SvgMetadata;
use CB\Core\MediaFormats\Svg\Sanitizer as SvgSanitizer;

defined( 'ABSPATH' ) || exit;

final class Runtime {
	public static function boot(): void {
		add_filter( 'upload_mimes', [ __CLASS__, 'filter_upload_mimes' ], 20, 2 );
		add_filter( 'wp_handle_upload_prefilter', [ __CLASS__, 'prefilter_upload' ], 20 );
		add_filter( 'wp_handle_sideload_prefilter', [ __CLASS__, 'prefilter_upload' ], 20 );
		add_filter( 'wp_check_filetype_and_ext', [ __CLASS__, 'filter_filetype_and_ext' ], 20, 5 );
		add_filter( 'image_editor_output_format', [ __CLASS__, 'filter_output_format' ], 20, 1 );
		SvgMetadata::boot();
	}

	/** @param array<string,string> $mimes @return array<string,string> */
	public static function filter_upload_mimes( array $mimes, $user = null ): array {
		unset( $user );
		$settings = Settings::all();
		if ( ! State::is_enabled() ) {
			return $mimes;
		}

		self::set_mime( $mimes, 'webp', 'image/webp', $settings['webp_uploads'] && Environment::webp_supported() );
		self::set_mime( $mimes, 'avif', 'image/avif', $settings['avif_uploads'] && Environment::avif_supported() );
		self::set_mime( $mimes, 'jxl', 'image/jxl', $settings['jxl_uploads'] );

		self::remove_mime_values( $mimes, [ 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' ] );
		if ( $settings['heic_imports'] && Environment::heic_supported() ) {
			$mimes['heic'] = 'image/heic';
			$mimes['heif'] = 'image/heif';
		}

		self::set_mime(
			$mimes,
			'svg',
			'image/svg+xml',
			$settings['svg_uploads'] && Environment::svg_supported() && current_user_can( Capabilities::UPLOAD_SVG )
		);

		return $mimes;
	}

	/** @param array<string,mixed> $file @return array<string,mixed> */
	public static function prefilter_upload( array $file ): array {
		if ( ! State::is_enabled() ) {
			return $file;
		}
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$settings = Settings::all();

		if ( 'svg' === $ext ) {
			if ( ! $settings['svg_uploads'] || ! current_user_can( Capabilities::UPLOAD_SVG ) ) {
				$file['error'] = __( 'SVG uploads are not enabled for your account.', 'core-blueprint' );
				return $file;
			}
			$result = SvgSanitizer::sanitize_file( $tmp );
			if ( is_wp_error( $result ) ) {
				$file['error'] = $result->get_error_message();
				self::audit_svg( 'media_formats_svg_rejected', 'warning', $name, $result->get_error_code() );
				return $file;
			}
			self::audit_svg( 'media_formats_svg_sanitized', 'info', $name );
		}

		if ( 'jxl' === $ext && $settings['jxl_uploads'] && ! self::is_jxl_file( $tmp ) ) {
			$file['error'] = __( 'The uploaded file does not contain a valid JPEG XL signature.', 'core-blueprint' );
		}

		return $file;
	}

	/** @param array<string,mixed> $data @param array<string,string>|null $mimes @return array<string,mixed> */
	public static function filter_filetype_and_ext( array $data, string $file, string $filename, $mimes = null, $real_mime = false ): array {
		unset( $mimes, $real_mime );
		if ( ! State::is_enabled() ) {
			return $data;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$settings = Settings::all();

		if ( 'svg' === $ext && $settings['svg_uploads'] && current_user_can( Capabilities::UPLOAD_SVG ) && SvgSanitizer::is_svg_file( $file ) ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
			$data['proper_filename'] = false;
		}
		if ( 'jxl' === $ext && $settings['jxl_uploads'] && self::is_jxl_file( $file ) ) {
			$data['ext']  = 'jxl';
			$data['type'] = 'image/jxl';
			$data['proper_filename'] = false;
		}
		return $data;
	}

	/** @param array<string,string> $formats @return array<string,string> */
	public static function filter_output_format( array $formats ): array {
		if ( ! State::is_enabled() ) {
			return $formats;
		}
		$setting = Settings::all()['output_format'];
		$target = '';
		if ( 'webp' === $setting && Environment::webp_supported() ) {
			$target = 'image/webp';
		} elseif ( 'avif' === $setting && Environment::avif_supported() ) {
			$target = 'image/avif';
		}
		if ( '' === $target ) {
			return $formats;
		}
		foreach ( [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ] as $source ) {
			if ( $source !== $target ) {
				$formats[ $source ] = $target;
			}
		}
		return $formats;
	}

	/** @param array<string,string> $mimes */
	private static function set_mime( array &$mimes, string $extension, string $mime, bool $enabled ): void {
		self::remove_mime_values( $mimes, [ $mime ] );
		if ( $enabled ) {
			$mimes[ $extension ] = $mime;
		}
	}

	/** @param array<string,string> $mimes @param string[] $values */
	private static function remove_mime_values( array &$mimes, array $values ): void {
		foreach ( $mimes as $extension => $mime ) {
			if ( in_array( strtolower( (string) $mime ), $values, true ) ) {
				unset( $mimes[ $extension ] );
			}
		}
	}

	private static function is_jxl_file( string $file ): bool {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		$header = file_get_contents( $file, false, null, 0, 12 );
		if ( ! is_string( $header ) ) {
			return false;
		}
		return str_starts_with( $header, "\xFF\x0A" )
			|| str_starts_with( $header, "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A" );
	}

	private static function audit_svg( string $event, string $severity, string $filename, string $reason = '' ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}
		$context = [ 'file' => sanitize_file_name( wp_basename( $filename ) ) ];
		if ( '' !== $reason ) {
			$context['reason'] = sanitize_key( $reason );
		}
		AuditLog::log( $event, $severity, $context );
	}
}
