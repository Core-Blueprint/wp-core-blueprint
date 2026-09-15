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
			. 'html{padding:0;}body{margin:0;padding:0;}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.4;color:#111;}'
			. '.cb-flow-block{box-sizing:border-box;}.cb-flow-image img{display:block;max-width:100%;height:auto;border:0;}'
			. '.cb-flow-heading h1,.cb-flow-heading h2,.cb-flow-heading h3{margin:0;padding:0;line-height:1.2;}'
			. '.cb-flow-heading--title{font-size:22pt;font-weight:700;color:' . self::escape( $accent ) . ';}'
			. '.cb-flow-heading--section{font-size:11pt;font-weight:700;color:#111;}'
			. '.cb-flow-heading--subsection{font-size:9.5pt;font-weight:700;color:#333;}'
			. '.cb-flow-callout{padding:10pt 12pt;border:1px solid #dbe2ea;border-left:3pt solid ' . self::escape( $accent ) . ';border-radius:6pt;background:#f8fafc;}'
			. '.cb-flow-callout__title{margin:0 0 3pt;font-size:13pt;font-weight:700;line-height:1.2;color:#0f172a;}'
			. '.cb-flow-callout__body{font-size:9.5pt;line-height:1.45;color:#334155;}'
			. '.cb-flow-callout--info{border-left-color:#2563eb;background:#eff6ff;}.cb-flow-callout--success{border-left-color:#16a34a;background:#f0fdf4;}'
			. '.cb-flow-callout--warning{border-left-color:#d97706;background:#fffbeb;}.cb-flow-callout--critical{border-left-color:#dc2626;background:#fef2f2;}'
			. '.cb-flow-metrics-table{width:100%;border-collapse:collapse;table-layout:fixed;}.cb-flow-metrics-table td{padding:2pt;vertical-align:top;border:0;}'
			. '.cb-flow-metric-card{min-height:48pt;padding:8pt;border:1px solid #dbe2ea;border-radius:5pt;background:#f8fafc;}'
			. '.cb-flow-metric-card__label{font-size:8pt;font-weight:700;line-height:1.25;color:#64748b;}'
			. '.cb-flow-metric-card__value{margin-top:2pt;font-size:17pt;font-weight:700;line-height:1.1;color:' . self::escape( $accent ) . ';}'
			. '.cb-flow-metric-card__detail{margin-top:3pt;font-size:8pt;line-height:1.35;color:#64748b;}'
			. '.cb-flow-rule__line{border-top:1px solid #dbe2ea;height:0;line-height:0;}'
			. '.cb-flow-columns-table{width:100%;border-collapse:collapse;table-layout:fixed;}.cb-flow-columns-table>tbody>tr>td{border:0;padding:0 6pt;vertical-align:top;}'
			. '.cb-flow-columns-table>tbody>tr>td:first-child{padding-left:0;}.cb-flow-columns-table>tbody>tr>td:last-child{padding-right:0;}'
			. '.cb-flow-table{width:100%;border-collapse:collapse;}.cb-flow-table th,.cb-flow-table td{padding:4pt;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;}'
			. '.cb-flow-table th{color:' . self::escape( $accent ) . ';border-bottom-color:' . self::escape( $accent ) . ';}'
			. '.cb-flow-page-footer{position:fixed;left:0;right:0;bottom:-10mm;border-top:1px solid #ddd;padding-top:2mm;font-size:8pt;color:#666;}'
			. '.cb-flow-page-footer table{width:100%;border-collapse:collapse;}.cb-flow-page-footer td{border:0;padding:0;vertical-align:top;}'
			. '.cb-flow-page-footer__page{text-align:right;white-space:nowrap;}.cb-flow-page-number::before{content:counter(page);}'
			. '</style></head><body>' . $body . '</body></html>';
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

		if ( 'callout' === $block->type() ) {
			/** @var array{title:string,body:string,tone:string} $callout */
			$callout = $block->payload();
			$html = '<div class="cb-flow-callout cb-flow-callout--' . self::escape( $callout['tone'] ) . '">';
			$html .= '<div class="cb-flow-callout__title">' . self::escape( $callout['title'] ) . '</div>';
			if ( '' !== $callout['body'] ) {
				$html .= '<div class="cb-flow-callout__body">' . nl2br( self::escape( $callout['body'] ), false ) . '</div>';
			}
			return $open . $html . '</div></div>';
		}

		if ( 'metrics' === $block->type() ) {
			/** @var list<array{label:string,value:string,detail:string}> $metrics */
			$metrics = $block->payload();
			$columns = self::metric_columns( count( $metrics ) );
			$html = $open . '<table class="cb-flow-metrics-table"><tbody>';
			foreach ( array_chunk( $metrics, $columns ) as $row ) {
				$html .= '<tr>';
				foreach ( $row as $metric ) {
					$html .= '<td><div class="cb-flow-metric-card">'
						. '<div class="cb-flow-metric-card__label">' . self::escape( $metric['label'] ) . '</div>'
						. '<div class="cb-flow-metric-card__value">' . self::escape( $metric['value'] ) . '</div>';
					if ( '' !== $metric['detail'] ) {
						$html .= '<div class="cb-flow-metric-card__detail">' . self::escape( $metric['detail'] ) . '</div>';
					}
					$html .= '</div></td>';
				}
				for ( $index = count( $row ); $index < $columns; $index++ ) {
					$html .= '<td></td>';
				}
				$html .= '</tr>';
			}
			return $html . '</tbody></table></div>';
		}

		if ( 'rule' === $block->type() ) {
			return $open . '<div class="cb-flow-rule__line"></div></div>';
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

	private static function metric_columns( int $count ): int {
		if ( $count <= 3 ) {
			return $count;
		}
		if ( $count <= 6 ) {
			return 3;
		}
		return 4;
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
