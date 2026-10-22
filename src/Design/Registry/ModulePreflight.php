<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side registered-module check. The integrator calls this BEFORE WP
 * prints the import map; it cannot register scripts or manufacture installed
 * artifact evidence.
 */
final class ModulePreflight {
	/** @param callable(string):bool $is_registered Trusted WordPress registry lookup. */
	public function verify( FrozenRegistry $registry, callable $is_registered ): array {
		$missing = [];
		foreach ( $registry->modules() as $module ) {
			try {
				$registered = true === $is_registered( $module['id'] );
			} catch ( \Throwable $error ) {
				$registered = false;
			}
			if ( ! $registered ) {
				$missing[] = $module['id'];
			}
		}
		sort( $missing, SORT_STRING );
		return [
			'ready' => [] === $missing,
			'code' => [] === $missing ? null : 'HOST_MODULE_MISSING',
			'missing' => $missing,
		];
	}
}
