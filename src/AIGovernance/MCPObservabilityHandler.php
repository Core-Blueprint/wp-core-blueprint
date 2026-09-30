<?php
declare(strict_types=1);
/**
 * Composing observability handler for the official WordPress MCP Adapter.
 *
 * The previous default-server handler is delegated to first so Core Blueprint
 * augments rather than replaces an existing observability integration.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

defined( 'ABSPATH' ) || exit;

final class MCPObservabilityHandler implements McpObservabilityHandlerInterface {
	private ?McpObservabilityHandlerInterface $delegate = null;

	public function __construct() {
		$class = MCPIntegration::delegate_class();
		if (
			null === $class
			|| self::class === $class
			|| ! class_exists( $class )
			|| ! is_subclass_of( $class, McpObservabilityHandlerInterface::class )
		) {
			return;
		}

		try {
			$delegate = new $class();
			if ( $delegate instanceof McpObservabilityHandlerInterface ) {
				$this->delegate = $delegate;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	public function record_event( string $event, array $tags = [], ?float $duration_ms = null ): void {
		if ( null !== $this->delegate ) {
			try {
				$this->delegate->record_event( $event, $tags, $duration_ms );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		try {
			MCPEventProjector::record( $event, $tags, $duration_ms );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
