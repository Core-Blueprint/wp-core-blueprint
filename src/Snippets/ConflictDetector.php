<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Detect snippet runtimes that may execute alongside Core Blueprint Snippets.
 *
 * Coexistence is intentionally warning-only: migrations can be performed one
 * snippet at a time. Imported snippets remain disabled, so Core Blueprint never
 * auto-runs a migrated copy while the source plugin is still active.
 */
final class ConflictDetector {
	private const KNOWN = [
		'easy-code-manager/easy-code-manager.php' => 'Fluent Snippets',
	];

	/** @return array<string,string> plugin basename => label */
	public static function active(): array {
		$active = get_option( 'active_plugins', [] );
		$active = is_array( $active ) ? $active : [];

		$network = is_multisite() ? get_site_option( 'active_sitewide_plugins', [] ) : [];
		$network = is_array( $network ) ? array_keys( $network ) : [];

		$active = array_values( array_unique( array_merge( $active, $network ) ) );
		$known  = (array) apply_filters( 'core_blueprint_snippets_conflicting_plugins', self::KNOWN );
		$out    = [];

		foreach ( $known as $basename => $label ) {
			if ( is_string( $basename ) && is_string( $label ) && in_array( $basename, $active, true ) ) {
				$out[ $basename ] = $label;
			}
		}

		// Fluent Snippets exposes this constant immediately from its bootstrap.
		// This catches non-standard plugin-directory names as well.
		if ( defined( 'FLUENT_SNIPPETS_PLUGIN_PATH' ) && ! in_array( 'Fluent Snippets', $out, true ) ) {
			$out['fluent-snippets'] = 'Fluent Snippets';
		}

		return $out;
	}

	public static function has_conflict(): bool {
		return ! empty( self::active() );
	}
}
