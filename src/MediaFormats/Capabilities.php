<?php
declare(strict_types=1);
/**
 * Media Formats capability metadata.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\MediaFormats;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const UPLOAD_SVG = 'cb_upload_svg';

	/** @param array<string,array<string,mixed>> $catalog */
	public static function register_catalog( array $catalog ): array {
		$catalog[ self::UPLOAD_SVG ] = [
			'label'       => __( 'Upload sanitized SVG files', 'core-blueprint' ),
			'group'       => __( 'Core Blueprint', 'core-blueprint' ),
			'source'      => 'Core Blueprint',
			'description' => __( 'Upload SVG files when Media Formats is enabled. Every SVG is sanitized before WordPress stores it.', 'core-blueprint' ),
		];
		return $catalog;
	}
}
