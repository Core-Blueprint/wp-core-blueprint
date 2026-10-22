<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Editor;

use CoreBlueprint\Core\Design\Bootstrap as DesignerBootstrap;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Integrity\ApprovedManifestSource;
use CoreBlueprint\Core\Design\Persistence\BaseDocumentRepositoryPort;
use CoreBlueprint\Core\Design\Persistence\OperationLedger;
use CoreBlueprint\Core\Design\Persistence\SaveEnvelope;
use CoreBlueprint\Core\Design\Persistence\TransactionalSave;
use CoreBlueprint\Core\Design\Profile\Mail\PlatformAdapter as Mail;
use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter as Reports;
use CoreBlueprint\Core\Design\Registry\FrozenRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * One authenticated REST transport for the one Base Designer Save Authority.
 * All writes delegate to TransactionalSave and the existing Repository.
 *
 * No old project-format deserialization, fallback editor or dual-write.
 * Unrecognized/legacy documents fail with SCHEMA_UNSUPPORTED_GENERATION.
 *
 * @internal
 */
final class PlatformTransport {
	private const NAMESPACE = 'core-blueprint/v1';
	private const ROUTE = '/designer-platform/(?P<action>context|save|recover)';

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods' => [ 'GET', 'POST' ],
			'callback' => [ self::class, 'dispatch' ],
			'permission_callback' => [ self::class, 'permission' ],
		] );
	}

	public static function permission( \WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return is_string( $nonce ) && (bool) wp_verify_nonce( $nonce, 'wp_rest' )
			&& get_current_user_id() > 0 && is_user_logged_in();
	}

	private static function identity( string $profile, string $resource ): ?array {
		if ( Mail::PROFILE === $profile
			&& Repository::valid_identity( Repository::MAIL, $resource )
			&& \CoreBlueprint\Core\Mail\Designer\TemplateRegistry::get( $resource )
			&& current_user_can( 'manage_options' ) ) {
			return [ 'type' => Repository::MAIL, 'key' => $resource ];
		}
		if ( Reports::PROFILE === $profile && 'maintenance' === $resource
			&& current_user_can( 'cb_manage_branding' ) ) {
			return [ 'type' => Repository::REPORTS, 'key' => $resource ];
		}
		return null;
	}

	private static function exact_keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}

	private static function deny( string $code, int $status = 403 ): \WP_Error {
		return new \WP_Error( strtolower( $code ), $code, [ 'status' => $status ] );
	}

	public static function dispatch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$action = (string) $request->get_param( 'action' );
		if ( 'context' === $action && 'GET' !== $request->get_method()
			|| 'context' !== $action && 'POST' !== $request->get_method() ) {
			return self::deny( 'HOST_CONTEXT_REFUSED', 405 );
		}
		try {
			if ( 'context' === $action ) return self::context( $request );
			return self::operation( $request, 'recover' === $action );
		} catch ( \Throwable $error ) {
			// Do not downgrade an unknown commit outcome to a definite refusal.
			return 'context' === $action
				? self::deny( 'HOST_CONTEXT_REFUSED', 403 )
				: new \WP_REST_Response( [ 'status' => 'unknown', 'code' => 'SAVE_UNKNOWN' ], 200 );
		}
	}

	private static function context( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$profile = (string) $request->get_param( 'profileId' );
		$resource = (string) $request->get_param( 'resourceKey' );
		$epoch = $request->get_param( 'contextEpoch' );
		if ( ! is_numeric( $epoch ) || ! ctype_digit( (string) $epoch )
			|| (string)(int)$epoch !== (string)$epoch || (int)$epoch < 0 ) {
			return self::deny( 'HOST_CONTEXT_REFUSED', 400 );
		}
		$identity = self::identity( $profile, $resource );
		if ( null === $identity ) return self::deny( 'HOST_CONTEXT_REFUSED' );
		$registry = DesignerBootstrap::frozen_registry();
		$profile_info = $registry->profile( $profile );
		if ( null === $profile_info ) return self::deny( 'HOST_CONTEXT_REFUSED' );
		$loaded = Repository::get( $identity['type'], $identity['key'] );
		if ( 'found' === ( $loaded['status'] ?? null ) ) {
			$doc = $loaded['document'];
			if ( ! is_array( $doc ) || ! self::exact_keys( $doc, [ 'project', 'fields' ] )
				|| ! self::valid_content( $doc['project'], $doc['fields'], $registry, $profile ) ) {
				return self::deny( 'SCHEMA_UNSUPPORTED_GENERATION', 409 );
			}
			$revision = $loaded['revision'];
		} elseif ( 'missing' === ( $loaded['status'] ?? null ) ) {
			$doc = [
				'project' => Mail::PROFILE === $profile ? Mail::default_project() : Reports::default_project(),
				'fields' => Mail::PROFILE === $profile ? Mail::default_fields() : Reports::default_fields(),
			];
			$revision = '0';
			if ( ! self::valid_content( $doc['project'], $doc['fields'], $registry, $profile ) ) {
				return self::deny( 'SCHEMA_DOMAIN_INVALID', 500 );
			}
		} elseif ( 'unsupported' === ( $loaded['status'] ?? null )
			|| 'corrupt' === ( $loaded['status'] ?? null ) ) {
			return self::deny( 'SCHEMA_UNSUPPORTED_GENERATION', 409 );
		} else {
			return self::deny( 'SAVE_AUTHORITY_UNAVAILABLE', 503 );
		}
		$evidence = ApprovedManifestSource::base();
		$nonce = wp_create_nonce( 'cb_designer_' . $profile . '_' . $identity['type'] . '_' . $resource );
		$bootstrap = DesignerBootstrap::mint_context(
			$registry, $profile, $identity['type'], $resource, (int)$epoch, $nonce,
			null, $evidence['approved'], $evidence['capabilities'],
			static fn ( array $context, array $p ): bool =>
				$p['id'] === $profile && self::identity( $profile, $resource ) !== null
		);
		return new \WP_REST_Response( [
			'status' => 'loaded', 'bootstrap' => $bootstrap,
			'profileId' => $profile, 'projectSchema' => $profile_info['projectSchema'],
			'registryFingerprint' => $registry->fingerprint(),
			'contextEpoch' => (int)$epoch, 'blogId' => (int)get_current_blog_id(),
			'actorId' => (int)get_current_user_id(), 'resourceType' => $identity['type'],
			'resourceKey' => $resource, 'storeRevision' => $revision,
			'project' => $doc['project'], 'fields' => $doc['fields'],
		] );
	}

	private static function operation( \WP_REST_Request $request, bool $recovery ): \WP_REST_Response {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || ! is_array( $data['project'] ?? null )
			|| ! is_array( $data['fields'] ?? null )
			|| ( $data['contractVersion'] ?? null ) !== 1 ) {
			return new \WP_REST_Response( [ 'status' => 'refused' ], 400 );
		}
		$profile = (string) ( $data['profileId'] ?? '' );
		$resource = (string) ( $data['resourceKey'] ?? '' );
		$identity = self::identity( $profile, $resource );
		if ( null === $identity || $data['resourceType'] !== $identity['type'] ) {
			return new \WP_REST_Response( [ 'status' => 'refused' ], 403 );
		}
		$registry = DesignerBootstrap::frozen_registry();
		$selected = $registry->profile( $profile );
		if ( null === $selected || $selected['projectSchema'] !== ( $data['projectSchema'] ?? null )
			|| $registry->fingerprint() !== ( $data['registryFingerprint'] ?? null )
			|| ! self::valid_content( $data['project'], $data['fields'], $registry, $profile ) ) {
			return new \WP_REST_Response( [ 'status' => 'refused' ], 409 );
		}
		$project = json_decode( wp_json_encode( $data['project'] ), false, 128, JSON_THROW_ON_ERROR );
		$fields = json_decode( wp_json_encode( $data['fields'] ), false, 128, JSON_THROW_ON_ERROR );
		$intent = new SaveEnvelope(
			(int) get_current_blog_id(), (int) get_current_user_id(),
			$identity['type'], $resource, $profile, $selected['projectSchema'],
			$registry->fingerprint(), (string) ( $data['operationId'] ?? '' ),
			(string) ( $data['expectedStoreRevision'] ?? '' ), $project, $fields
		);
		$authorizer = static fn ( SaveEnvelope $item ): bool =>
			self::identity( $item->profile_id, $item->resource_key ) !== null
			&& $item->resource_type === $identity['type']
			&& $item->registry_fingerprint === $registry->fingerprint();
		$normalize = static function ( SaveEnvelope $item, ?array $existing ) use ( $registry ): array {
			if ( null !== $existing && ( ! self::exact_keys( $existing, [ 'project', 'fields' ] )
				|| ! self::valid_content( $existing['project'], $existing['fields'], $registry, $item->profile_id ) ) ) {
				throw new \DomainException( 'SCHEMA_UNSUPPORTED_GENERATION' );
			}
			$project = json_decode( SaveEnvelope::canonical_json( $item->project ), true, 128, JSON_THROW_ON_ERROR );
			$fields = json_decode( SaveEnvelope::canonical_json( $item->fields ), true, 128, JSON_THROW_ON_ERROR );
			if ( ! self::valid_content( $project, $fields, $registry, $item->profile_id ) ) {
				throw new \DomainException( 'SCHEMA_DOMAIN_INVALID' );
			}
			return [ 'document' => [ 'project' => $project, 'fields' => $fields ],
				'project' => $item->project, 'fields' => $item->fields ];
		};
		$create = static fn ( SaveEnvelope $item ): bool =>
			'0' === $item->expected_revision
			&& ( ( Mail::PROFILE === $item->profile_id && Repository::MAIL === $item->resource_type )
				|| ( Reports::PROFILE === $item->profile_id && Repository::REPORTS === $item->resource_type
					&& 'maintenance' === $item->resource_key ) )
			&& self::identity( $item->profile_id, $item->resource_key ) !== null
			&& self::valid_native_document( $item->resource_type, [
				'project' => json_decode( SaveEnvelope::canonical_json( $item->project ), true, 128, JSON_THROW_ON_ERROR ),
				'fields' => json_decode( SaveEnvelope::canonical_json( $item->fields ), true, 128, JSON_THROW_ON_ERROR ),
			] );
		$port = new BaseDocumentRepositoryPort( $authorizer, $normalize, $create );
		$engine = new TransactionalSave( new OperationLedger() );
		$result = $recovery ? $engine->recover( $intent, $port ) : $engine->save( $intent, $port );
		if ( ( $result['status'] ?? null ) === 'committed'
			&& is_string( $result['receipt_json'] ?? null ) ) {
			$receipt = json_decode( $result['receipt_json'], true, 128, JSON_THROW_ON_ERROR );
			return new \WP_REST_Response( [ 'status' => 'committed', 'receipt' => $receipt ], 200 );
		}
		return new \WP_REST_Response( [
			'status' => $result['status'] ?? 'unknown',
		], 200 );
	}

	/**
	 * Repository's mandatory full native-v1 validator for both persisted reads
	 * and same-transaction writes. The verifier and the REST layer always
	 * compile the SAME server-owned FrozenRegistry, never an arbitrary request
	 * schema or a PHP fallback. Reject previous schema formats explicitly.
	 */
	public static function valid_native_document( string $type, array $document ): bool {
		if ( ! in_array( $type, [ Repository::MAIL, Repository::REPORTS ], true )
			|| ! self::exact_keys( $document, [ 'project', 'fields' ] )
			|| ! is_array( $document['project'] ) || ! is_array( $document['fields'] ) ) {
			return false;
		}
		$profile = Repository::MAIL === $type ? Mail::PROFILE : Reports::PROFILE;
		try {
			return self::valid_content(
				$document['project'], $document['fields'], DesignerBootstrap::frozen_registry(), $profile
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Strict PHP mirror of native StableSchemaV1 for trusted domain values. */
	private static function valid_content( mixed $project, mixed $fields, FrozenRegistry $registry, string $profile ): bool {
		$snapshot = $registry->project_schema_snapshot( $profile );
		$facet = $registry->persisted_field_facet( $profile );
		$info = $registry->profile( $profile );
		if ( ! is_array( $project ) || ! is_array( $fields ) || ! is_array( $snapshot )
			|| ! is_array( $facet ) || ! is_array( $info )
			|| ! self::exact_keys( $project, [ 'schema_version', 'design_type', 'root' ] )
			|| ( $project['schema_version'] ?? null ) !== 1
			|| $project['design_type'] !== $info['designType']
			|| count( $fields ) !== count( $facet['fieldDescriptors'] ) ) return false;
		foreach ( $facet['fieldDescriptors'] as $definition ) {
			$key = $definition['saveKey'];
			if ( ! array_key_exists( $key, $fields ) || ! self::valid_value( $fields[$key], $definition['valueSchema'] ) ) {
				return false;
			}
		}
		$nodes = array_column( $snapshot['nodeTypes'], null, 'id' );
		$schemas = array_column( $snapshot['domainSchemas'], null, 'id' );
		$count = 0;
		$visit = function ( mixed $node, ?string $parent, int $depth ) use ( &$visit, $nodes, $schemas, &$count ): bool {
			if ( ! is_array( $node ) || ! self::exact_keys( $node, [ 'type', 'provider', 'properties', 'children' ] )
				|| ! is_string( $node['type'] ) || ! isset( $nodes[$node['type']] )
				|| $node['provider'] !== $nodes[$node['type']]['provider'] || $depth > 64
				|| ++$count > 100000 || ! is_array( $node['children'] ) || ! array_is_list( $node['children'] )
				|| ! is_array( $node['properties'] ) || array_is_list( $node['properties'] ) ) return false;
			if ( null !== $parent && ! in_array( $parent, $nodes[$node['type']]['allowedParents'], true ) ) return false;
			$id = $nodes[$node['type']]['propertiesSchemaId'];
			if ( ! is_string( $id ) || ! isset( $schemas[$id] )
				|| ! self::valid_value( $node['properties'], $schemas[$id]['schema'] ) ) return false;
			foreach ( $node['children'] as $child ) {
				if ( ! $visit( $child, $node['type'], $depth + 1 ) ) return false;
			}
			return true;
		};
		return is_array( $project['root'] )
			&& ( $project['root']['type'] ?? null ) === $info['designType']
			&& $visit( $project['root'], null, 0 );
	}

	private static function valid_value( mixed $value, array $schema, int $depth = 0 ): bool {
		if ( $depth > 64 || ! is_string( $schema['type'] ?? null ) ) return false;
		switch ( $schema['type'] ) {
			case 'string':
				return is_string( $value ) && mb_strlen( $value, 'UTF-8' ) >= ( $schema['minLength'] ?? 0 )
					&& mb_strlen( $value, 'UTF-8' ) <= ( $schema['maxLength'] ?? 65535 );
			case 'integer':
				return is_int( $value );
			case 'boolean':
				return is_bool( $value );
			case 'object':
				if ( ! is_array( $value ) || array_is_list( $value ) ) return false;
				$properties = $schema['properties'] ?? [];
				if ( ! is_array( $properties ) || count( $value ) !== count( $properties ) ) return false;
				foreach ( $properties as $key => $definition ) {
					if ( ! array_key_exists( $key, $value )
						|| ! self::valid_value( $value[$key], $definition, $depth + 1 ) ) return false;
				}
				return true;
			default:
				return false;
		}
	}

	private function __construct() {}
}
