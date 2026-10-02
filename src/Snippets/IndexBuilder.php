<?php
declare(strict_types=1);

namespace CB\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class IndexBuilder {
	public static function rebuild_from_registry( array $registry, bool $module_enabled ): bool {
		$manifest = [];
		if ( $module_enabled ) {
			foreach ( $registry as $id => $meta ) {
				if ( ! is_array( $meta ) || empty( $meta['enabled'] ) ) {
					continue;
				}
				$manifest[ (string) $id ] = [
					'id'         => (string) $id,
					'type'       => (string) ( $meta['type'] ?? 'php' ),
					'location'   => (string) ( $meta['location'] ?? 'plugins_loaded' ),
					'priority'   => (int) ( $meta['priority'] ?? 10 ),
					'shortcode'  => (string) ( $meta['shortcode'] ?? '' ),
					'code_hash'  => (string) ( $meta['code_hash'] ?? '' ),
					'conditions' => is_array( $meta['conditions'] ?? null ) ? $meta['conditions'] : [ 'relation' => 'and', 'rules' => [] ],
				];
			}
		}

		$contents = "<?php\n\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $manifest, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- deterministic PHP array serialization for the runtime manifest, not debug output.
		return AtomicFile::write( Paths::runtime_index(), $contents );
	}

	public static function load_runtime_manifest(): array {
		$path = Paths::runtime_index();
		if ( ! is_file( $path ) ) {
			return [];
		}

		try {
			$data = require $path;
		} catch ( \Throwable $e ) {
			error_log( 'Core Blueprint Snippets runtime index could not be loaded: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- corrupted-runtime fallback must remain independent of the generated index.
			return [];
		}

		return is_array( $data ) ? $data : [];
	}
}
