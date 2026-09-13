<?php
declare(strict_types=1);

namespace CB\Core\Design\Profile\Document\Flow;

defined( 'ABSPATH' ) || exit;

final class HtmlRenderer {
	/** @param array<string,mixed> $layout @param list<RenderBlock> $blocks */
	public function render( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): string {
		if ( ! Layout::matches_contract( $layout ) ) {
			throw new \InvalidArgumentException( 'Flow rendering requires the exact root-owned Flow layout contract.' );
		}
		$page = Layout::page( $layout );
		$margins = Layout::margins( $layout );
		if ( null === $page || null === $margins || ! Layout::has_content_area( $page, $margins ) ) {
			throw new \InvalidArgumentException( 'Invalid Flow render layout.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $locale ) ) {
			throw new \InvalidArgumentException( 'Flow rendering requires an explicit valid locale.' );
		}
		foreach ( $blocks as $block ) {
			if ( ! $block instanceof RenderBlock ) { throw new \InvalidArgumentException( 'Flow rendering accepts typed render blocks only.' ); }
		}

		$lang = str_replace( '_', '-', $locale );
		$body = implode( '', array_map( fn ( RenderBlock $block ): string => $this->block( $block ), $blocks ) );
		$page_size = self::number( $page['width'] ) . 'mm ' . self::number( $page['height'] ) . 'mm';
		$margin = implode( ' ', [ self::number( $margins['top'] ) . 'mm', self::number( $margins['right'] ) . 'mm', self::number( $margins['bottom'] ) . 'mm', self::number( $margins['left'] ) . 'mm' ] );
		$accent = ( $presentation ?? Presentation::defaults() )->accent();

		return '<!doctype html><html lang="' . self::escape( $lang ) . '"><head><meta charset="utf-8"><style>'
			. '@page{size:' . $page_size . ';margin:' . $margin . ';}'
			. 'html,body{margin:0;padding:0;}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.4;color:#111;}'
			. '.cb-flow-block{box-sizing:border-box;}.cb-flow-image img{display:block;max-width:100%;height:auto;border:0;}'
			. '.cb-flow-heading h1,.cb-flow-heading h2,.cb-flow-heading h3{margin:0;padding:0;line-height:1.2;}'
			. '.cb-flow-heading--title{font-size:22pt;font-weight:700;color:' . self::escape( $accent ) . ';}'
			. '.cb-flow-heading--section{font-size:11pt;font-weight:700;color:#111;}'
			. '.cb-flow-heading--subsection{font-size:9.5pt;font-weight:700;color:#333;}'
			. '.cb-flow-columns-table{width:100%;border-collapse:collapse;table-layout:fixed;}.cb-flow-columns-table>tbody>tr>td{border:0;padding:0 6pt;vertical-align:top;}'
			. '.cb-flow-columns-table>tbody>tr>td:first-child{padding-left:0;}.cb-flow-columns-table>tbody>tr>td:last-child{padding-right:0;}'
			. '.cb-flow-table{width:100%;border-collapse:collapse;}.cb-flow-table th,.cb-flow-table td{padding:4pt;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;}'
			. '.cb-flow-table th{color:' . self::escape( $accent ) . ';border-bottom-color:' . self::escape( $accent ) . ';}'
			. '.cb-flow-page-footer{position:fixed;left:0;right:0;bottom:-10mm;border-top:1px solid #ddd;padding-top:2mm;font-size:8pt;color:#666;}'
			. '.cb-flow-page-footer table{width:100%;border-collapse:collapse;}.cb-flow-page-footer td{border:0;padding:0;vertical-align:top;}'
			. '.cb-flow-page-footer__page{text-align:right;white-space:nowrap;}.cb-flow-page-number::before{content:counter(page);}'
			. '</style></head><body>' . $body . '</body></html>';
	}

	/**
	 * Internal continuous-screen target used by the public Flow render facade.
	 *
	 * PDF pagination and page counters are deliberately not simulated here.
	 * Consumers must use the public FlowRenderApi rather than this renderer.
	 *
	 * @internal
	 * @param array<string,mixed> $layout
	 * @param list<RenderBlock>    $blocks
	 */
	public function render_preview( array $layout, array $blocks, string $locale, ?Presentation $presentation = null ): string {
		if ( ! Layout::matches_contract( $layout ) ) {
			throw new \InvalidArgumentException( 'Flow rendering requires the exact root-owned Flow layout contract.' );
		}
		$page = Layout::page( $layout );
		$margins = Layout::margins( $layout );
		if ( null === $page || null === $margins || ! Layout::has_content_area( $page, $margins ) ) {
			throw new \InvalidArgumentException( 'Invalid Flow render layout.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $locale ) ) {
			throw new \InvalidArgumentException( 'Flow rendering requires an explicit valid locale.' );
		}
		foreach ( $blocks as $block ) {
			if ( ! $block instanceof RenderBlock ) { throw new \InvalidArgumentException( 'Flow rendering accepts typed render blocks only.' ); }
		}

		$lang = str_replace( '_', '-', $locale );
		$body = implode( '', array_map( fn ( RenderBlock $block ): string => $this->block( $block ), $blocks ) );
		$width = self::number( $page['width'] );
		$height = self::number( $page['height'] );
		$padding = implode( ' ', [ self::number( $margins['top'] ) . 'mm', self::number( $margins['right'] ) . 'mm', self::number( $margins['bottom'] ) . 'mm', self::number( $margins['left'] ) . 'mm' ] );
		$accent = ( $presentation ?? Presentation::defaults() )->accent();

		return '<!doctype html><html lang="' . self::escape( $lang ) . '"><head><meta charset="utf-8">'
			. '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; img-src data:; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;;">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><style>'
			. 'html,body{margin:0;padding:0;background:#fff;}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.4;color:#111;}'
			. '.cb-flow-preview-page{box-sizing:border-box;width:100%;max-width:' . $width . 'mm;min-height:' . $height . 'mm;margin:0 auto;padding:' . $padding . ';background:#fff;}'
			. '.cb-flow-preview-page .cb-flow-block{page-break-before:auto!important;page-break-after:auto!important;page-break-inside:auto!important;break-before:auto!important;break-after:auto!important;break-inside:auto!important;}'
			. '.cb-flow-block{box-sizing:border-box;}.cb-flow-image img{display:block;max-width:100%;height:auto;border:0;}'
			. '.cb-flow-heading h1,.cb-flow-heading h2,.cb-flow-heading h3{margin:0;padding:0;line-height:1.2;}'
			. '.cb-flow-heading--title{font-size:22pt;font-weight:700;color:' . self::escape( $accent ) . ';}'
			. '.cb-flow-heading--section{font-size:11pt;font-weight:700;color:#111;}'
			. '.cb-flow-heading--subsection{font-size:9.5pt;font-weight:700;color:#333;}'
			. '.cb-flow-columns-table{width:100%;border-collapse:collapse;table-layout:fixed;}.cb-flow-columns-table>tbody>tr>td{border:0;padding:0 6pt;vertical-align:top;}'
			. '.cb-flow-columns-table>tbody>tr>td:first-child{padding-left:0;}.cb-flow-columns-table>tbody>tr>td:last-child{padding-right:0;}'
			. '.cb-flow-table{width:100%;border-collapse:collapse;}.cb-flow-table th,.cb-flow-table td{padding:4pt;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;}'
			. '.cb-flow-table th{color:' . self::escape( $accent ) . ';border-bottom-color:' . self::escape( $accent ) . ';}'
			. '.cb-flow-page-footer{position:static;border-top:1px solid #ddd;margin-top:8mm;padding-top:2mm;font-size:8pt;color:#666;}'
			. '.cb-flow-page-footer table{width:100%;border-collapse:collapse;}.cb-flow-page-footer td{border:0;padding:0;vertical-align:top;}'
			. '.cb-flow-page-footer__page{display:none;}'
			. '</style></head><body><div class="cb-flow-preview-page" data-cb-flow-preview>' . $body . '</div></body></html>';
	}

	private function block( RenderBlock $block ): string {
		if ( 'page_footer' === $block->type() ) {
			/** @var array{left_text:string,page_label:string,show_page_number:bool} $footer */
			$footer = $block->payload();
			$html = '<div class="cb-flow-page-footer"><table><tr><td class="cb-flow-page-footer__text">' . self::escape( $footer['left_text'] ) . '</td>';
			if ( $footer['show_page_number'] ) {
				$html .= '<td class="cb-flow-page-footer__page">' . self::escape( $footer['page_label'] ) . ' <span class="cb-flow-page-number"></span></td>';
			}
			return $html . '</tr></table></div>';
		}

		$style = $this->hints_style( $block->hints() );
		$open = '<div class="cb-flow-block cb-flow-' . self::escape( $block->type() ) . '" style="' . self::escape( $style ) . '">';

		if ( 'text' === $block->type() ) {
			return $open . nl2br( self::escape( (string) $block->payload() ), false ) . '</div>';
		}

		if ( 'heading' === $block->type() ) {
			/** @var array{text:string,role:string} $heading */
			$heading = $block->payload();
			$tags = [ 'title' => 'h1', 'section' => 'h2', 'subsection' => 'h3' ];
			$tag = $tags[ $heading['role'] ] ?? null;
			if ( null === $tag ) {
				throw new \InvalidArgumentException( 'Unsupported Flow heading role.' );
			}
			return $open . '<' . $tag . ' class="cb-flow-heading--' . self::escape( $heading['role'] ) . '">' . self::escape( $heading['text'] ) . '</' . $tag . '></div>';
		}

		if ( 'image' === $block->type() ) {
			return $open . '<img alt="" src="' . self::escape( (string) $block->payload() ) . '" /></div>';
		}

		if ( 'container' === $block->type() ) {
			/** @var list<RenderBlock> $children */
			$children = $block->payload();
			return $open . implode( '', array_map( fn ( RenderBlock $child ): string => $this->block( $child ), $children ) ) . '</div>';
		}

		if ( 'columns' === $block->type() ) {
			/** @var array{columns:list<list<RenderBlock>>,weights:list<float>} $composition */
			$composition = $block->payload();
			$widths = self::relative_widths( $composition['weights'] );
			$html = $open . '<table class="cb-flow-columns-table"><colgroup>';
			foreach ( $widths as $width ) {
				$html .= '<col style="width:' . self::number( $width ) . '%">';
			}
			$html .= '</colgroup><tbody><tr>';
			foreach ( $composition['columns'] as $column ) {
				$html .= '<td>' . implode( '', array_map( fn ( RenderBlock $child ): string => $this->block( $child ), $column ) ) . '</td>';
			}
			return $html . '</tr></tbody></table></div>';
		}

		if ( 'table' === $block->type() ) {
			/** @var array{headers:list<string>,rows:list<list<string>>,columns:list<TableColumn>,show_header:bool} $table */
			$table = $block->payload();
			$columns = $table['columns'];
			$html = $open . '<table class="cb-flow-table">';

			if ( [] !== $columns ) {
				$widths = self::relative_widths( array_map( fn ( TableColumn $column ): float => $column->weight(), $columns ) );
				$html .= '<colgroup>';
				foreach ( $widths as $width ) {
					$html .= '<col style="width:' . self::number( $width ) . '%">';
				}
				$html .= '</colgroup>';
			}

			if ( $table['show_header'] ) {
				$html .= '<thead><tr>';
				foreach ( $table['headers'] as $index => $header ) {
					$html .= '<th' . $this->table_cell_style( $columns[ $index ] ?? null ) . '>' . self::escape( $header ) . '</th>';
				}
				$html .= '</tr></thead>';
			}

			$html .= '<tbody>';
			foreach ( $table['rows'] as $row ) {
				$html .= '<tr>';
				foreach ( $row as $index => $cell ) {
					$html .= '<td' . $this->table_cell_style( $columns[ $index ] ?? null ) . '>' . self::escape( $cell ) . '</td>';
				}
				$html .= '</tr>';
			}
			return $html . '</tbody></table></div>';
		}

		throw new \InvalidArgumentException( 'Unsupported Flow render block type.' );
	}

	private function table_cell_style( ?TableColumn $column ): string {
		if ( null === $column ) {
			return '';
		}
		$style = 'text-align:' . $column->alignment() . ';';
		if ( $column->nowrap() ) {
			$style .= 'white-space:nowrap;';
		}
		return ' style="' . $style . '"';
	}

	/** @param list<float> $weights @return list<float> */
	private static function relative_widths( array $weights ): array {
		$total = array_sum( $weights );
		if ( $total <= 0.0 || ! is_finite( $total ) ) {
			throw new \InvalidArgumentException( 'Flow column weights cannot be normalized.' );
		}
		return array_map( static fn ( float $weight ): float => ( $weight / $total ) * 100.0, $weights );
	}

	/** @param array{space_before:float,space_after:float,break_before:bool,break_after:bool,keep_together:bool} $hints */
	private function hints_style( array $hints ): string {
		$style = 'margin-top:' . self::number( $hints['space_before'] ) . 'mm;margin-bottom:' . self::number( $hints['space_after'] ) . 'mm;';
		if ( $hints['break_before'] ) { $style .= 'page-break-before:always;'; }
		if ( $hints['break_after'] ) { $style .= 'page-break-after:always;'; }
		if ( $hints['keep_together'] ) { $style .= 'page-break-inside:avoid;'; }
		return $style;
	}

	private static function escape( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	private static function number( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
		return '' === $formatted ? '0' : $formatted;
	}
}
