<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\TemplateLibrary\DesignerScope;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated Mail-owned reusable asset operations. They NEVER write the
 * live Mail Designer document or bypass its TransactionalSave authority.
 */
final class SavedTemplateController {
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		foreach ( [ 'list' => 'GET', 'create' => 'POST', 'rename' => 'POST', 'duplicate' => 'POST', 'delete' => 'POST' ] as $action => $method ) {
			register_rest_route( 'core-blueprint/v1', '/mail-templates/' . $action, [
				'methods' => $method,
				'callback' => [ self::class, $action === 'list' ? 'listing' : $action ],
				'permission_callback' => [ self::class, 'permitted' ],
			] );
		}
	}

	public static function permitted( \WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return is_user_logged_in() && get_current_user_id() > 0
			&& current_user_can( 'manage_options' ) && DesignerState::is_enabled()
			&& is_string( $nonce ) && (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private static function exact( mixed $data, array $expected ): bool {
		if ( ! is_array( $data ) || array_is_list( $data ) ) return false;
		$keys = array_keys( $data );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}

	private static function reply( array $result, int $success_code = 200 ): \WP_REST_Response {
		$status = (string) ( $result['status'] ?? 'unknown' );
		$code = in_array( $status, [ 'created', 'renamed', 'deleted', 'ready' ], true )
			? $success_code
			: ( 'conflict' === $status ? 409
				: ( 'missing' === $status ? 404
					: ( 'refused' === $status ? 403 : 503 ) ) );
		return new \WP_REST_Response( $result, $code );
	}

	private static function payload( \WP_REST_Request $request, array $keys ): ?array {
		if ( strlen( $request->get_body() ) > 1150000 ) return null;
		$data = $request->get_json_params();
		return self::exact( $data, $keys ) ? $data : null;
	}

	public static function listing( \WP_REST_Request $request ): \WP_REST_Response {
		$origin = $request->get_param( 'origin' );
		$search = $request->get_param( 'search' );
		$cursor = $request->get_param( 'cursor' );
		$limit = $request->get_param( 'limit' );
		foreach ( [ $origin, $search, $cursor ] as $value ) {
			if ( null !== $value && ! is_string( $value ) ) return self::reply( [ 'status' => 'refused' ] );
		}
		if ( null !== $limit && ( ! is_int( $limit ) && ! is_string( $limit )
			|| ! ctype_digit( (string) $limit ) ) ) return self::reply( [ 'status' => 'refused' ] );
		try {
			$query = new TemplateQuery(
				search: $search ?? '', origin: $origin ?? '', limit: null === $limit ? 24 : (int) $limit
			);
		} catch ( \InvalidArgumentException ) {
			return self::reply( [ 'status' => 'refused' ] );
		}
		if ( '' !== $query->origin && ! in_array( $query->origin, [ 'personal', 'site' ], true ) ) {
			return self::reply( [ 'status' => 'refused' ] );
		}
		$page = SavedTemplateStore::page( $query, '' === ( $cursor ?? '' ) ? null : $cursor, get_current_user_id() );
		return self::reply( $page );
	}

	public static function create( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::payload( $request,
			[ 'title', 'visibility', 'resourceType', 'resourceKey', 'profileId', 'contextEpoch', 'document' ] );
		if ( null === $data || ! is_string( $data['title'] )
			|| ! is_string( $data['visibility'] ) || ! is_string( $data['resourceType'] )
			|| ! is_string( $data['resourceKey'] ) || ! is_string( $data['profileId'] )
			|| ! is_int( $data['contextEpoch'] ) || $data['contextEpoch'] < 0
			|| ! is_array( $data['document'] ) || $data['profileId'] !== 'base/mail'
			|| $data['resourceType'] !== Repository::MAIL
			|| ! PlatformTransport::authorized_resource(
				$data['profileId'], $data['resourceType'], $data['resourceKey']
			) ) {
			return self::reply( [ 'status' => 'refused' ] );
		}
		$token = $request->get_header( 'X-CB-Designer-Scope' );
		$scope = is_string( $token ) && '' !== $token
			? DesignerScope::verify( $token, $data['resourceType'], $data['resourceKey'] ) : null;
		if ( ( $scope['extension'] ?? null ) !== 'core-blueprint'
			|| ( $scope['designer'] ?? null ) !== 'mail' ) {
			return self::reply( [ 'status' => 'refused' ] );
		}
		return self::reply( SavedTemplateStore::create(
			$data['title'], $data['visibility'], get_current_user_id(), $data['document']
		), 201 );
	}

	public static function rename( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::payload( $request, [ 'id', 'revision', 'title' ] );
		if ( null === $data || ! is_string( $data['id'] )
			|| ! is_int( $data['revision'] ) || ! is_string( $data['title'] ) ) {
			return self::reply( [ 'status' => 'refused' ] );
		}
		return self::reply( SavedTemplateStore::rename(
			$data['id'], $data['revision'], $data['title'], get_current_user_id()
		) );
	}

	public static function duplicate( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::payload( $request, [ 'id', 'revision', 'title' ] );
		if ( null === $data || ! is_string( $data['id'] )
			|| ! is_int( $data['revision'] ) || ! is_string( $data['title'] ) ) {
			return self::reply( [ 'status' => 'refused' ] );
		}
		return self::reply( SavedTemplateStore::duplicate(
			$data['id'], $data['revision'], $data['title'], get_current_user_id()
		), 201 );
	}

	public static function delete( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::payload( $request, [ 'id', 'revision' ] );
		if ( null === $data || ! is_string( $data['id'] )
			|| ! is_int( $data['revision'] ) ) return self::reply( [ 'status' => 'refused' ] );
		return self::reply( SavedTemplateStore::delete(
			$data['id'], $data['revision'], get_current_user_id()
		) );
	}

	private function __construct() {}
}
