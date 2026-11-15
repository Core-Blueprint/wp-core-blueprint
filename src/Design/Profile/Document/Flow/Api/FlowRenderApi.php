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

namespace CoreBlueprint\Core\Design\Profile\Document\Flow\Api;

use CoreBlueprint\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Flow\PdfRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Flow\Presentation;
use CoreBlueprint\Core\Design\Profile\Document\Flow\RenderBlock;
use CoreBlueprint\Core\PDF\Api\PdfApi;

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
	 * Optional preview regions map semantic Designer selection IDs to one or more
	 * Flow render paths. Integer entries keep the original top-level block-index
	 * shorthand; nested paths use ordered integer lists that traverse containers
	 * and column compositions. Region metadata exists only in the screen preview
	 * target and never changes paged/PDF output.
	 *
	 * @param array<string,mixed>                 $layout
	 * @param list<RenderBlock>                    $blocks
	 * @param array<string,list<int|list<int>>>    $preview_regions
	 */
	public static function preview_html(
		array $layout,
		array $blocks,
		string $locale,
		?Presentation $presentation = null,
		array $preview_regions = []
	): string {
		return ( new HtmlRenderer() )->render_preview( $layout, $blocks, $locale, $presentation, $preview_regions );
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
	 * Return the authoritative paged PDF plus true page count.
	 *
	 * This does not expose DOM page boundaries, element positions or page
	 * targets. Consumers cannot activate the Designer's Page Navigation from
	 * this count alone while their preview remains continuous HTML.
	 *
	 * @param array<string,mixed> $layout
	 * @param list<RenderBlock> $blocks
	 * @return array{pdf:string,page_count:int}
	 */
	public static function pdf_with_page_count( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): array {
		return ( new PdfRenderer() )->render_with_page_count( $layout, $blocks, $locale, $presentation );
	}

	/**
	 * Opt-in trusted PDF page projection in the same single Dompdf pass.
	 *
	 * This returns actual PDF bytes, physical page count/dimensions (PDF pts)
	 * and bounded semantic rectangles. It does not create a browser viewer or
	 * grant document mutation/selection authority. Consumer preview regions
	 * are resolved by the same validated Flow render paths as screen preview.
	 *
	 * @param array<string,mixed> $layout
	 * @param list<RenderBlock> $blocks
	 * @param array<string,list<int|list<int>>> $preview_regions
	 * @return array{pdf:string,page_count:int,page_width_pt:float,page_height_pt:float,regions:array<string,mixed>}
	 */
	public static function pdf_with_page_regions(
		array $layout,
		array $blocks,
		string $locale,
		?Presentation $presentation = null,
		array $preview_regions = []
	): array {
		return ( new PdfRenderer() )->render_with_page_regions(
			$layout, $blocks, $locale, $presentation, $preview_regions
		);
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
