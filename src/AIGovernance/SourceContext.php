<?php
declare(strict_types=1);
/**
 * Evidence-based request/source attribution for AI Governance.
 *
 * REST, CLI and PHP are transports, not AI identities. Only the exact official
 * WordPress MCP Adapter runtime combined with its default HTTP route / CLI
 * command is attributed here. Unknown attribution remains unknown.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\AIGovernance;

use CoreBlueprint\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class SourceContext {
	private const OFFICIAL_MCP_ADAPTER_CLASS = 'WP\\MCP\\Core\\McpAdapter';

	/** @return array{transport:string,source_id:?string,source_label:?string,evidence:array<string,mixed>} */
	public static function detect(): array {
		if ( RequestContext::is_cli() ) {
			if ( self::is_official_mcp_cli() ) {
				return [
					'transport'    => 'mcp-stdio',
					'source_id'    => 'wordpress-mcp-adapter',
					'source_label' => 'WordPress MCP Adapter',
					'evidence'     => [ 'source_basis' => 'adapter-runtime+wp-cli-command' ],
				];
			}
			return [
				'transport' => 'cli',
				'source_id' => null,
				'source_label' => null,
				'evidence' => [],
			];
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			if ( self::is_official_mcp_http_route() ) {
				return [
					'transport'    => 'mcp-http',
					'source_id'    => 'wordpress-mcp-adapter',
					'source_label' => 'WordPress MCP Adapter',
					'evidence'     => [ 'source_basis' => 'adapter-runtime+rest-route' ],
				];
			}
			return [
				'transport' => 'rest',
				'source_id' => null,
				'source_label' => null,
				'evidence' => [],
			];
		}

		return [
			'transport' => 'php',
			'source_id' => null,
			'source_label' => null,
			'evidence' => [],
		];
	}

	private static function official_mcp_adapter_loaded(): bool {
		return class_exists( self::OFFICIAL_MCP_ADAPTER_CLASS );
	}

	private static function is_official_mcp_cli(): bool {
		if ( ! self::official_mcp_adapter_loaded() ) {
			return false;
		}
		$argv = $GLOBALS['argv'] ?? [];
		if ( ! is_array( $argv ) ) {
			return false;
		}
		$parts = array_map( static fn( $part ): string => (string) $part, $argv );
		$count = count( $parts );
		for ( $i = 0; $i < $count - 1; ++$i ) {
			if ( 'mcp-adapter' === $parts[ $i ] && 'serve' === $parts[ $i + 1 ] ) {
				return true;
			}
		}
		return false;
	}

	private static function is_official_mcp_http_route(): bool {
		if ( ! self::official_mcp_adapter_loaded() ) {
			return false;
		}
		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request classification.
			$route = wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$route = '/' . ltrim( $route, '/' );
		if ( '/mcp/mcp-adapter-default-server' === rtrim( $route, '/' ) ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}
		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( rest_get_url_prefix(), '/' ) : 'wp-json';
		$needle = '/' . $prefix . '/mcp/mcp-adapter-default-server';
		return str_ends_with( rtrim( $path, '/' ), $needle );
	}
}