<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/** Read-only request boundary for the offline Template Library browser. */
final class BrowserController {
	public const ROUTE = 'core-blueprint/v1/template-library';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( 'core-blueprint/v1', '/template-library/sources', [
			'methods' => 'GET',
			'callback' => [ self::class, 'sources' ],
			'permission_callback' => [ self::class, 'permitted' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/template-library/templates', [
			'methods' => 'GET',
			'callback' => [ self::class, 'templates' ],
			'permission_callback' => [ self::class, 'permitted' ],
		] );
	}

	public static function permitted( WP_REST_Request $request ): bool {
		// WordPress' cookie REST authentication verifies X-WP-Nonce before this callback.
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$token = trim( (string) $request->get_header( 'X-CB-Designer-Scope' ) );
		return '' !== $token ? null !== DesignerScope::verify( $token )
			: current_user_can( 'manage_options' );
	}

	public static function sources( WP_REST_Request $request ): WP_REST_Response {
		$scope = self::scope( $request );
		if ( false === $scope ) {
			return new WP_REST_Response( [ 'code' => 'invalid_scope' ], 403 );
		}
		$sources = TemplateSourceRegistry::sources();
		if ( null !== $scope ) {
			$sources = array_filter( $sources, static fn ( array $item ): bool =>
				$item['extension'] === $scope['extension'] && $item['designer'] === $scope['designer'] );
		}
		return new WP_REST_Response( [
			'sources' => array_map( static fn ( string $id, array $source ): array => [
				'id' => $id, 'extension' => $source['extension'], 'designer' => $source['designer'],
			], array_keys( $sources ), array_values( $sources ) ),
		] );
	}

	public static function templates( WP_REST_Request $request ): WP_REST_Response {
		$scope = self::scope( $request );
		if ( false === $scope ) {
			return new WP_REST_Response( [ 'code' => 'invalid_scope' ], 403 );
		}
		$params = [];
		foreach ( [ 'search', 'source', 'extension', 'designer', 'origin', 'category', 'locale', 'limit', 'cursor' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) ) {
				return new WP_REST_Response( [ 'code' => 'invalid_query' ], 400 );
			}
			$params[ $key ] = null === $value ? '' : (string) $value;
		}
		if ( '' !== $params['limit'] && ( ! ctype_digit( $params['limit'] ) || strlen( $params['limit'] ) > 2 ) ) {
			return new WP_REST_Response( [ 'code' => 'invalid_query' ], 400 );
		}
		if ( strlen( $params['cursor'] ) > 2048 ) {
			return new WP_REST_Response( [ 'code' => 'invalid_cursor' ], 400 );
		}
		try {
			$query = new TemplateQuery(
				search: $params['search'], source: $params['source'],
				extension: $params['extension'], designer: $params['designer'],
				origin: $params['origin'], category: $params['category'],
				locale: $params['locale'], limit: '' === $params['limit'] ? 24 : (int) $params['limit']
			);
		} catch ( \InvalidArgumentException ) {
			return new WP_REST_Response( [ 'code' => 'invalid_query' ], 400 );
		}
		if ( null === $scope ) {
			$page = TemplateSourceRegistry::browse_global( $query, '' === $params['cursor'] ? null : $params['cursor'] );
		} else {
			if ( ( '' !== $params['extension'] && $params['extension'] !== $scope['extension'] )
				|| ( '' !== $params['designer'] && $params['designer'] !== $scope['designer'] ) ) {
				return new WP_REST_Response( [ 'code' => 'scope_mismatch' ], 403 );
			}
			$page = TemplateSourceRegistry::browse_designer(
				$scope['extension'], $scope['designer'], $query,
				'' === $params['cursor'] ? null : $params['cursor']
			);
		}
		return new WP_REST_Response( [
			'items' => array_map( static fn ( TemplateMetadata $item ): array => [
				'source' => $item->source, 'extension' => $item->extension,
				'designer' => $item->designer, 'id' => $item->id,
				'title' => $item->title, 'description' => $item->description,
				'origin' => $item->origin, 'category' => $item->category,
				'locale' => $item->locale, 'version' => $item->version,
				'compatible' => $item->compatible,
			], $page->items ),
			'next_cursor' => $page->next_cursor,
		] );
	}

	/** @return array{extension:string,designer:string}|null|false */
	private static function scope( WP_REST_Request $request ): array|null|false {
		$token = trim( (string) $request->get_header( 'X-CB-Designer-Scope' ) );
		if ( '' === $token ) {
			return current_user_can( 'manage_options' ) ? null : false;
		}
		return DesignerScope::verify( $token ) ?? false;
	}
}
