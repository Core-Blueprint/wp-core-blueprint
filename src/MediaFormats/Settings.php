<?php
declare(strict_types=1);
/**
 * Media Formats settings repository.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Settings {
	private const OPTION = 'cb_core_media_formats';

	/** @return array{enabled:bool,svg_uploads:bool,webp_uploads:bool,avif_uploads:bool,jxl_uploads:bool,heic_imports:bool,output_format:string} */
	public static function defaults(): array {
		return [
			'enabled'      => false,
			'svg_uploads'  => false,
			'webp_uploads' => true,
			'avif_uploads' => true,
			'jxl_uploads'  => false,
			'heic_imports' => false,
			'output_format'=> 'original',
		];
	}

	/** @return array{enabled:bool,svg_uploads:bool,webp_uploads:bool,avif_uploads:bool,jxl_uploads:bool,heic_imports:bool,output_format:string} */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return self::normalize( array_merge( self::defaults(), $stored ) );
	}

	/** @param array<string,mixed> $input */
	public static function save( array $input, string $actor = 'unknown' ): bool {
		$before = self::all();
		$after  = self::normalize( array_merge( $before, $input ) );
		$after['enabled'] = $before['enabled'];

		if ( $before === $after ) {
			return true;
		}

		$result = update_option( self::OPTION, $after, false );
		if ( ! $result ) {
			return false;
		}

		if ( class_exists( AuditLog::class ) ) {
			$changed = [];
			foreach ( $after as $key => $value ) {
				if ( 'enabled' !== $key && ( $before[ $key ] ?? null ) !== $value ) {
					$changed[] = $key;
				}
			}
			AuditLog::log( 'media_formats_settings_changed', 'notice', [
				'actor'   => $actor,
				'changed' => implode( ', ', $changed ),
			] );
		}

		return true;
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$settings = self::all();
		if ( $settings['enabled'] === $enabled ) {
			return;
		}

		$settings['enabled'] = $enabled;
		if ( ! update_option( self::OPTION, $settings, false ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'The Media Formats module state could not be saved.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'media_formats_subsystem_enabled' : 'media_formats_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}
	}

	/** @param array<string,mixed> $settings */
	private static function normalize( array $settings ): array {
		$output_format = sanitize_key( (string) ( $settings['output_format'] ?? 'original' ) );
		if ( ! in_array( $output_format, [ 'original', 'webp', 'avif' ], true ) ) {
			$output_format = 'original';
		}

		return [
			'enabled'       => ! empty( $settings['enabled'] ),
			'svg_uploads'   => ! empty( $settings['svg_uploads'] ),
			'webp_uploads'  => ! empty( $settings['webp_uploads'] ),
			'avif_uploads'  => ! empty( $settings['avif_uploads'] ),
			'jxl_uploads'   => ! empty( $settings['jxl_uploads'] ),
			'heic_imports'  => ! empty( $settings['heic_imports'] ),
			'output_format' => $output_format,
		];
	}
}
