<?php
declare(strict_types=1);
/**
 * Lazy private catalog for Core Admin ES modules.
 *
 * Factories are grouped in private definition classes and evaluated only after
 * ScreenAssetRegistry selects a consumer. Runtime/localized data construction
 * therefore stays behind the consuming module.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminModuleCatalog {

	private const FOUNDATION_MODULES = [
		'@cb-core/icon',
		'@cb-core/modal',
		'@cb-core/toast',
		'@cb-core/clipboard',
		'@cb-core/select-picker',
		'@cb-core/reorder',
	];

	/** @var array<string,bool> */
	private static array $enqueued = [];

	/** @return string[] Private module IDs known to Base. */
	public static function ids(): array {
		return array_keys( self::factories() );
	}

	public static function enqueue( string $id, ScreenContext $context ): void {
		if ( isset( self::$enqueued[ $id ] ) ) {
			return;
		}

		$factory = self::factories()[ $id ] ?? null;
		if ( ! is_callable( $factory ) ) {
			return;
		}

		$module = $factory();
		self::$enqueued[ $id ] = true;

		foreach ( (array) ( $module['deps'] ?? [] ) as $dependency ) {
			$dependency = (string) $dependency;
			if ( in_array( $dependency, self::FOUNDATION_MODULES, true ) ) {
				continue;
			}
			self::enqueue( $dependency, $context );
		}

		wp_enqueue_script_module(
			(string) $module['id'],
			CB_CORE_URL . 'assets/js/' . (string) $module['src'],
			(array) ( $module['deps'] ?? [] ),
			CB_CORE_VERSION
		);

		$module_data = $module['data'] ?? null;
		if ( ! empty( $module_data ) && is_array( $module_data ) ) {
			$module_id = (string) $module['id'];
			add_filter(
				"script_module_data_{$module_id}",
				static function ( array $existing ) use ( $module_data ): array {
					return array_merge( $existing, $module_data );
				}
			);
		}
	}

	/** @return array<string,callable():array<string,mixed>> */
	private static function factories(): array {
		static $factories = null;
		if ( is_array( $factories ) ) {
			return $factories;
		}

		$admin_nonce = wp_create_nonce( 'cb_core_admin' );
		$ajax_url    = admin_url( 'admin-ajax.php' );
		$save_status = [
			'saving'          => __( 'Saving…', 'core-blueprint' ),
			'saved'           => __( 'Saved', 'core-blueprint' ),
			'saveFailed'      => __( 'Could not save - try again', 'core-blueprint' ),
			'saveFailedShort' => __( 'Save failed.', 'core-blueprint' ),
			'networkError'    => __( 'Network error - try again.', 'core-blueprint' ),
		];

		$groups = [
			AdminModuleDefinitionsCore::class,
			AdminModuleDefinitionsPreferences::class,
			AdminModuleDefinitionsSecurity::class,
			AdminModuleDefinitionsScannerNotes::class,
			AdminModuleDefinitionsConsoleAux::class,
		];

		$factories = [];
		foreach ( $groups as $group ) {
			$factories = array_merge( $factories, $group::factories( $admin_nonce, $ajax_url, $save_status ) );
		}

		return $factories;
	}
}
