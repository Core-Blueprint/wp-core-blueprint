<?php
declare(strict_types=1);
/**
 * Core Blueprint icon registry and SVG renderer.
 *
 * Lucide is the canonical icon source for the Core Blueprint suite. This
 * registry intentionally contains a curated subset only: callers request
 * semantic names where practical, while the registry maps those names to a
 * concrete Lucide glyph. That keeps downstream plugins decoupled from a
 * specific glyph choice and prevents every extension from bundling Lucide.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Icon {

	public const SIZE_COMPACT = 'compact';
	public const SIZE_DEFAULT = 'default';
	public const SIZE_LARGE   = 'large';

	/**
	 * Curated Lucide icon-node data.
	 *
	 * Format mirrors Lucide's icon-node contract: [ tag, attributes ].
	 * Only trusted, hard-coded data is exposed to the renderers.
	 *
	 * @var array<string,array<int,array{0:string,1:array<string,string>}>>
	 */
	private const ICONS = [
		'chevron-right' => [
			[ 'path', [ 'd' => 'm9 18 6-6-6-6' ] ],
		],
		'chevron-down' => [
			[ 'path', [ 'd' => 'm6 9 6 6 6-6' ] ],
		],
		'plus' => [
			[ 'line', [ 'x1' => '12', 'y1' => '5', 'x2' => '12', 'y2' => '19' ] ],
			[ 'line', [ 'x1' => '5', 'y1' => '12', 'x2' => '19', 'y2' => '12' ] ],
		],
		'pencil' => [
			[ 'path', [ 'd' => 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z' ] ],
			[ 'path', [ 'd' => 'm15 5 4 4' ] ],
		],
		'users' => [
			[ 'path', [ 'd' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2' ] ],
			[ 'circle', [ 'cx' => '9', 'cy' => '7', 'r' => '4' ] ],
			[ 'path', [ 'd' => 'M22 21v-2a4 4 0 0 0-3-3.87' ] ],
			[ 'path', [ 'd' => 'M16 3.13a4 4 0 0 1 0 7.75' ] ],
		],
		'arrow-right' => [
			[ 'path', [ 'd' => 'M5 12h14' ] ],
			[ 'path', [ 'd' => 'm12 5 7 7-7 7' ] ],
		],
		'clock' => [
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ],
			[ 'polyline', [ 'points' => '12 6 12 12 16 14' ] ],
		],
		'tag' => [
			[ 'path', [ 'd' => 'M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z' ] ],
			[ 'circle', [ 'cx' => '7.5', 'cy' => '7.5', 'r' => '.5' ] ],
		],
		'list' => [
			[ 'path', [ 'd' => 'M8 6h13' ] ],
			[ 'path', [ 'd' => 'M8 12h13' ] ],
			[ 'path', [ 'd' => 'M8 18h13' ] ],
			[ 'path', [ 'd' => 'M3 6h.01' ] ],
			[ 'path', [ 'd' => 'M3 12h.01' ] ],
			[ 'path', [ 'd' => 'M3 18h.01' ] ],
		],
		'grid-2x2' => [
			[ 'rect', [ 'width' => '7', 'height' => '7', 'x' => '3', 'y' => '3', 'rx' => '1' ] ],
			[ 'rect', [ 'width' => '7', 'height' => '7', 'x' => '14', 'y' => '3', 'rx' => '1' ] ],
			[ 'rect', [ 'width' => '7', 'height' => '7', 'x' => '3', 'y' => '14', 'rx' => '1' ] ],
			[ 'rect', [ 'width' => '7', 'height' => '7', 'x' => '14', 'y' => '14', 'rx' => '1' ] ],
		],
		'maximize-2' => [
			[ 'polyline', [ 'points' => '15 3 21 3 21 9' ] ],
			[ 'polyline', [ 'points' => '9 21 3 21 3 15' ] ],
			[ 'line', [ 'x1' => '21', 'y1' => '3', 'x2' => '14', 'y2' => '10' ] ],
			[ 'line', [ 'x1' => '3', 'y1' => '21', 'x2' => '10', 'y2' => '14' ] ],
		],
		'minimize-2' => [
			[ 'polyline', [ 'points' => '4 14 10 14 10 20' ] ],
			[ 'polyline', [ 'points' => '20 10 14 10 14 4' ] ],
			[ 'line', [ 'x1' => '14', 'y1' => '10', 'x2' => '21', 'y2' => '3' ] ],
			[ 'line', [ 'x1' => '3', 'y1' => '21', 'x2' => '10', 'y2' => '14' ] ],
		],
		'file' => [
			[ 'path', [ 'd' => 'M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5z' ] ],
			[ 'polyline', [ 'points' => '14 2 14 8 20 8' ] ],
		],
		'upload' => [
			[ 'path', [ 'd' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' ] ],
			[ 'polyline', [ 'points' => '17 8 12 3 7 8' ] ],
			[ 'line', [ 'x1' => '12', 'y1' => '3', 'x2' => '12', 'y2' => '15' ] ],
		],
		'download' => [
			[ 'path', [ 'd' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' ] ],
			[ 'polyline', [ 'points' => '7 10 12 15 17 10' ] ],
			[ 'line', [ 'x1' => '12', 'y1' => '15', 'x2' => '12', 'y2' => '3' ] ],
		],
		'shield-alert' => [
			[ 'path', [ 'd' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10' ] ],
			[ 'path', [ 'd' => 'M12 8v4' ] ],
			[ 'path', [ 'd' => 'M12 16h.01' ] ],
		],
		'archive-restore' => [
			[ 'rect', [ 'width' => '20', 'height' => '5', 'x' => '2', 'y' => '3', 'rx' => '1' ] ],
			[ 'path', [ 'd' => 'M4 8v11a2 2 0 0 0 2 2h2' ] ],
			[ 'path', [ 'd' => 'M20 8v11a2 2 0 0 1-2 2h-2' ] ],
			[ 'path', [ 'd' => 'm9 15 3-3 3 3' ] ],
			[ 'path', [ 'd' => 'M12 12v9' ] ],
		],
		'trash-2' => [
			[ 'path', [ 'd' => 'M10 11v6' ] ],
			[ 'path', [ 'd' => 'M14 11v6' ] ],
			[ 'path', [ 'd' => 'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6' ] ],
			[ 'path', [ 'd' => 'M3 6h18' ] ],
			[ 'path', [ 'd' => 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2' ] ],
		],
		'eye' => [
			[ 'path', [ 'd' => 'M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0' ] ],
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '3' ] ],
		],
		'info' => [
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ],
			[ 'path', [ 'd' => 'M12 16v-4' ] ],
			[ 'path', [ 'd' => 'M12 8h.01' ] ],
		],
		'copy' => [
			[ 'rect', [ 'width' => '14', 'height' => '14', 'x' => '8', 'y' => '8', 'rx' => '2', 'ry' => '2' ] ],
			[ 'path', [ 'd' => 'M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2' ] ],
		],
		'circle-check' => [
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ],
			[ 'path', [ 'd' => 'm9 12 2 2 4-4' ] ],
		],
		'triangle-alert' => [
			[ 'path', [ 'd' => 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3' ] ],
			[ 'path', [ 'd' => 'M12 9v4' ] ],
			[ 'path', [ 'd' => 'M12 17h.01' ] ],
		],
		'circle-x' => [
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ],
			[ 'path', [ 'd' => 'm15 9-6 6' ] ],
			[ 'path', [ 'd' => 'm9 9 6 6' ] ],
		],

		'shield' => [
			[ 'path', [ 'd' => 'M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3z' ] ],
		],
		'mail' => [
			[ 'rect', [ 'width' => '20', 'height' => '16', 'x' => '2', 'y' => '4', 'rx' => '2' ] ],
			[ 'path', [ 'd' => 'm22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7' ] ],
		],
		'languages' => [
			[ 'path', [ 'd' => 'm5 8 6 6' ] ],
			[ 'path', [ 'd' => 'm4 14 6-6 2-3' ] ],
			[ 'path', [ 'd' => 'M2 5h12' ] ],
			[ 'path', [ 'd' => 'M7 2h1' ] ],
			[ 'path', [ 'd' => 'm22 22-5-10-5 10' ] ],
			[ 'path', [ 'd' => 'M14 18h6' ] ],
		],
		'palette' => [
			[ 'circle', [ 'cx' => '13.5', 'cy' => '6.5', 'r' => '.5', 'fill' => 'currentColor' ] ],
			[ 'circle', [ 'cx' => '17.5', 'cy' => '10.5', 'r' => '.5', 'fill' => 'currentColor' ] ],
			[ 'circle', [ 'cx' => '8.5', 'cy' => '7.5', 'r' => '.5', 'fill' => 'currentColor' ] ],
			[ 'circle', [ 'cx' => '6.5', 'cy' => '12.5', 'r' => '.5', 'fill' => 'currentColor' ] ],
			[ 'path', [ 'd' => 'M12 22a10 10 0 1 1 10-10 4 4 0 0 1-4 4h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4A1.75 1.75 0 0 1 13.25 22z' ] ],
		],
		'code' => [
			[ 'path', [ 'd' => 'm16 18 6-6-6-6' ] ],
			[ 'path', [ 'd' => 'm8 6-6 6 6 6' ] ],
		],
		'lock' => [
			[ 'rect', [ 'width' => '18', 'height' => '11', 'x' => '3', 'y' => '11', 'rx' => '2', 'ry' => '2' ] ],
			[ 'path', [ 'd' => 'M7 11V7a5 5 0 0 1 10 0v4' ] ],
		],
		'search' => [
			[ 'circle', [ 'cx' => '11', 'cy' => '11', 'r' => '8' ] ],
			[ 'path', [ 'd' => 'm21 21-4.3-4.3' ] ],
		],
		'life-buoy' => [
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ],
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '4' ] ],
			[ 'path', [ 'd' => 'm4.93 4.93 4.24 4.24' ] ],
			[ 'path', [ 'd' => 'm14.83 14.83 4.24 4.24' ] ],
			[ 'path', [ 'd' => 'm14.83 9.17 4.24-4.24' ] ],
			[ 'path', [ 'd' => 'm9.17 14.83-4.24 4.24' ] ],
		],
		'settings' => [
			[ 'path', [ 'd' => 'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z' ] ],
			[ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '3' ] ],
		],
	];

	/** @var array<string,string> */
	private const ALIASES = [
		'expand'           => 'chevron-right',
		'collapse'         => 'chevron-down',
		'workspace-expand'  => 'maximize-2',
		'workspace-restore' => 'minimize-2',
		'quarantine' => 'shield-alert',
		'restore'    => 'archive-restore',
		'delete'     => 'trash-2',
		'review'      => 'eye',
		'public-site' => 'eye',
		'locked-site' => 'shield-alert',
		'settings'         => 'settings',
		'admin-generic'    => 'settings',
		'admin-network'    => 'grid-2x2',
		'admin-users'      => 'users',
		'art'              => 'palette',
		'edit-page'        => 'pencil',
		'editor-code'      => 'code',
		'email-alt'        => 'mail',
		'lock'             => 'lock',
		'privacy'          => 'shield',
		'search'           => 'search',
		'shield'           => 'shield',
		'sos'              => 'life-buoy',
		'translation'      => 'languages',
		'feedback-info'    => 'info',
		'feedback-success' => 'circle-check',
		'feedback-warning' => 'triangle-alert',
		'feedback-error'   => 'circle-x',
		'clipboard-copy'    => 'copy',
		'clipboard-success' => 'circle-check',
		'add'               => 'plus',
		'edit'              => 'pencil',
		'assignees'         => 'users',
		'meta-author'       => 'users',
		'meta-assigned'     => 'arrow-right',
		'meta-updated'      => 'clock',
		'meta-tags'         => 'tag',
		'layout-list'       => 'list',
		'menu'              => 'list',
		'layout-grid'       => 'grid-2x2',
		'import'            => 'upload',
		'export'            => 'download',
	];

	/** @var string[] */
	private const ALLOWED_TAGS = [ 'path', 'circle', 'rect', 'line', 'polyline' ];

	/** Resolve a semantic alias or canonical Lucide name. */
	public static function resolve( string $name ): string {
		$name = sanitize_key( $name );
		return self::ALIASES[ $name ] ?? $name;
	}


	/**
	 * Normalize a stored admin-menu icon value.
	 *
	 * Supported formats:
	 * - dashicons-* class names (backwards compatible)
	 * - lucide:{name} canonical Core Blueprint icon references
	 */
	public static function normalize_menu_icon( string $icon, string $default = 'dashicons-admin-generic' ): string {
		$default = self::normalize_menu_icon_default( $default );
		$icon = trim( sanitize_text_field( $icon ) );
		if ( '' === $icon ) {
			return $default;
		}

		if ( str_starts_with( $icon, 'lucide:' ) ) {
			$name = self::resolve( substr( $icon, 7 ) );
			return isset( self::ICONS[ $name ] ) ? 'lucide:' . $name : $default;
		}

		$dashicon = self::normalize_dashicon_class( $icon );
		return '' !== $dashicon ? $dashicon : $default;
	}

	/** Convert a stored icon value to the argument expected by WordPress menu APIs. */
	public static function menu_icon_argument( string $icon, string $default = 'dashicons-admin-generic' ): string {
		$icon = self::normalize_menu_icon( $icon, $default );
		if ( str_starts_with( $icon, 'lucide:' ) ) {
			$svg = self::menu_icon_svg( substr( $icon, 7 ) );
			if ( '' !== $svg ) {
				return 'data:image/svg+xml;base64,' . base64_encode( $svg );
			}
		}
		return self::normalize_dashicon_class( $icon ) ?: self::normalize_menu_icon_default( $default );
	}

	/** Build a compact SVG suitable for wp-admin menu icon usage. */
	private static function menu_icon_svg( string $name ): string {
		$resolved = self::resolve( $name );
		if ( ! isset( self::ICONS[ $resolved ] ) ) {
			return '';
		}

		$out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
		foreach ( self::ICONS[ $resolved ] as $node ) {
			$out .= self::render_node( $node );
		}
		$out .= '</svg>';
		return $out;
	}

	private static function normalize_menu_icon_default( string $default ): string {
		$default = trim( sanitize_text_field( $default ) );
		if ( str_starts_with( $default, 'lucide:' ) ) {
			$name = self::resolve( substr( $default, 7 ) );
			return isset( self::ICONS[ $name ] ) ? 'lucide:' . $name : 'dashicons-admin-generic';
		}
		$dashicon = self::normalize_dashicon_class( $default );
		return '' !== $dashicon ? $dashicon : 'dashicons-admin-generic';
	}

	private static function normalize_dashicon_class( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( str_starts_with( $value, 'dashicons:' ) ) {
			$value = 'dashicons-' . sanitize_key( substr( $value, 10 ) );
		}
		if ( ! str_starts_with( $value, 'dashicons-' ) ) {
			return '';
		}
		$slug = sanitize_key( substr( $value, 10 ) );
		return '' !== $slug ? 'dashicons-' . $slug : '';
	}

	/** Whether the registry knows an icon or semantic alias. */
	public static function has( string $name ): bool {
		return isset( self::ICONS[ self::resolve( $name ) ] );
	}

	/**
	 * Export registry data for the shared JS icon module.
	 *
	 * @return array{icons:array,aliases:array}
	 */
	public static function export_registry(): array {
		return [
			'icons'   => self::ICONS,
			'aliases' => self::ALIASES,
		];
	}

	/**
	 * Render an inline SVG icon.
	 *
	 * Args:
	 *   size  : compact|default|large
	 *   class : additional CSS classes
	 *   label : accessible label. Empty means decorative aria-hidden icon.
	 */
	public static function render( string $name, array $args = [] ): string {
		$resolved = self::resolve( $name );
		if ( ! isset( self::ICONS[ $resolved ] ) ) {
			return '';
		}

		$size = (string) ( $args['size'] ?? self::SIZE_DEFAULT );
		if ( ! in_array( $size, [ self::SIZE_COMPACT, self::SIZE_DEFAULT, self::SIZE_LARGE ], true ) ) {
			$size = self::SIZE_DEFAULT;
		}

		$extra = trim( (string) ( $args['class'] ?? '' ) );
		$label = trim( (string) ( $args['label'] ?? '' ) );

		$classes = [ 'cb-core-icon', 'cb-core-icon--' . $size, 'cb-core-icon--' . $resolved ];
		if ( '' !== $extra ) {
			foreach ( preg_split( '/\s+/', $extra ) ?: [] as $class ) {
				$class = sanitize_html_class( $class );
				if ( '' !== $class ) {
					$classes[] = $class;
				}
			}
		}

		$pixel_size = self::SIZE_COMPACT === $size ? 14 : ( self::SIZE_LARGE === $size ? 24 : 16 );

		$accessibility = '' === $label
			? ' aria-hidden="true" focusable="false"'
			: ' role="img" aria-label="' . esc_attr( $label ) . '"';

		$out = '<svg class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '" width="' . esc_attr( (string) $pixel_size ) . '" height="' . esc_attr( (string) $pixel_size ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg"' . $accessibility . '>';
		foreach ( self::ICONS[ $resolved ] as $node ) {
			$out .= self::render_node( $node );
		}
		$out .= '</svg>';
		return $out;
	}

	/** @param array{0:string,1:array<string,string>} $node */
	private static function render_node( array $node ): string {
		$tag = $node[0] ?? '';
		if ( ! in_array( $tag, self::ALLOWED_TAGS, true ) ) {
			return '';
		}

		$attrs = '';
		foreach ( $node[1] ?? [] as $key => $value ) {
			if ( ! preg_match( '/^[a-z][a-z0-9-]*$/', $key ) ) {
				continue;
			}
			$attrs .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}
		return '<' . $tag . $attrs . '></' . $tag . '>';
	}
}
