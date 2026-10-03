<?php
declare(strict_types=1);
/**
 * Canonical Media Formats definition map.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats;

defined( 'ABSPATH' ) || exit;

final class FormatRegistry {
	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		return [
			'svg' => [
				'label'      => 'SVG',
				'setting'    => 'svg_uploads',
				'mime'       => 'image/svg+xml',
				'available'  => Environment::svg_supported(),
				'processing' => 'sanitized',
				'kind'       => 'vector',
			],
			'webp' => [
				'label'      => 'WebP',
				'setting'    => 'webp_uploads',
				'mime'       => 'image/webp',
				'available'  => Environment::webp_supported(),
				'processing' => Environment::webp_supported() ? 'native' : 'unavailable',
				'kind'       => 'image',
			],
			'avif' => [
				'label'      => 'AVIF',
				'setting'    => 'avif_uploads',
				'mime'       => 'image/avif',
				'available'  => Environment::avif_supported(),
				'processing' => Environment::avif_supported() ? 'native' : 'unavailable',
				'kind'       => 'image',
			],
			'jxl' => [
				'label'      => 'JPEG XL',
				'setting'    => 'jxl_uploads',
				'mime'       => 'image/jxl',
				'available'  => true,
				'processing' => Environment::jxl_processing_supported() ? 'native' : 'upload-only',
				'kind'       => 'experimental',
			],
			'heic' => [
				'label'      => 'HEIC / HEIF',
				'setting'    => 'heic_imports',
				'mime'       => 'image/heic',
				'available'  => Environment::heic_supported(),
				'processing' => Environment::heic_supported() ? 'native' : 'unavailable',
				'kind'       => 'import',
			],
		];
	}
}
