<?php
declare(strict_types=1);
/**
 * Base-owned WordPress plugin lifecycle for Core Blueprint extensions.
 *
 * Global extension On/Off is a platform concern. Extension plugins expose
 * inventory metadata and optional Dashboard shortcuts, while Base resolves the
 * canonical plugin_file server-side and owns activation/deactivation through
 * WordPress' native plugin APIs.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

defined( 'ABSPATH' ) || exit;

final class ExtensionLifecycle {

	/** @return string[] */
	public static function ids(): array {
		return array_keys( Extensions::detected() );
	}

	/** @return array<string,mixed>|null */
	public static function extension( string $id ): ?array {
		if ( ! ExtensionRegistry::is_valid_id( $id ) ) {
			return null;
		}

		$extensions = Extensions::detected();
		$extension  = $extensions[ $id ] ?? null;
		if ( ! is_array( $extension ) || empty( $extension['installed'] ) ) {
			return null;
		}

		$plugin_file = isset( $extension['plugin_file'] ) && is_string( $extension['plugin_file'] )
			? trim( wp_normalize_path( $extension['plugin_file'] ) )
			: '';
		if ( '' === $plugin_file || $plugin_file === CB_CORE_BASENAME || $plugin_file !== plugin_basename( $plugin_file ) ) {
			return null;
		}

		return $extension;
	}

	/**
	 * Capability required to mutate the current extension lifecycle state.
	 *
	 * Network-active plugins may only be deactivated by a network plugin
	 * manager. All other transitions use WordPress' normal activate_plugins
	 * capability.
	 */
	public static function capability( string $id ): ?string {
		$extension = self::extension( $id );
		if ( null === $extension ) {
			return null;
		}

		$plugin_file = (string) $extension['plugin_file'];
		return self::is_network_active( $plugin_file ) ? 'manage_network_plugins' : 'activate_plugins';
	}

	public static function is_active( string $id ): bool {
		$extension = self::extension( $id );
		return null !== $extension && ! empty( $extension['active'] );
	}

	/**
	 * Apply one native WordPress plugin lifecycle transition.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_active( string $id, bool $active ): true|\WP_Error {
		$extension = self::extension( $id );
		if ( null === $extension ) {
			return new \WP_Error( 'cb_core_unknown_extension', __( 'Unknown extension.', 'core-blueprint' ) );
		}

		self::ensure_plugin_api();

		$plugin_file = (string) $extension['plugin_file'];
		$current     = self::plugin_is_active( $plugin_file );
		if ( $current === $active ) {
			return true;
		}

		if ( $active ) {
			$result = activate_plugin( $plugin_file, '', false, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			$network_wide = self::is_network_active( $plugin_file );
			deactivate_plugins( $plugin_file, false, $network_wide );
		}

		Extensions::invalidate_cache();
		wp_clean_plugins_cache( true );

		if ( self::plugin_is_active( $plugin_file ) !== $active ) {
			return new \WP_Error(
				'cb_core_extension_lifecycle_failed',
				$active
					? __( 'The extension could not be activated.', 'core-blueprint' )
					: __( 'The extension could not be deactivated.', 'core-blueprint' )
			);
		}

		return true;
	}

	private static function ensure_plugin_api(): void {
		if ( ! function_exists( 'activate_plugin' ) || ! function_exists( 'deactivate_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	private static function plugin_is_active( string $plugin_file ): bool {
		self::ensure_plugin_api();
		return is_plugin_active( $plugin_file ) || self::is_network_active( $plugin_file );
	}

	private static function is_network_active( string $plugin_file ): bool {
		return function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file );
	}
}
