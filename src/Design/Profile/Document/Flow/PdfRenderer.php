<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Flow;

use CoreBlueprint\Core\PDF\Api\PdfApi;

defined( 'ABSPATH' ) || exit;

final class PdfRenderer {
	public function __construct( private readonly HtmlRenderer $html = new HtmlRenderer() ) {}

	/** @param array<string,mixed> $layout @param list<RenderBlock> $blocks */
	public function html( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): string {
		return $this->html->render( $layout, $blocks, $locale, $presentation );
	}

	/** @param array<string,mixed> $layout @param list<RenderBlock> $blocks */
	public function render( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): string {
		$page = Layout::page( $layout );
		if ( null === $page ) {
			throw new \InvalidArgumentException( 'Invalid Flow PDF page.' );
		}
		return PdfApi::render(
			$this->html( $layout, $blocks, $locale, $presentation ),
			[
				'paper_size_mm' => [ $page['width'], $page['height'] ],
				'default_font' => 'DejaVu Sans',
			]
		);
	}

	/**
	 * One physical PDF render with its authoritative Dompdf page count.
	 * The count does NOT describe HTML screen-region page boundaries.
	 *
	 * @param array<string,mixed> $layout
	 * @param list<RenderBlock> $blocks
	 * @return array{pdf:string,page_count:int}
	 */
	public function render_with_page_count( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): array {
		$page = Layout::page( $layout );
		if ( null === $page ) {
			throw new \InvalidArgumentException( 'Invalid Flow PDF page.' );
		}
		return PdfApi::render_with_page_count(
			$this->html( $layout, $blocks, $locale, $presentation ),
			[
				'paper_size_mm' => [ $page['width'], $page['height'] ],
				'default_font' => 'DejaVu Sans',
			]
		);
	}
}
