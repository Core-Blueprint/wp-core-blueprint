<?php
declare(strict_types=1);
/**
 * Extension inventory projection.
 *
 * Base owns WordPress inventory facts. Active extensions may enrich that inventory
 * only through the controlled public ExtensionRegistry. First-party header/folder
 * discovery is a convenience for installed/inactive visibility, not a trust or
 * compatibility boundary.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

use CoreBlueprint\Core\Modules\Status;

defined( 'ABSPATH' ) || exit;

final class Extensions {

	/** @var array<string,array<string,mixed>>|null */
	private static ?array $cache = null;

	public static function init(): void {
		add_action( 'activated_plugin', [ self::class, 'invalidate_cache' ] );
		add_action( 'deactivated_plugin', [ self::class, 'invalidate_cache' ] );
	}

	/**
	 * Return the current extension inventory keyed by canonical extension ID.
	 *
	 * @return array<string,array{
	 *   id:string,plugin_file:string,name:string,version:string,description:string,
	 *   installed:bool,active:bool,registered:bool,compatible:?bool,
	 *   health:string,health_detail:string,menu_url:string,status_id:string
	 * }>
	 */
	public static function detected(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins     = get_plugins();
		$definitions = ExtensionRegistry::definitions();
		$by_file     = [];
		foreach ( $definitions as $id => $definition ) {
			$by_file[ $definition['plugin_file'] ] = $id;
		}

		$extensions = [];
		foreach ( $plugins as $plugin_file => $data ) {
			if ( $plugin_file === CB_CORE_BASENAME || ! is_array( $data ) ) {
				continue;
			}

			$folder      = dirname( $plugin_file );
			$author      = trim( wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ) );
			$first_party = str_starts_with( $folder, 'core-blueprint-' ) && 'Core Blueprint' === $author;
			$registered_id = $by_file[ $plugin_file ] ?? '';
			if ( ! $first_party && '' === $registered_id ) {
				continue;
			}

			$id = '' !== $registered_id ? $registered_id : $folder;
			if ( ! ExtensionRegistry::is_valid_id( $id ) ) {
				continue;
			}

			$definition = $definitions[ $id ] ?? null;
			$extensions[ $id ] = self::project( $id, $plugin_file, $data, is_array( $definition ) ? $definition : null );
		}

		ksort( $extensions, SORT_STRING );
		if ( did_action( 'init' ) > 0 || doing_action( 'init' ) ) {
			self::$cache = $extensions;
		}
		return $extensions;
	}

	public static function invalidate_cache(): void {
		self::$cache = null;
	}

	/**
	 * @param array<string,mixed> $plugin_data
	 * @param array{id:string,plugin_file:string,requires_api:string,requires_base:string,menu_url:string,status_id:string}|null $definition
	 * @return array<string,mixed>
	 */
	private static function project( string $id, string $plugin_file, array $plugin_data, ?array $definition ): array {
		$active     = is_plugin_active( $plugin_file ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file ) );
		$registered = null !== $definition && $active;
		$compatible = $registered ? self::compatibility( $definition ) : null;
		$status_id  = $registered ? $definition['status_id'] : '';
		$health     = 'unknown';
		$detail     = '';
		$menu_url   = $registered ? $definition['menu_url'] : '';

		if ( $registered && true === $compatible && '' !== $status_id ) {
			$status = Status::get( $status_id );
			if ( is_array( $status ) ) {
				$state = (string) ( $status['state'] ?? '' );
				if ( in_array( $state, [ 'ok', 'warn', 'err', 'off' ], true ) ) {
					$health = $state;
					$detail = sanitize_text_field( (string) ( $status['detail'] ?? '' ) );
				}
				if ( '' === $menu_url ) {
					$menu_url = wp_validate_redirect( esc_url_raw( (string) ( $status['url'] ?? '' ) ), '' );
				}
			}
		}

		return [
			'id'            => $id,
			'plugin_file'   => $plugin_file,
			'name'          => sanitize_text_field( (string) ( $plugin_data['Name'] ?? $id ) ),
			'version'       => sanitize_text_field( (string) ( $plugin_data['Version'] ?? '' ) ),
			'description'   => wp_strip_all_tags( (string) ( $plugin_data['Description'] ?? '' ) ),
			'installed'     => true,
			'active'        => $active,
			'registered'    => $registered,
			'compatible'    => $compatible,
			'health'        => $health,
			'health_detail' => $detail,
			'menu_url'      => $menu_url,
			'status_id'     => $status_id,
		];
	}

	/**
	 * @param array{id:string,plugin_file:string,requires_api:string,requires_base:string,menu_url:string,status_id:string} $definition
	 */
	private static function compatibility( array $definition ): bool {
		$current_api = defined( 'CB_CORE_API_VERSION' ) ? (string) CB_CORE_API_VERSION : '0.0';
		$required_api = $definition['requires_api'];
		$current_parts = array_map( 'intval', explode( '.', $current_api, 2 ) );
		$required_parts = array_map( 'intval', explode( '.', $required_api, 2 ) );

		if ( ( $current_parts[0] ?? -1 ) !== ( $required_parts[0] ?? -2 ) ) {
			return false;
		}
		if ( ( $current_parts[1] ?? 0 ) < ( $required_parts[1] ?? 0 ) ) {
			return false;
		}

		$requires_base = $definition['requires_base'];
		if ( '' !== $requires_base ) {
			$current_base = defined( 'CB_CORE_VERSION' ) ? (string) CB_CORE_VERSION : '0.0.0';
			if ( version_compare( $current_base, $requires_base, '<' ) ) {
				return false;
			}
		}

		return true;
	}
}
