<?php
declare(strict_types=1);
/**
 * AbstractModule - optional convenience base class for security modules.
 *
 * Provides the housekeeping that every module ends up needing:
 *
 *   - A `feature( $id )` helper that checks enablement against the registry
 *     without the module having to repeat its own slug on every line. Two
 *     slightly different styles existed before this class: Fingerprint
 *     called `ModuleRegistry::is_feature_enabled( 'fingerprint', $id )`
 *     eight times with the slug hardcoded; Headers had its own private
 *     `feature()` wrapper. This class lifts that wrapper into the base so
 *     every module picks it up identically.
 *
 *   - Sensible defaults for the parts of the {@see Module} contract that
 *     most modules don't override (badges() returns []).
 *
 * Extending this class is optional. Third-party CB plugins may still
 * implement the {@see Module} interface directly if they prefer - the
 * registry only cares about the interface.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

defined( 'ABSPATH' ) || exit;

abstract class AbstractModule implements Module {

	/**
	 * Is a specific feature on this module currently enabled?
	 *
	 * Thin wrapper around {@see ModuleRegistry::is_feature_enabled()} that
	 * binds the slug to the current class via `$this->slug()`. Keeps the
	 * module's slug defined in exactly one place - the slug() method -
	 * and makes boot()/hook callbacks read as domain logic instead of
	 * registry plumbing.
	 *
	 *     if ( $this->feature( 'remove_wp_version_meta' ) ) {
	 *         remove_action( 'wp_head', 'wp_generator' );
	 *     }
	 */
	protected function feature( string $id ): bool {
		return ModuleRegistry::is_feature_enabled( $this->slug(), $id );
	}

	/**
	 * Default: no module-level badges. Override when your module qualifies
	 * for tech-spec, OWASP, CWE, or CB-baseline badges (see {@see UI}).
	 *
	 * @return array<int, array>
	 */
	public function badges(): array {
		return [];
	}
}
