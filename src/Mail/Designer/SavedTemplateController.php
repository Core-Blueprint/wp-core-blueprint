<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\DataExchange\Engine;
use CoreBlueprint\Core\DataExchange\Foundation;
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
		foreach ( [ 'list' => 'GET', 'create' => 'POST', 'rename' => 'POST', 'duplicate' => 'POST', 'delete' => 'POST', 'export' => 'POST', 'preview' => 'POST', 'import' => 'POST' ] as $action => $method ) {
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
					: ( 'refused' === $status ? 403 : ( 'invalid' === $status ? 400 : ( 'unsupported' === $status ? 422 : 503 ) ) ) ) );
		return new \WP_REST_Response( $result, $code );
	}

	private static function payload( \WP_REST_Request $request, array $keys, int $limit = 1150000 ): ?array {
		if ( strlen( $request->get_body() ) > $limit ) return null;
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


	/** Export one authorized saved Mail asset as an exact Data Exchange envelope. */
	public static function export( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::payload( $request, [ 'id', 'revision' ] );
		if ( null === $data || ! is_string( $data['id'] ) || ! SavedTemplateStore::valid_id( $data['id'] )
			|| ! is_int( $data['revision'] ) || $data['revision'] < 1 ) {
			return self::reply( [ 'status' => 'invalid' ] );
		}
		$json = Engine::export_json( 'core-blueprint', SavedTemplatePortability::ENTITY, [
			'source_id' => $data['id'], 'revision' => $data['revision'],
		] );
		if ( is_wp_error( $json ) ) {
			return self::reply( [ 'status' => $json->get_error_code() === 'cb_mail_portability_media_unsupported'
				? 'unsupported' : 'refused' ] );
		}
		return self::reply( [ 'status' => 'ready', 'data' => $json ] );
	}

	/** Strict bounded payload, no uploaded server files and no preview mutations. */
	private static function import_payload( \WP_REST_Request $request, bool $apply ): ?array {
		$data = self::payload( $request,
			$apply ? [ 'file', 'destinationId', 'fingerprint' ] : [ 'file', 'destinationId' ],
			2500000
		);
		if ( null === $data || ! is_string( $data['file'] )
			|| strlen( $data['file'] ) < 1 || strlen( $data['file'] ) > 1150000
			|| ! is_string( $data['destinationId'] )
			|| ! SavedTemplateStore::valid_id( $data['destinationId'] )
			|| ( $apply && ( ! is_string( $data['fingerprint'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $data['fingerprint'] ) ) ) ) return null;
		return $data;
	}

	private static function import_context( string $destination ): array {
		return [ 'intent' => 'create_personal_copy', 'destination_id' => $destination ];
	}

	public static function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::import_payload( $request, false );
		if ( null === $data ) return self::reply( [ 'status' => 'invalid' ] );
		$preview = Engine::preview_json( $data['file'], Foundation::MODE_CREATE_ONLY,
			self::import_context( $data['destinationId'] )
		);
		if ( is_wp_error( $preview ) || ! ( $preview['valid'] ?? false )
			|| ( $preview['record_count'] ?? 0 ) !== 1
			|| ( $preview['counts'][Foundation::OP_CREATE] ?? 0 ) !== 1
			|| ! is_string( $preview['fingerprint'] ?? null ) ) {
			return self::reply( [ 'status' => 'invalid' ] );
		}
		// Expose only the public preview contract; never the document/opaque plan.
		return self::reply( [ 'status' => 'ready', 'preview' => $preview ] );
	}

	public static function import( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::import_payload( $request, true );
		if ( null === $data ) return self::reply( [ 'status' => 'invalid' ] );
		$result = Engine::apply_json( $data['file'], Foundation::MODE_CREATE_ONLY,
			$data['fingerprint'], self::import_context( $data['destinationId'] )
		);
		if ( is_wp_error( $result ) ) return self::reply( [ 'status' => 'invalid' ] );
		if ( ( $result['status'] ?? null ) !== 'complete'
			|| ( $result['applied_count'] ?? null ) !== 1
			|| ( $result['record_count'] ?? null ) !== 1
			|| ( $result['items'][0]['reference'] ?? null ) !== $data['destinationId'] ) {
			return self::reply( [ 'status' => 'unknown' ] );
		}
		return self::reply( [
			'status' => 'created', 'id' => $data['destinationId'], 'revision' => 1,
		], 201 );
	}

	private function __construct() {}
}
