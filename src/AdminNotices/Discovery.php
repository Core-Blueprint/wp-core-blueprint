<?php
declare(strict_types=1);
/**
 * Request-local discovery of WordPress admin notice producers.
 *
 * Discovery reads the public WP_Hook callback registry. It stores no notice
 * markup, action URLs, nonces or message bodies.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\AdminNotices;

defined( 'ABSPATH' ) || exit;

final class Discovery {

	public const HOOKS = [
		'admin_notices',
		'all_admin_notices',
		'network_admin_notices',
		'user_admin_notices',
	];

	/**
	 * @return array<string,array{id:string,label:string,kind:string,manageable:bool,protected:bool,hooks:list<string>,callback_count:int}>
	 */
	public static function sources(): array {
		$sources = [];

		foreach ( self::HOOKS as $hook ) {
			foreach ( self::callbacks( $hook ) as $entry ) {
				$source = $entry['source'];
				$id = (string) $source['id'];

				if ( ! isset( $sources[ $id ] ) ) {
					$sources[ $id ] = [
						'id'             => $id,
						'label'          => (string) $source['label'],
						'kind'           => (string) $source['kind'],
						'manageable'     => (bool) $source['manageable'],
						'protected'      => (bool) $source['protected'],
						'hooks'          => [],
						'callback_count' => 0,
					];
				}

				$sources[ $id ]['hooks'][ $hook ] = $hook;
				$sources[ $id ]['callback_count']++;
			}
		}

		foreach ( $sources as &$source ) {
			$source['hooks'] = array_values( $source['hooks'] );
			sort( $source['hooks'], SORT_STRING );
		}
		unset( $source );

		ksort( $sources, SORT_STRING );
		return $sources;
	}

	/**
	 * @return list<array{priority:int,callback:mixed,source:array<string,mixed>}>
	 */
	public static function callbacks( string $hook ): array {
		if ( ! in_array( $hook, self::HOOKS, true ) ) {
			return [];
		}

		global $wp_filter;
		$wp_hook = $wp_filter[ $hook ] ?? null;
		if ( ! $wp_hook instanceof \WP_Hook || ! is_array( $wp_hook->callbacks ) ) {
			return [];
		}

		$out = [];
		foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}
			foreach ( $callbacks as $entry ) {
				$callback = is_array( $entry ) ? ( $entry['function'] ?? null ) : null;
				if ( ! is_callable( $callback ) || self::is_runtime_callback( $callback ) ) {
					continue;
				}
				$out[] = [
					'priority' => (int) $priority,
					'callback' => $callback,
					'source'   => SourceResolver::resolve( $callback ),
				];
			}
		}
		return $out;
	}

	private static function is_runtime_callback( mixed $callback ): bool {
		return is_array( $callback )
			&& 2 === count( $callback )
			&& Runtime::class === $callback[0]
			&& 'govern_current_hook' === $callback[1];
	}

	private function __construct() {}
}
