<?php
declare(strict_types=1);
/**
 * Public Automation Foundation action registration and discovery boundary.
 *
 * Action executors are intentionally withheld from public discovery snapshots.
 * Governed execution is available only through ActionInvoker; direct callback
 * access remains an internal Foundation implementation detail.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Automation;

use CoreBlueprint\Core\Automation\Internal\CapabilityRegistry;

defined( 'ABSPATH' ) || exit;

final class ActionRegistry {

	/** @param array<string,mixed> $definition */
	public static function register( array $definition ): bool {
		return CapabilityRegistry::register_action( $definition, false );
	}

	/**
	 * Register a Base-owned action.
	 *
	 * Provider identity is forced to `core-blueprint`; any supplied provider
	 * value is ignored. Internal Base lifecycle API, not a public extension path.
	 *
	 * @internal
	 * @param array<string,mixed> $definition
	 */
	public static function register_base( array $definition ): bool {
		unset( $definition['provider'] );
		return CapabilityRegistry::register_action( $definition, true );
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		return CapabilityRegistry::actions();
	}

	/** @return array<string,mixed>|null */
	public static function get( string $provider, string $id ): ?array {
		return CapabilityRegistry::action( $provider, $id );
	}

	/** @internal */
	public static function _reset_for_testing(): void {
		CapabilityRegistry::reset_for_tests();
	}
}
