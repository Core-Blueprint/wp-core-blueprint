<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Fixed;

use CoreBlueprint\Core\PDF\Api\PdfApi;

defined( 'ABSPATH' ) || exit;

final class PdfRenderer {
	public function __construct( private readonly HtmlRenderer $html = new HtmlRenderer() ) {}

	/**
	 * @param array<string,mixed> $page
	 * @param list<RenderFragment> $fragments
	 */
	public function html( array $page, array $fragments, string $locale ): string {
		return $this->html->render( $page, $fragments, $locale );
	}

	/**
	 * @param array<string,mixed> $page
	 * @param list<RenderFragment> $fragments
	 */
	public function render( array $page, array $fragments, string $locale ): string {
		$html = $this->html( $page, $fragments, $locale );
		$normalized_page = Geometry::page(
			[
				'mode'  => Contract::LAYOUT_MODE,
				'units' => Contract::UNITS,
				'page'  => $page,
			]
		);
		if ( null === $normalized_page ) {
			throw new \InvalidArgumentException( 'Invalid Fixed PDF page.' );
		}

		return PdfApi::render(
			$html,
			[
				'paper_size_mm' => [ $normalized_page['width'], $normalized_page['height'] ],
				'default_font'  => 'DejaVu Sans',
			]
		);
	}
}
