<?php
declare(strict_types=1);
/**
 * WordPress MCP Adapter integration wiring.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class MCPIntegration {
	private static ?string $delegate_class = null;

	public static function boot(): void {
		add_filter( 'mcp_adapter_default_server_config', [ __CLASS__, 'wrap_default_server' ], PHP_INT_MAX );
	}

	/** @param mixed $config @return array<string,mixed> */
	public static function wrap_default_server( mixed $config ): array {
		$config = is_array( $config ) ? $config : [];
		$existing = $config['observability_handler'] ?? null;
		self::$delegate_class = (
			is_string( $existing )
			&& '' !== $existing
			&& MCPObservabilityHandler::class !== $existing
		) ? $existing : null;
		$config['observability_handler'] = MCPObservabilityHandler::class;
		return $config;
	}

	public static function delegate_class(): ?string {
		return self::$delegate_class;
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$delegate_class = null;
	}
}
