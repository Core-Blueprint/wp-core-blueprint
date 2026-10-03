<?php
declare(strict_types=1);
/**
 * Public Automation Foundation trigger registration and discovery boundary.
 *
 * Extensions register during cb_core_register_automation_capabilities. Base-owned
 * subsystems use register_base(); external code must never claim Base ownership.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Automation;

use CoreBlueprint\Core\Automation\Internal\CapabilityRegistry;

defined( 'ABSPATH' ) || exit;

final class TriggerRegistry {

	/** @param array<string,mixed> $definition */
	public static function register( array $definition ): bool {
		return CapabilityRegistry::register_trigger( $definition, false );
	}

	/**
	 * Register a Base-owned trigger.
	 *
	 * Provider identity is forced to `core-blueprint`; any supplied provider
	 * value is ignored. Internal Base lifecycle API, not a public extension path.
	 *
	 * @internal
	 * @param array<string,mixed> $definition
	 */
	public static function register_base( array $definition ): bool {
		unset( $definition['provider'] );
		return CapabilityRegistry::register_trigger( $definition, true );
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		return CapabilityRegistry::triggers();
	}

	/** @return array<string,mixed>|null */
	public static function get( string $provider, string $id ): ?array {
		return CapabilityRegistry::trigger( $provider, $id );
	}

	/** @internal */
	public static function _reset_for_testing(): void {
		CapabilityRegistry::reset_for_tests();
	}
}
