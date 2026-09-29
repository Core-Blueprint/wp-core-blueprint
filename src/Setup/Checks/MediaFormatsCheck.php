<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Media Formats policy and environment compatibility.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\MediaFormats\Admin\Page;
use CB\Core\MediaFormats\FormatRegistry;
use CB\Core\MediaFormats\Settings;
use CB\Core\Modules\ActivationRegistry;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class MediaFormatsCheck implements CheckInterface {

	public function id(): string { return 'media-formats'; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return 'Media Formats'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Page::SLUG ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$enabled = ActivationRegistry::is_enabled( 'media-formats' );
			if ( ! $enabled ) {
				return new Evidence(
					Evidence::HEALTH_OK,
					'media-formats.disabled',
					[ 'enabled' => false ],
					[ 'enabled' => false ]
				);
			}

			$settings = Settings::all();
			$formats = FormatRegistry::all();
			$availability = [];
			$unsupported = [];

			foreach ( $formats as $id => $format ) {
				$setting = (string) ( $format['setting'] ?? '' );
				$available = ! empty( $format['available'] );
				$availability[ (string) $id ] = [
					'available'  => $available,
					'processing' => (string) ( $format['processing'] ?? '' ),
				];
				if ( '' !== $setting && ! empty( $settings[ $setting ] ) && ! $available ) {
					$unsupported[] = (string) $id;
				}
			}
			ksort( $availability, SORT_STRING );
			sort( $unsupported, SORT_STRING );

			$output = (string) ( $settings['output_format'] ?? 'original' );
			if ( in_array( $output, [ 'webp', 'avif' ], true ) && empty( $availability[ $output ]['available'] ) ) {
				$unsupported[] = 'output:' . $output;
			}
			$unsupported = array_values( array_unique( $unsupported ) );
			$attention = [] !== $unsupported;

			$policy = $settings;
			unset( $policy['enabled'] );

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'media-formats.unsupported-selection' : 'media-formats.ready',
				[
					'enabled'      => true,
					'policy'       => $policy,
					'availability' => $availability,
					'unsupported'  => $unsupported,
				],
				[
					'enabled'     => true,
					'unsupported' => $unsupported,
					'output'      => $output,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'media-formats.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['enabled'] );
	}
}
