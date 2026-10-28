<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Editor;

use CoreBlueprint\Core\Design\Bootstrap as DesignerBootstrap;
use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Integrity\ApprovedManifestSource;
use CoreBlueprint\Core\Design\Persistence\BaseDocumentRepositoryPort;
use CoreBlueprint\Core\Design\Persistence\OperationLedger;
use CoreBlueprint\Core\Design\Persistence\SaveEnvelope;
use CoreBlueprint\Core\Design\Persistence\TransactionalSave;
use CoreBlueprint\Core\Design\Profile\Mail\PlatformAdapter as Mail;
use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter as Reports;
use CoreBlueprint\Core\Design\Registry\FrozenRegistry;
use CoreBlueprint\Core\Log\AuditLog;

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
			&& str_starts_with( $resource, 'wordpress.' )
			&& \CoreBlueprint\Core\Mail\DesignerState::is_enabled()
			&& current_user_can( 'manage_options' ) ) {
			// The first Base-only package has NO independently approved
			// extension consumer identity. Allow only built-in WordPress templates.
			$definition = \CoreBlueprint\Core\Mail\Designer\TemplateRegistry::get( $resource );
			if ( ! is_array( $definition ) || 'core' !== ( $definition['provider'] ?? null )
				|| 'wordpress' !== ( $definition['group'] ?? null ) ) {
				return null;
			}
			return [ 'type' => Repository::MAIL, 'key' => $resource ];
		}
		if ( Reports::PROFILE === $profile && 'maintenance' === $resource
			&& \CoreBlueprint\Core\Reports\State::is_enabled()
			&& current_user_can( 'cb_manage_branding' ) ) {
			return [ 'type' => Repository::REPORTS, 'key' => $resource ];
		}
		return null;
	}

	/** The single live WordPress domain policy; no global edit_posts fallback. */
	public static function authorized_resource( string $profile, string $resource_type, string $resource_key ): bool {
		$identity = self::identity( $profile, $resource_key );
		return is_array( $identity )
			&& hash_equals( $identity['type'], $resource_type )
			&& hash_equals( $identity['key'], $resource_key );
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
			// Best-effort diagnostics through Base's existing audit queue only.
			// Never record exception messages, payloads, tokens, document text or
			// paths. Queueing avoids a DB write inside an uncertain Save transaction.
			try {
				$known = [ 'HOST_CONTEXT_REFUSED', 'ARTIFACT_RUNTIME_MISMATCH',
					'HOST_MODULE_MISSING', 'CSS_RUNTIME_STALE', 'SAVE_SCHEMA_UNAVAILABLE',
					'SAVE_AUTHORITY_UNAVAILABLE', 'SCHEMA_DOMAIN_INVALID', 'REGISTRY_INVALID' ];
				AuditLog::queue( 'designer.transport_exception', 'warning', [
					'action' => in_array( $action, [ 'context', 'save', 'recover' ], true ) ? $action : 'unknown',
					'exception' => get_class( $error ),
					'reason' => $error instanceof \DomainException && in_array( $error->getMessage(), $known, true )
						? $error->getMessage() : 'UNEXPECTED',
				] );
			} catch ( \Throwable $ignored ) {
				// Diagnostic failure must not change the REST or Save outcome.
			}
			// Only genuine authorization denials are 403. Public responses use
			// stable category codes, never internal exception details or paths.
			// A Save failure remains UNKNOWN unless the transaction proves otherwise.
			if ( 'context' !== $action ) {
				return new \WP_REST_Response( [ 'status' => 'unknown', 'reason' => 'SAVE_DISPATCH_UNVERIFIED' ], 200 );
			}
			$code = $error instanceof \DomainException ? $error->getMessage() : '';
			if ( 'HOST_CONTEXT_REFUSED' === $code ) return self::deny( 'HOST_CONTEXT_REFUSED' );
			if ( in_array( $code, [ 'ARTIFACT_RUNTIME_MISMATCH', 'HOST_MODULE_MISSING', 'CSS_RUNTIME_STALE' ], true ) ) {
				return self::deny( 'DESIGNER_ASSETS_UNAVAILABLE', 503 );
			}
			if ( in_array( $code, [ 'SAVE_SCHEMA_UNAVAILABLE', 'SAVE_AUTHORITY_UNAVAILABLE' ], true ) ) {
				return self::deny( 'SAVE_AUTHORITY_UNAVAILABLE', 503 );
			}
			return self::deny( 'DESIGNER_RUNTIME_UNAVAILABLE', 500 );
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
		if ( 'active' !== ( Authority::inspect( $identity['type'] )['status'] ?? 'blocked' ) ) {
			return self::deny( 'SAVE_AUTHORITY_UNAVAILABLE', 503 );
		}
		$loaded = Repository::get( $identity['type'], $identity['key'] );
		if ( 'found' === ( $loaded['status'] ?? null ) ) {
			$doc = $loaded['document'];
			if ( ! is_array( $doc ) || ! self::exact_keys( $doc, [ 'project', 'fields' ] )
				|| ! self::valid_content( $doc['project'], $doc['fields'], $registry, $profile ) ) {
				return self::deny( 'SCHEMA_UNSUPPORTED_GENERATION', 409 );
			}
			$revision = $loaded['revision'];
		} elseif ( 'missing' === ( $loaded['status'] ?? null ) ) {
			if ( Mail::PROFILE === $profile ) {
				// Mail owns WordPress-notification wording, token semantics and
				// security links. Generic empty templates are forbidden.
				if ( ! is_callable( [ Mail::class, 'default_for_resource' ] ) ) {
					return self::deny( 'NATIVE_TEMPLATE_DEFAULT_UNAVAILABLE', 503 );
				}
				$doc = Mail::default_for_resource( $resource );
			} else {
				$doc = [
					'project' => Reports::default_project(),
					'fields' => Reports::default_fields(),
				];
			}
			if ( ! is_array( $doc ) || ! self::exact_keys( $doc, [ 'project', 'fields' ] ) ) {
				return self::deny( 'SCHEMA_DOMAIN_INVALID', 500 );
			}
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
		if ( null === $identity || ( $data['resourceType'] ?? null ) !== $identity['type'] ) {
			return new \WP_REST_Response( [ 'status' => 'refused' ], 403 );
		}
		// Refuse ambiguous authority before beginning a Save transaction.
		if ( 'active' !== ( Authority::inspect( $identity['type'] )['status'] ?? 'blocked' ) ) {
			return new \WP_REST_Response( [ 'status' => 'refused' ], 409 );
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
			'active' === ( Authority::inspect( $item->resource_type )['status'] ?? 'blocked' )
			&& self::authorized_resource( $item->profile_id, $item->resource_type, $item->resource_key )
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
			'active' === ( Authority::inspect( $item->resource_type )['status'] ?? 'blocked' )
			&& '0' === $item->expected_revision
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
		$status = (string) ( $result['status'] ?? 'unknown' );
		// A bounded phase classification is diagnostic metadata, never a
		// write authorization or proof of successful persistence. No SQL,
		// document values, tokens, actor identities or exception messages.
		$unknown_phases = [
			'SAVE_TX_STATE_UNVERIFIED', 'SAVE_TX_BEGIN_UNVERIFIED',
			'SAVE_TX_ROLLBACK_UNVERIFIED', 'SAVE_OPERATION_CLAIM_UNVERIFIED',
			'SAVE_TX_COMMIT_UNVERIFIED', 'SAVE_POSTCOMMIT_RECEIPT_UNVERIFIED',
			'SAVE_TX_EXCEPTION_UNVERIFIED', 'SAVE_RECOVERY_PREFLIGHT_UNVERIFIED',
			'SAVE_LEDGER_SCHEMA_UNVERIFIED', 'SAVE_LEDGER_READ_UNVERIFIED',
			'SAVE_LEDGER_STATE_UNVERIFIED', 'SAVE_LEDGER_RECEIPT_INVALID',
		];
		$body = [ 'status' => $status ];
		if ( 'unknown' === $status ) {
			$reason = $result['reason'] ?? '';
			$body['reason'] = is_string( $reason ) && in_array( $reason, $unknown_phases, true )
				? $reason : 'SAVE_SERVER_UNVERIFIED';
		} elseif ( 'refused' === $status ) {
			// Only a confirmed pre-transaction refusal or acknowledged ROLLBACK
			// may reveal a phase category. No request contents, SQL, user IDs,
			// exception details or operation identity are returned.
			$refused_phases = [
				'SAVE_PREFLIGHT_REFUSED', 'SAVE_DOCUMENT_LOAD_REFUSED',
				'SAVE_DOCUMENT_AUTHORIZATION_REFUSED', 'SAVE_DOCUMENT_CREATION_REFUSED',
				'SAVE_DOCUMENT_VALIDATION_REFUSED', 'SAVE_DOCUMENT_STORAGE_REFUSED',
				'SAVE_DOCUMENT_NORMALIZATION_REFUSED', 'SAVE_DOCUMENT_WRITE_REFUSED',
				'SAVE_DOCUMENT_VERIFY_REFUSED', 'SAVE_OPERATION_RECEIPT_REFUSED',
			];
			$reason = $result['reason'] ?? '';
			$storage_phase = is_string( $reason )
				&& 1 === preg_match(
					'/^SAVE_STORAGE_(?:CREATE|UPDATE)_(?:INVALID|SCHEMA_UNAVAILABLE|QUERY_FAILED|READBACK_UNVERIFIED|READ_UNAVAILABLE|UNCLASSIFIED|DB_[1-9][0-9]{0,4})$/D',
					$reason
				);
			$body['reason'] = is_string( $reason )
				&& ( in_array( $reason, $refused_phases, true ) || $storage_phase )
				? $reason : 'SAVE_REFUSED_UNCLASSIFIED';
		}
		return new \WP_REST_Response( $body, 200 );
	}

	/**
	 * Stored-document admission uses the existing first-party native domain
	 * validators, not the JS editor artifact or Registry compiler. Mail delivery
	 * must not depend on an installed browser editor or its CSS witness.
	 *
	 * The REST context and Save transport STILL require the verified FrozenRegistry
	 * and run valid_content() against its snapshot before publishing/committing.
	 * This method never authorizes a resource or a write.
	 */
	public static function valid_native_document( string $type, array $document ): bool {
		if ( ! in_array( $type, [ Repository::MAIL, Repository::REPORTS ], true )
			|| ! self::exact_keys( $document, [ 'project', 'fields' ] )
			|| ! is_array( $document['project'] ) || ! is_array( $document['fields'] ) ) {
			return false;
		}
		try {
			if ( Repository::REPORTS === $type ) {
				return \CoreBlueprint\Core\Reports\Composer\NativeComposition::valid_document( $document );
			}
			$fields = $document['fields'];
			if ( ! self::exact_keys( $fields, [ 'subject', 'preheader' ] )
				|| ! self::valid_value( $fields['subject'], [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ] )
				|| ! self::valid_value( $fields['preheader'], [ 'type' => 'string', 'maxLength' => 500 ] ) ) {
				return false;
			}
			$validator = new \CoreBlueprint\Core\Design\Profile\Mail\Validator();
			$diagnostics = $validator->validate( $document['project'] );
			return $diagnostics instanceof \CoreBlueprint\Core\Design\Kernel\Diagnostics
				&& ! $diagnostics->has_errors();
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
		if ( ! is_array( $project['root'] )
			|| ( $project['root']['type'] ?? null ) !== $info['designType']
			|| ! $visit( $project['root'], null, 0 ) ) return false;
		// The registered schema is necessary but not sufficient: product
		// invariants are MANDATORY on both Repository reads and canonical Save.
		// Never accept when an owner's native validator is not installed or
		// cannot execute. No alternative v0 validator or data conversion.
		if ( Mail::PROFILE === $profile ) {
			if ( ! class_exists( \CoreBlueprint\Core\Design\Profile\Mail\Contract::class )
				|| ! class_exists( \CoreBlueprint\Core\Design\Profile\Mail\Validator::class ) ) return false;
			$validator = new \CoreBlueprint\Core\Design\Profile\Mail\Validator();
			if ( ! is_callable( [ $validator, 'validate' ] ) ) return false;
			$diagnostics = $validator->validate( $project );
			return $diagnostics instanceof \CoreBlueprint\Core\Design\Kernel\Diagnostics
				&& ! $diagnostics->has_errors();
		}
		if ( Reports::PROFILE === $profile ) {
			$validator = \CoreBlueprint\Core\Reports\Composer\NativeComposition::class;
			if ( ! class_exists( $validator ) || ! is_callable( [ $validator, 'valid_document' ] ) ) {
				return false;
			}
			return true === $validator::valid_document( [
				'project' => $project, 'fields' => $fields,
			] );
		}
		return false;
	}

	/**
	 * Mirrors StableSchemaV1 domain constraints at the authoritative PHP
	 * read/transactional-write boundary. No coercion, repairs or legacy cases.
	 */
	private static function valid_value( mixed $value, array $schema, int $depth = 0 ): bool {
		if ( $depth > 64 || ! isset( $schema['type'] ) ) return false;
		$types = is_string( $schema['type'] ) ? [ $schema['type'] ] : $schema['type'];
		if ( ! is_array( $types ) || ! array_is_list( $types ) || [] === $types ) return false;
		$matches = false;
		foreach ( $types as $type ) {
			if ( ! in_array( $type, [ 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ], true ) ) return false;
			$matches = $matches || match ( $type ) {
				'string' => is_string( $value ),
				'integer' => is_int( $value ) && abs( $value ) <= 9007199254740991,
				// SaveEnvelopeV1 deliberately admits only cross-language
				// exact integers until fractional JSON digests are standardized.
				'number' => is_int( $value ) && abs( $value ) <= 9007199254740991,
				'boolean' => is_bool( $value ),
				'object' => is_array( $value ) && ( [] === $value || ! array_is_list( $value ) ),
				'array' => is_array( $value ) && array_is_list( $value ),
				'null' => null === $value,
			};
		}
		if ( ! $matches ) return false;
		if ( array_key_exists( 'enum', $schema ) && ( ! is_array( $schema['enum'] )
			|| ! in_array( $value, $schema['enum'], true ) ) ) return false;
		if ( is_string( $value ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' )
				: preg_match_all( '/./us', $value );
			if ( false === $length ) return false;
			if ( $length < ( $schema['minLength'] ?? 0 ) || $length > ( $schema['maxLength'] ?? 65535 ) ) return false;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( ( isset( $schema['minimum'] ) && $value < $schema['minimum'] )
				|| ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) ) return false;
		}
		if ( is_array( $value ) && in_array( 'array', $types, true )
			&& array_is_list( $value ) ) {
			if ( count( $value ) < ( $schema['minItems'] ?? 0 )
				|| count( $value ) > ( $schema['maxItems'] ?? 100000 )
				|| ! is_array( $schema['items'] ?? null ) ) return false;
			foreach ( $value as $entry ) {
				if ( ! self::valid_value( $entry, $schema['items'], $depth + 1 ) ) return false;
			}
		}
		if ( is_array( $value ) && ( ! array_is_list( $value )
			|| ( [] === $value && in_array( 'object', $types, true ) && ! in_array( 'array', $types, true ) ) ) ) {
			$properties = $schema['properties'] ?? [];
			$required = $schema['required'] ?? [];
			if ( ! is_array( $properties ) || ! is_array( $required ) ) return false;
			foreach ( $required as $key ) {
				if ( ! is_string( $key ) || ! array_key_exists( $key, $value ) ) return false;
			}
			foreach ( $value as $key => $entry ) {
				if ( ! array_key_exists( $key, $properties ) ) {
					if ( ( $schema['additionalProperties'] ?? false ) !== true ) return false;
					continue;
				}
				if ( ! is_array( $properties[$key] )
					|| ! self::valid_value( $entry, $properties[$key], $depth + 1 ) ) return false;
			}
		}
		return true;
	}

	private function __construct() {}
}
