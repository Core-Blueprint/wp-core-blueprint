<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const TYPES = [ 'php', 'css', 'js', 'html' ];

	private const LOCATION_IDS_BY_TYPE = [
		'php'  => [ 'plugins_loaded', 'init', 'wp_loaded', 'admin_init', 'wp_head', 'wp_footer', 'admin_head', 'admin_footer', 'shortcode' ],
		'css'  => [ 'frontend', 'admin', 'both' ],
		'js'   => [ 'wp_head', 'wp_footer', 'admin_head', 'admin_footer' ],
		'html' => [ 'shortcode', 'wp_head', 'wp_footer', 'admin_head', 'admin_footer' ],
	];

	public static function default_meta(): array {
		return [
			'id'          => '',
			'title'       => '',
			'description' => '',
			'type'        => 'php',
			'location'    => 'plugins_loaded',
			'priority'    => 10,
			'enabled'     => false,
			'shortcode'   => '',
			'tags'        => [],
			'conditions'  => [ 'relation' => 'and', 'rules' => [] ],
			'created_at'  => '',
			'updated_at'  => '',
			'last_error'  => null,
			'code_hash'   => '',
			'source'      => 'core-blueprint',
		];
	}

	public static function locations_for_type( string $type ): array {
		$locations = [];
		foreach ( self::LOCATION_IDS_BY_TYPE[ $type ] ?? [] as $location ) {
			$locations[ $location ] = self::location_label( $location );
		}
		return $locations;
	}

	public static function default_location( string $type ): string {
		$map = [
			'php'  => 'plugins_loaded',
			'css'  => 'frontend',
			'js'   => 'wp_footer',
			'html' => 'shortcode',
		];
		return $map[ $type ] ?? 'plugins_loaded';
	}

	public static function valid_location( string $type, string $location ): bool {
		return in_array( $location, self::LOCATION_IDS_BY_TYPE[ $type ] ?? [], true );
	}

	private static function location_label( string $location ): string {
		switch ( $location ) {
			case 'plugins_loaded':
				return __( 'Everywhere / early', 'core-blueprint' );
			case 'init':
				return __( 'WordPress init', 'core-blueprint' );
			case 'wp_loaded':
				return __( 'WordPress loaded', 'core-blueprint' );
			case 'admin_init':
				return __( 'Admin only', 'core-blueprint' );
			case 'wp_head':
				return __( 'Frontend head', 'core-blueprint' );
			case 'wp_footer':
				return __( 'Frontend footer', 'core-blueprint' );
			case 'admin_head':
				return __( 'Admin head', 'core-blueprint' );
			case 'admin_footer':
				return __( 'Admin footer', 'core-blueprint' );
			case 'shortcode':
				return __( 'Shortcode', 'core-blueprint' );
			case 'frontend':
				return __( 'Frontend', 'core-blueprint' );
			case 'admin':
				return __( 'Admin', 'core-blueprint' );
			case 'both':
				return __( 'Frontend and admin', 'core-blueprint' );
		}
		return $location;
	}
}
