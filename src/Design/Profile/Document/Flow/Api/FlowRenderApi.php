<?php
declare(strict_types=1);
/**
 * Public rendering facade for Core Blueprint Flow documents.
 *
 * External consumers should use this class instead of constructing the internal
 * Flow HTML/PDF renderers directly. Screen preview and paged PDF output share
 * the same typed layout, block and presentation contracts while keeping their
 * target-specific presentation semantics separate.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Design\Profile\Document\Flow\Api;

use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CB\Core\Design\Profile\Document\Flow\PdfRenderer;
use CB\Core\Design\Profile\Document\Flow\Presentation;
use CB\Core\Design\Profile\Document\Flow\RenderBlock;
use CB\Core\PDF\Api\PdfApi;

defined( 'ABSPATH' ) || exit;

final class FlowRenderApi {
	/**
	 * Render a safe, continuous HTML document for an isolated screen preview.
	 *
	 * This target is intended for Designer/document-canvas previews. It preserves
	 * Flow typography, bounded presentation, tables, validated data-URI images
	 * and root layout margins, but does not simulate paged-media pagination or
	 * page counters. PDF remains the authoritative paged output target.
	 *
	 * @param array<string,mixed> $layout
	 * @param list<RenderBlock>    $blocks
	 */
	public static function preview_html( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): string {
		return ( new HtmlRenderer() )->render_preview( $layout, $blocks, $locale, $presentation );
	}

	/**
	 * Render the authoritative paged PDF output for a Flow document.
	 *
	 * @param array<string,mixed> $layout
	 * @param list<RenderBlock>    $blocks
	 */
	public static function pdf( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): string {
		return ( new PdfRenderer() )->render( $layout, $blocks, $locale, $presentation );
	}

	/**
	 * Whether the bundled PDF backend is available in the current runtime.
	 *
	 * Screen preview rendering does not depend on the PDF backend.
	 */
	public static function is_pdf_available(): bool {
		return PdfApi::is_available();
	}

	private function __construct() {}
}
