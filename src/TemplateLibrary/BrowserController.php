<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/** Template Library read, apply and provider-owned management boundaries. */
final class BrowserController {
	public const ROUTE = 'core-blueprint/v1/template-library';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( 'core-blueprint/v1', '/template-library/manage', [
			'methods' => 'POST',
			'callback' => [ self::class, 'manage' ],
			'permission_callback' => [ self::class, 'manage_permitted' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/template-library/apply', [
			'methods' => 'POST',
			'callback' => [ self::class, 'apply' ],
			'permission_callback' => [ self::class, 'apply_permitted' ],
		] );
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

	/** All management writes require BOTH WordPress REST CSRF and live scope/admin authority. */
	public static function manage_permitted( WP_REST_Request $request ): bool {
		return self::apply_permitted( $request ) && self::permitted( $request );
	}

	/** Shared transport only; provider is the sole domain writer/exporter. */
	public static function manage( WP_REST_Request $request ): WP_REST_Response {
		$scope = self::scope( $request );
		if ( false === $scope || ! self::manage_permitted( $request ) ) {
			return new WP_REST_Response( [ 'status' => 'refused' ], 403 );
		}
		if ( strlen( $request->get_body() ) > 4096 ) {
			return new WP_REST_Response( [ 'status' => 'invalid' ], 400 );
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return new WP_REST_Response( [ 'status' => 'invalid' ], 400 );
		}
		$keys = array_keys( $data );
		sort( $keys, SORT_STRING );
		if ( $keys !== [ 'action', 'input', 'revision', 'source', 'templateId' ]
			|| ! is_string( $data['source'] ) || ! TemplateQuery::valid_id( $data['source'] )
			|| ! is_string( $data['templateId'] ) || ! TemplateQuery::valid_id( $data['templateId'] )
			|| ! is_string( $data['action'] )
			|| ! is_int( $data['revision'] ) || $data['revision'] < 1
			|| ! is_array( $data['input'] ) ) {
			return new WP_REST_Response( [ 'status' => 'invalid' ], 400 );
		}
		$result = TemplateSourceRegistry::perform_action(
			$data['source'], $data['templateId'], $data['action'],
			$data['revision'], $data['input'], $scope
		);
		$status = $result['status'];
		$http = match ( $status ) {
			'created' => 201,
			'renamed', 'deleted', 'ready' => 200,
			'conflict' => 409,
			'missing' => 404,
			'refused' => 403,
			'invalid' => 400,
			'unsupported' => 422,
			default => 503,
		};
		return new WP_REST_Response( $result, $http );
	}

	/** Apply returns a document to the browser; it never writes to WordPress. */
	public static function apply_permitted( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return is_user_logged_in() && get_current_user_id() > 0
			&& is_string( $nonce ) && (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public static function apply( WP_REST_Request $request ): WP_REST_Response {
		$data = $request->get_json_params();
		$keys = [ 'source', 'templateId', 'profileId', 'resourceType', 'resourceKey', 'contextEpoch' ];
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return new WP_REST_Response( [ 'code' => 'invalid_apply_request' ], 400 );
		}
		$actual = array_keys( $data );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $actual !== $keys
			|| ! is_int( $data['contextEpoch'] ) || $data['contextEpoch'] < 0
			|| ! is_string( $data['source'] ) || ! TemplateQuery::valid_id( $data['source'] )
			|| ! is_string( $data['templateId'] ) || ! TemplateQuery::valid_id( $data['templateId'] )
			|| ! is_string( $data['profileId'] ) || ! in_array( $data['profileId'], [ 'base/mail', 'base/reports' ], true )
			|| ! is_string( $data['resourceType'] ) || ! TemplateQuery::valid_id( $data['resourceType'] )
			|| ! is_string( $data['resourceKey'] ) || '' === $data['resourceKey']
			|| strlen( $data['resourceKey'] ) > 191 ) {
			return new WP_REST_Response( [ 'code' => 'invalid_apply_request' ], 400 );
		}
		$token = trim( (string) $request->get_header( 'X-CB-Designer-Scope' ) );
		$scope = '' !== $token
			? DesignerScope::verify( $token, $data['resourceType'], $data['resourceKey'] ) : null;
		if ( null === $scope ) {
			return new WP_REST_Response( [ 'code' => 'apply_refused' ], 403 );
		}
		$document = TemplateSourceRegistry::document_for_apply(
			$data['source'], $data['templateId'], $data['profileId'],
			$data['resourceType'], $data['resourceKey'], $scope
		);
		if ( null === $document ) {
			return new WP_REST_Response( [ 'code' => 'apply_refused' ], 403 );
		}
		return new WP_REST_Response( [
			'status' => 'ready',
			'source' => $data['source'], 'templateId' => $data['templateId'],
			'profileId' => $data['profileId'],
			'resourceType' => $data['resourceType'], 'resourceKey' => $data['resourceKey'],
			'contextEpoch' => $data['contextEpoch'],
			'actorId' => get_current_user_id(), 'blogId' => get_current_blog_id(),
			'document' => $document,
		], 200 );
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
		// After Designer-scope filtering, access only those approved source facets.
		$categories = TemplateSourceRegistry::source_categories( array_keys( $sources ) );
		return new WP_REST_Response( [
			'sources' => array_map( static fn ( string $id, array $source ): array => [
				'id' => $id, 'extension' => $source['extension'], 'designer' => $source['designer'],
				'categories' => $categories[$id] ?? [],
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
				'navigation' => TemplateSourceRegistry::navigation( $item, null === $scope )->public_links( null === $scope ),
				'actions' => TemplateSourceRegistry::actions( $item ),
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
