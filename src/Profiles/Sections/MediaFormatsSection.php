<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\MediaFormats\Settings;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class MediaFormatsSection extends ExactSection {
	public function id(): string { return 'media-formats'; }
	public function label(): string { return __( 'Media Formats', 'core-blueprint' ); }
	public function description(): string { return __( 'Image upload and generated-image format policy. Activation is applied separately at the end of the Profile transaction.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		$current = Settings::all();
		unset( $current['enabled'] );
		return $current;
	}

	public function normalize( array $incoming ): array {
		$allowed = [ 'svg_uploads', 'webp_uploads', 'avif_uploads', 'jxl_uploads', 'heic_imports', 'output_format' ];
		SchemaGuard::exact_keys( $incoming, $allowed, 'Media Formats' );
		$output = SchemaGuard::string( $incoming['output_format'] ?? null, 'Media Formats output format' );
		if ( ! in_array( $output, [ 'original', 'webp', 'avif' ], true ) ) {
			throw new \InvalidArgumentException( __( 'The profile contains an invalid generated image format.', 'core-blueprint' ) );
		}
		return [
			'svg_uploads'   => SchemaGuard::bool( $incoming['svg_uploads'] ?? null, 'Media Formats SVG uploads' ),
			'webp_uploads'  => SchemaGuard::bool( $incoming['webp_uploads'] ?? null, 'Media Formats WebP uploads' ),
			'avif_uploads'  => SchemaGuard::bool( $incoming['avif_uploads'] ?? null, 'Media Formats AVIF uploads' ),
			'jxl_uploads'   => SchemaGuard::bool( $incoming['jxl_uploads'] ?? null, 'Media Formats JPEG XL uploads' ),
			'heic_imports'  => SchemaGuard::bool( $incoming['heic_imports'] ?? null, 'Media Formats HEIC imports' ),
			'output_format' => $output,
		];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		if ( ! Settings::save( $incoming, $actor ) ) {
			$current = $this->export();
			if ( $current !== $incoming ) {
				throw new \RuntimeException( __( 'Could not apply the Media Formats profile settings.', 'core-blueprint' ) );
			}
		}
	}
}
