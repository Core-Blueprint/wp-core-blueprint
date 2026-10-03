<?php
declare(strict_types=1);
/**
 * Public integration facade for Core Blueprint PDF rendering.
 *
 * External Core Blueprint plugins should use this class instead of constructing
 * the renderer directly. Dompdf remains an internal implementation detail of
 * Core Blueprint Base.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\PDF\Api;

use CoreBlueprint\Core\PDF\Renderer;
use CoreBlueprint\Core\PDF\RendererException;

defined( 'ABSPATH' ) || exit;

final class PdfApi {

	/**
	 * Whether the bundled PDF renderer is available in the current runtime.
	 */
	public static function is_available(): bool {
		return Renderer::is_available();
	}

	/**
	 * Render a complete HTML document to an in-memory PDF binary.
	 *
	 * Supported renderer options currently include:
	 * - paper_size: named size such as A4 or Letter.
	 * - paper_size_mm: final custom [width, height] dimensions in millimetres.
	 * - orientation: portrait or landscape for named paper sizes.
	 * - default_font: default font family.
	 * - is_html5_parser: whether Dompdf's HTML5 parser is enabled.
	 *
	 * When paper_size_mm is supplied its dimensions are authoritative and
	 * orientation is ignored. Dompdf's native point-array paper contract remains
	 * an internal Base implementation detail.
	 *
	 * Permanent storage, filenames, downloads and caller-specific logging are
	 * intentionally outside this API. Security-sensitive Dompdf options such as
	 * remote resources, embedded PHP and JavaScript remain hard-disabled by the
	 * underlying Renderer and cannot be enabled by callers.
	 *
	 * @param string               $html    Complete HTML document.
	 * @param array<string,mixed>  $options Renderer options.
	 * @return string PDF binary beginning with the %PDF- signature.
	 *
	 * @throws RendererException When the renderer is unavailable or rendering fails.
	 */
	public static function render( string $html, array $options = [] ): string {
		return ( new Renderer() )->render( $html, $options );
	}
}
