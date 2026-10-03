<?php
declare(strict_types=1);
/**
 * Controlled registry for dedicated retention stores.
 *
 * This is intentionally separate from AuditLog RetentionPolicy: each entry
 * represents a dedicated datastore with its own retention window and prune
 * implementation. The prune callable receives the effective retention days. It is not an AuditLog category and not a generic job runner.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\Governance;

defined( 'ABSPATH' ) || exit;

final class RetentionStoreRegistry {
	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/';

	/** @var array<string,array{id:string,label:string,days:int,prune:callable,settings_url:string}> */
	private static array $stores = [];

	/** @param array{id:string,label:string,days:int,prune:callable,settings_url?:string} $definition */
	public static function register( array $definition ): bool {
		$id    = isset( $definition['id'] ) ? (string) $definition['id'] : '';
		$label = isset( $definition['label'] ) ? trim( (string) $definition['label'] ) : '';
		$days  = isset( $definition['days'] ) ? (int) $definition['days'] : -1;
		$prune = $definition['prune'] ?? null;
		$url   = isset( $definition['settings_url'] ) ? (string) $definition['settings_url'] : '';

		if (
			1 !== preg_match( self::ID_PATTERN, $id )
			|| '' === $label
			|| $days < 0
			|| ! is_callable( $prune )
			|| isset( self::$stores[ $id ] )
		) {
			return false;
		}

		self::$stores[ $id ] = [
			'id'           => $id,
			'label'        => $label,
			'days'         => $days,
			'prune'        => $prune,
			'settings_url' => $url,
		];
		return true;
	}

	/** @return array<string,array{id:string,label:string,days:int,settings_url:string}> */
	public static function snapshot(): array {
		$out = [];
		foreach ( self::$stores as $id => $store ) {
			$out[ $id ] = [
				'id' => $store['id'],
				'label' => $store['label'],
				'days' => $store['days'],
				'settings_url' => $store['settings_url'],
			];
		}
		return $out;
	}

	/** @internal @return array<string,array{id:string,label:string,days:int,prune:callable,settings_url:string}> */
	public static function all(): array {
		return self::$stores;
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$stores = [];
	}
}
