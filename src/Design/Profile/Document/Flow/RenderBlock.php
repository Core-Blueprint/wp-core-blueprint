<?php
declare(strict_types=1);

namespace CB\Core\Design\Profile\Document\Flow;

use CB\Core\Design\Profile\Document\Render\ImageDataUri;

defined( 'ABSPATH' ) || exit;

final readonly class RenderBlock {
	private const MAX_TEXT_BYTES = 65536;
	private const MAX_COLUMNS = 32;
	private const MAX_ROWS = 2000;
	private const MAX_COMPOSITION_COLUMNS = 4;
	private const MAX_COLUMN_WEIGHT = 100.0;
	private const MAX_METRICS = 8;
	private const CALLOUT_TONES = [ 'neutral', 'info', 'success', 'warning', 'critical' ];

	/** @param mixed $payload @param array{space_before:float,space_after:float,break_before:bool,break_after:bool,keep_together:bool} $hints */
	private function __construct( private string $type, private mixed $payload, private array $hints ) {}

	/** @param array<string,mixed> $hints */
	public static function text( string $text, array $hints = [] ): self {
		self::assert_text_size( $text, 'Flow text block exceeds the supported size.' );
		return new self( 'text', $text, self::normalize_hints( $hints ) );
	}

	/**
	 * Semantic heading with a bounded presentation role.
	 *
	 * @param array<string,mixed> $hints
	 */
	public static function heading( string $text, string $role = 'section', array $hints = [] ): self {
		self::assert_text_size( $text, 'Flow heading exceeds the supported size.' );
		if ( ! in_array( $role, [ 'title', 'section', 'subsection' ], true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Flow heading role.' );
		}
		return new self( 'heading', [ 'text' => $text, 'role' => $role ], self::normalize_hints( $hints ) );
	}

	/**
	 * Bounded semantic callout. Tone selects renderer-owned presentation only;
	 * consumers cannot supply colours, classes or style values.
	 *
	 * @param array<string,mixed> $hints
	 */
	public static function callout( string $title, string $body = '', string $tone = 'neutral', array $hints = [] ): self {
		self::assert_text_size( $title, 'Flow callout title exceeds the supported size.' );
		self::assert_text_size( $body, 'Flow callout body exceeds the supported size.' );
		if ( '' === trim( $title ) ) {
			throw new \InvalidArgumentException( 'Flow callouts require a title.' );
		}
		if ( ! in_array( $tone, self::CALLOUT_TONES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Flow callout tone.' );
		}
		return new self(
			'callout',
			[ 'title' => $title, 'body' => $body, 'tone' => $tone ],
			self::normalize_hints( $hints )
		);
	}

	/**
	 * Bounded KPI/metric group. Every item has a required label and value and an
	 * optional detail line. Grid geometry remains renderer-owned.
	 *
	 * @param list<array{label:string,value:string,detail?:string}> $items
	 * @param array<string,mixed> $hints
	 */
	public static function metrics( array $items, array $hints = [] ): self {
		if ( ! array_is_list( $items ) || [] === $items || count( $items ) > self::MAX_METRICS ) {
			throw new \InvalidArgumentException( 'Flow metrics require between 1 and 8 ordered items.' );
		}

		$normalized = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				throw new \InvalidArgumentException( 'Every Flow metric must be an object.' );
			}
			foreach ( array_keys( $item ) as $key ) {
				if ( ! is_string( $key ) || ! in_array( $key, [ 'label', 'value', 'detail' ], true ) ) {
					throw new \InvalidArgumentException( 'Flow metrics contain an unsupported key.' );
				}
			}

			$label  = $item['label'] ?? null;
			$value  = $item['value'] ?? null;
			$detail = $item['detail'] ?? '';
			if ( ! is_string( $label ) || '' === trim( $label ) || ! is_string( $value ) || '' === trim( $value ) || ! is_string( $detail ) ) {
				throw new \InvalidArgumentException( 'Flow metric label/value must be non-empty strings and detail must be a string.' );
			}
			self::assert_text_size( $label, 'Flow metric label exceeds the supported size.' );
			self::assert_text_size( $value, 'Flow metric value exceeds the supported size.' );
			self::assert_text_size( $detail, 'Flow metric detail exceeds the supported size.' );
			$normalized[] = [ 'label' => $label, 'value' => $value, 'detail' => $detail ];
		}

		return new self( 'metrics', $normalized, self::normalize_hints( $hints ) );
	}

	/** @param array<string,mixed> $hints */
	public static function rule( array $hints = [] ): self {
		return new self( 'rule', null, self::normalize_hints( $hints ) );
	}

	/** @param array<string,mixed> $hints */
	public static function image( string $data_uri, array $hints = [] ): self {
		return new self( 'image', ImageDataUri::assert_valid( $data_uri ), self::normalize_hints( $hints ) );
	}

	/** @param list<RenderBlock> $children @param array<string,mixed> $hints */
	public static function container( array $children, array $hints = [] ): self {
		if ( ! array_is_list( $children ) ) { throw new \InvalidArgumentException( 'Flow container children must be an ordered list.' ); }
		foreach ( $children as $child ) {
			if ( ! $child instanceof self ) { throw new \InvalidArgumentException( 'Flow containers accept typed render blocks only.' ); }
		}
		return new self( 'container', array_values( $children ), self::normalize_hints( $hints ) );
	}

	/**
	 * Bounded multi-column composition for document sections.
	 *
	 * Columns contain typed render blocks only. Widths are positive relative
	 * weights; consumers cannot supply CSS, units or arbitrary layout values.
	 *
	 * @param list<list<RenderBlock>> $columns
	 * @param list<float|int> $weights
	 * @param array<string,mixed> $hints
	 */
	public static function columns( array $columns, array $weights = [], array $hints = [] ): self {
		if ( ! array_is_list( $columns ) || count( $columns ) < 2 || count( $columns ) > self::MAX_COMPOSITION_COLUMNS ) {
			throw new \InvalidArgumentException( 'Flow columns require between 2 and 4 ordered columns.' );
		}
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) || ! array_is_list( $column ) ) {
				throw new \InvalidArgumentException( 'Every Flow column must be an ordered list of typed render blocks.' );
			}
			foreach ( $column as $child ) {
				if ( ! $child instanceof self ) {
					throw new \InvalidArgumentException( 'Flow columns accept typed render blocks only.' );
				}
		}
		}

		if ( [] === $weights ) {
			$weights = array_fill( 0, count( $columns ), 1.0 );
		}
		if ( ! array_is_list( $weights ) || count( $weights ) !== count( $columns ) ) {
			throw new \InvalidArgumentException( 'Flow column weights must match the declared column count.' );
		}

		$normalized_weights = [];
		foreach ( $weights as $weight ) {
			if ( ! is_int( $weight ) && ! is_float( $weight ) ) {
				throw new \InvalidArgumentException( 'Flow column weights must be numeric.' );
			}
			$weight = (float) $weight;
			if ( ! is_finite( $weight ) || $weight <= 0.0 || $weight > self::MAX_COLUMN_WEIGHT ) {
				throw new \InvalidArgumentException( 'Flow column weight is outside the supported range.' );
			}
			$normalized_weights[] = $weight;
		}

		return new self(
			'columns',
			[ 'columns' => array_values( $columns ), 'weights' => $normalized_weights ],
			self::normalize_hints( $hints )
		);
	}

	/**
	 * @param list<string> $headers
	 * @param list<list<string>> $rows
	 * @param array<string,mixed> $hints
	 * @param list<TableColumn> $columns Optional presentation metadata. Empty
	 *        preserves the original R4/R5 table rendering behavior.
	 */
	public static function table( array $headers, array $rows, array $hints = [], array $columns = [], bool $show_header = true ): self {
		if ( ! array_is_list( $headers ) || ! array_is_list( $rows ) ) { throw new \InvalidArgumentException( 'Flow table headers and rows must be ordered lists.' ); }
		if ( [] === $headers || count( $headers ) > self::MAX_COLUMNS ) { throw new \InvalidArgumentException( 'Flow tables require between 1 and 32 columns.' ); }
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) || strlen( $header ) > self::MAX_TEXT_BYTES ) { throw new \InvalidArgumentException( 'Flow table headers must be bounded strings.' ); }
		}
		if ( count( $rows ) > self::MAX_ROWS ) { throw new \InvalidArgumentException( 'Flow table exceeds the supported row count.' ); }
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! array_is_list( $row ) || count( $row ) !== count( $headers ) ) { throw new \InvalidArgumentException( 'Every Flow table row must match the declared column count.' ); }
			foreach ( $row as $cell ) {
				if ( ! is_string( $cell ) || strlen( $cell ) > self::MAX_TEXT_BYTES ) { throw new \InvalidArgumentException( 'Flow table cells must be bounded strings.' ); }
			}
		}

		if ( [] !== $columns ) {
			if ( ! array_is_list( $columns ) || count( $columns ) !== count( $headers ) ) {
				throw new \InvalidArgumentException( 'Flow table column metadata must match the declared column count.' );
			}
			foreach ( $columns as $column ) {
				if ( ! $column instanceof TableColumn ) {
					throw new \InvalidArgumentException( 'Flow tables accept typed column metadata only.' );
			}
		}

		return new self(
			'table',
			[
				'headers'     => array_values( $headers ),
				'rows'        => array_values( $rows ),
				'columns'     => array_values( $columns ),
				'show_header' => $show_header,
			],
			self::normalize_hints( $hints )
		);
	}

	/**
	 * Fixed per-page footer with consumer-owned escaped text and optional,
	 * renderer-owned CSS page counter. No layout/style options are accepted.
	 */
	public static function page_footer( string $left_text, string $page_label = '', bool $show_page_number = true ): self {
		self::assert_text_size( $left_text, 'Flow page footer text exceeds the supported size.' );
		self::assert_text_size( $page_label, 'Flow page footer text exceeds the supported size.' );
		if ( $show_page_number && '' === trim( $page_label ) ) {
			throw new \InvalidArgumentException( 'Flow page footer requires a page label when page numbering is enabled.' );
		}
		return new self(
			'page_footer',
			[
				'left_text'        => $left_text,
				'page_label'       => $page_label,
				'show_page_number' => $show_page_number,
			],
			self::normalize_hints( [] )
		);
	}

	public function type(): string { return $this->type; }
	public function payload(): mixed { return $this->payload; }
	/** @return array{space_before:float,space_after:float,break_before:bool,break_after:bool,keep_together:bool} */
	public function hints(): array { return $this->hints; }

	/** @param array<string,mixed> $hints @return array{space_before:float,space_after:float,break_before:bool,break_after:bool,keep_together:bool} */
	private static function normalize_hints( array $hints ): array {
		$normalized = Hints::normalize( $hints );
		if ( null === $normalized ) { throw new \InvalidArgumentException( 'Invalid Flow render hints.' ); }
		return $normalized;
	}

	private static function assert_text_size( string $text, string $message ): void {
		if ( strlen( $text ) > self::MAX_TEXT_BYTES ) {
			throw new \InvalidArgumentException( $message );
		}
	}
}
