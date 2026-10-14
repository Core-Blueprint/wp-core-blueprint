<?php
declare(strict_types=1);

/**
 * Public Core Admin List View Foundation.
 *
 * Presentation only: consumers supply already-authorized, bounded result rows
 * and their own safe navigation URLs, localized labels, sorting and filters.
 * This class never queries, authorizes, persists or executes row actions.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class ListView {
	private const MAX_COLUMNS = 20;
	private const MAX_ROWS = 500;
	private const MAX_FILTERS = 16;
	private const MAX_ACTIONS = 8;

	/**
	 * Opt-in Core Admin presentation; also safe for explicit external
	 * wp-admin enqueues when a consumer owns its screen.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( 'cb-core-css-tokens', CB_CORE_URL . 'assets/css/tokens.css', [], CB_CORE_VERSION );
		wp_enqueue_style( 'cb-core-css-buttons', CB_CORE_URL . 'assets/css/components/buttons.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
		wp_enqueue_style( 'cb-core-css-form-controls', CB_CORE_URL . 'assets/css/components/form-controls.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
		wp_enqueue_style( 'cb-core-css-mode-switcher', CB_CORE_URL . 'assets/css/components/mode-switcher.css', [ 'cb-core-css-tokens' ], CB_CORE_VERSION );
		wp_enqueue_style( 'cb-core-css-filter-bar', CB_CORE_URL . 'assets/css/components/filter-bar.css', [ 'cb-core-css-tokens', 'cb-core-css-mode-switcher' ], CB_CORE_VERSION );
		wp_enqueue_style( 'cb-core-css-list-view', CB_CORE_URL . 'assets/css/components/list-view.css', [ 'cb-core-css-filter-bar', 'cb-core-css-buttons', 'cb-core-css-form-controls' ], CB_CORE_VERSION );
	}

	/**
	 * Render a complete, progressively enhanced read-only admin listing.
	 *
	 * All visible labels MUST already be translated by the consumer. Strings
	 * are escaped here. Arbitrary HTML, scripts, mutation forms and callbacks
	 * are deliberately not accepted as cell or action descriptors.
	 *
	 * @param array<string,mixed> $args See docs/LIST-VIEW-FOUNDATION.md.
	 * @throws \InvalidArgumentException On malformed or excessive descriptors.
	 */
	public static function render( array $args ): string {
		$title = self::scalar( $args['title'] ?? '' );
		$columns = $args['columns'] ?? [];
		$rows = $args['rows'] ?? [];
		if ( '' === trim( $title ) || ! is_array( $columns ) || ! array_is_list( $columns )
			|| [] === $columns || count( $columns ) > self::MAX_COLUMNS
			|| ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > self::MAX_ROWS ) {
			throw new \InvalidArgumentException( 'Invalid List View title, columns or rows.' );
		}

		$ids = [];
		foreach ( $columns as $column ) {
			$id = is_array( $column ) ? ( $column['id'] ?? '' ) : '';
			if ( ! self::valid_key( $id ) || isset( $ids[ $id ] )
				|| '' === trim( self::scalar( $column['label'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( 'Invalid List View column.' );
			}
			$ids[ $id ] = true;
		}

		$labels = is_array( $args['labels'] ?? null ) ? $args['labels'] : [];
		$heading = esc_html( $title );
		$out = '<section class="cb-core-list-view" aria-label="' . esc_attr( $title ) . '">';
		$out .= '<header class="cb-core-list-view__head"><div class="cb-core-list-view__heading">';
		$out .= '<h1 class="cb-core-title">' . $heading . '</h1>';
		if ( '' !== self::scalar( $args['description'] ?? '' ) ) {
			$out .= '<p class="cb-core-intro">' . esc_html( self::scalar( $args['description'] ) ) . '</p>';
		}
		$out .= '</div>';
		$primary = $args['primary_action'] ?? null;
		if ( is_array( $primary ) && '' !== self::scalar( $primary['url'] ?? '' )
			&& '' !== self::scalar( $primary['label'] ?? '' ) ) {
			$out .= '<a class="button button-primary cb-core-button cb-core-button--primary" href="' .
				esc_url( self::scalar( $primary['url'] ) ) . '">' . esc_html( self::scalar( $primary['label'] ) ) . '</a>';
		}
		$out .= '</header>';

		if ( isset( $args['filters'] ) && is_array( $args['filters'] ) ) {
			$out .= self::filters( $args['filters'] );
		}
		$error = (bool) ( $args['error'] ?? false );
		if ( $error || [] === $rows ) {
			$message = $error
				? self::scalar( $args['error_message'] ?? '' )
				: self::scalar( $args['empty_message'] ?? '' );
			$out .= '<div class="cb-core-list-view__state' . ( $error ? ' cb-core-list-view__state--error' : '' ) .
				'" role="status">' . esc_html( $message ) . '</div>';
		} else {
			$out .= '<div class="cb-core-list-view__scroll" role="region" tabindex="0" aria-label="' .
				esc_attr( self::scalar( $labels['scroll'] ?? $title ) ) . '">';
			$out .= '<table class="cb-core-list-view__table"><caption class="screen-reader-text">' .
				$heading . '</caption><thead><tr>';
			foreach ( $columns as $column ) {
				$sort = $column['sort'] ?? null;
				$direction = is_array( $sort ) ? ( $sort['direction'] ?? '' ) : '';
				$direction = in_array( $direction, [ 'ascending', 'descending' ], true ) ? $direction : '';
				$out .= '<th scope="col"' . ( '' !== $direction ? ' aria-sort="' . $direction . '"' : '' ) . '>';
				$label = esc_html( self::scalar( $column['label'] ) );
				$url = is_array( $sort ) ? self::scalar( $sort['url'] ?? '' ) : '';
				$out .= '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $label . '</a>' : $label;
				$out .= '</th>';
			}
			$out .= '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! is_array( $row['cells'] ?? null ) ) {
					throw new \InvalidArgumentException( 'Invalid List View row.' );
				}
				$out .= '<tr>';
				foreach ( $columns as $index => $column ) {
					$id = $column['id'];
					$cell = $row['cells'][ $id ] ?? '';
					$content = self::cell( $cell );
					$out .= 0 === $index
						? '<th scope="row">' . $content . '</th>'
						: '<td>' . $content . '</td>';
				}
				$out .= '</tr>';
			}
			$out .= '</tbody></table></div>';
		}

		if ( ! $error && isset( $args['pagination'] ) && is_array( $args['pagination'] ) ) {
			$out .= self::pagination( $args['pagination'], $labels );
		}
		return $out . '</section>';
	}

	/** @param array<string,mixed> $args */
	private static function filters( array $args ): string {
		$action = self::scalar( $args['action'] ?? '' );
		$submit = self::scalar( $args['submit_label'] ?? '' );
		if ( '' === $action || '' === $submit ) {
			throw new \InvalidArgumentException( 'Filters require action and localized submit label.' );
		}
		$hidden = $args['hidden'] ?? [];
		$selects = $args['selects'] ?? [];
		if ( ! is_array( $hidden ) || ! is_array( $selects ) || count( $hidden ) > self::MAX_FILTERS
			|| ! array_is_list( $selects ) || count( $selects ) > self::MAX_FILTERS ) {
			throw new \InvalidArgumentException( 'Invalid List View filters.' );
		}
		$search = $args['search'] ?? null;
		$out = '<form class="cb-core-toolbar cb-core-list-view__filters" method="get" action="' .
			esc_url( $action ) . '" role="search">';
		foreach ( $hidden as $name => $value ) {
			if ( ! self::valid_key( $name ) || ! is_scalar( $value ) ) {
				throw new \InvalidArgumentException( 'Invalid hidden List View filter.' );
			}
			$out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( self::scalar( $value ) ) . '">';
		}
		if ( null !== $search ) {
			if ( ! is_array( $search ) || ! self::valid_key( $search['name'] ?? '' )
				|| '' === self::scalar( $search['label'] ?? '' ) ) {
				throw new \InvalidArgumentException( 'Invalid List View search.' );
			}
			$out .= '<label class="cb-core-toolbar__field cb-core-toolbar__field--grow"><span class="cb-core-toolbar__label">' .
				esc_html( self::scalar( $search['label'] ) ) . '</span><input type="search" name="' .
				esc_attr( $search['name'] ) . '" value="' . esc_attr( self::scalar( $search['value'] ?? '' ) ) .
				'" maxlength="120"></label>';
		}
		foreach ( $selects as $field ) {
			if ( ! is_array( $field ) || ! self::valid_key( $field['name'] ?? '' )
				|| '' === self::scalar( $field['label'] ?? '' )
				|| ! is_array( $field['options'] ?? null ) || count( $field['options'] ) > 100 ) {
				throw new \InvalidArgumentException( 'Invalid List View select.' );
			}
			$out .= '<label class="cb-core-toolbar__field"><span class="cb-core-toolbar__label">' .
				esc_html( self::scalar( $field['label'] ) ) . '</span><select name="' .
				esc_attr( $field['name'] ) . '">';
			$current = self::scalar( $field['value'] ?? '' );
			foreach ( $field['options'] as $value => $label ) {
				if ( ! is_scalar( $value ) || ! is_scalar( $label ) ) {
					throw new \InvalidArgumentException( 'Invalid List View option.' );
				}
				$out .= '<option value="' . esc_attr( (string) $value ) . '"' .
					selected( $current, (string) $value, false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			$out .= '</select></label>';
		}
		$out .= '<div class="cb-core-toolbar__actions cb-core-toolbar__actions--inline"><div class="cb-core-toolbar__actions-row">';
		$out .= '<button type="submit" class="button button-primary">' . esc_html( $submit ) . '</button>';
		$reset = $args['reset'] ?? null;
		if ( is_array( $reset ) && '' !== self::scalar( $reset['url'] ?? '' )
			&& '' !== self::scalar( $reset['label'] ?? '' ) ) {
			$out .= '<a class="button" href="' . esc_url( self::scalar( $reset['url'] ) ) . '">' .
				esc_html( self::scalar( $reset['label'] ) ) . '</a>';
		}
		return $out . '</div></div></form>';
	}

	/** @param mixed $cell */
	private static function cell( mixed $cell ): string {
		if ( is_scalar( $cell ) ) {
			return esc_html( (string) $cell );
		}
		if ( ! is_array( $cell ) ) {
			throw new \InvalidArgumentException( 'Invalid List View cell.' );
		}
		$text = esc_html( self::scalar( $cell['text'] ?? '' ) );
		$url = self::scalar( $cell['url'] ?? '' );
		$variant = self::scalar( $cell['tone'] ?? '' );
		if ( '' !== $variant && ! in_array( $variant, [ 'neutral', 'info', 'success', 'warning', 'danger' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid List View cell tone.' );
		}
		if ( '' !== $url ) {
			$text = '<a href="' . esc_url( $url ) . '">' . $text . '</a>';
		}
		if ( ! empty( $cell['strong'] ) ) {
			$text = '<strong>' . $text . '</strong>';
		}
		if ( '' !== $variant ) {
			$text = '<span class="cb-core-list-view__pill cb-core-list-view__pill--' . $variant . '">' . $text . '</span>';
		}
		$actions = $cell['actions'] ?? null;
		if ( null === $actions ) {
			return $text;
		}
		if ( ! is_array( $actions ) || ! array_is_list( $actions ) || count( $actions ) > self::MAX_ACTIONS ) {
			throw new \InvalidArgumentException( 'Invalid List View actions.' );
		}
		$text .= '<span class="cb-core-list-view__actions">';
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || '' === self::scalar( $action['url'] ?? '' )
				|| '' === self::scalar( $action['label'] ?? '' ) ) {
				throw new \InvalidArgumentException( 'Invalid List View action.' );
			}
			$text .= '<a class="button button-small" href="' . esc_url( self::scalar( $action['url'] ) ) .
				'">' . esc_html( self::scalar( $action['label'] ) ) . '</a>';
		}
		return $text . '</span>';
	}

	/** @param array<string,mixed> $args @param array<string,mixed> $labels */
	private static function pagination( array $args, array $labels ): string {
		$page = $args['page'] ?? null;
		$pages = $args['pages'] ?? null;
		$total = $args['total'] ?? null;
		if ( ! is_int( $page ) || ! is_int( $pages ) || ! is_int( $total )
			|| $page < 1 || $pages < 1 || $page > $pages || $total < 0 ) {
			throw new \InvalidArgumentException( 'Invalid List View pagination.' );
		}
		$out = '<nav class="cb-core-list-view__pagination" aria-label="' .
			esc_attr( self::scalar( $labels['pagination'] ?? '' ) ) . '">';
		$summary = self::scalar( $args['summary'] ?? '' );
		if ( '' !== $summary ) {
			$out .= '<span class="cb-core-list-view__summary">' . esc_html( $summary ) . '</span>';
		}
		$out .= '<span class="cb-core-list-view__pagination-actions">';
		foreach ( [ 'previous', 'next' ] as $direction ) {
			$url = self::scalar( $args[ $direction . '_url' ] ?? '' );
			$label = self::scalar( $labels[ $direction ] ?? '' );
			if ( '' !== $url && '' !== $label && ( 'previous' === $direction ? $page > 1 : $page < $pages ) ) {
				$out .= '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		return $out . '</span></nav>';
	}

	private static function valid_key( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $value );
	}

	private static function scalar( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			throw new \InvalidArgumentException( 'List View values must be scalar.' );
		}
		return (string) $value;
	}

	private function __construct() {}
}
