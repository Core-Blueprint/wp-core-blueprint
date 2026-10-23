<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Persistence;

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * A2-06 single-operation, two-table transaction authority.
 *
 * Inert until a separately approved first-party BaseDocumentTransactionPort
 * and SchemaRegistry registration bridge exist. No consumer endpoint is wired.
 *
 * @internal
 */
final class TransactionalSave {
	public function __construct( private readonly OperationLedger $ledger ) {}

	/**
	 * @return array{status:string,receipt_json?:string}
	 */
	public function save( SaveEnvelope $intent, BaseDocumentTransactionPort $port ): array {
		if ( ! $this->preflight( $intent, $port ) ) {
			return [ 'status' => 'refused', 'reason' => 'SAVE_PREFLIGHT_REFUSED' ];
		}
		global $wpdb;
		// Fail closed: never start a nested or caller-owned transaction.
		$wpdb->last_error = '';
		$active = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( '' !== (string) $wpdb->last_error || '0' !== (string) $active ) {
			return [ 'status' => 'unknown', 'reason' => 'SAVE_TX_STATE_UNVERIFIED' ];
		}
		$wpdb->last_error = '';
		if ( false === $wpdb->query( 'START TRANSACTION' ) || '' !== (string) $wpdb->last_error ) {
			return [ 'status' => 'unknown', 'reason' => 'SAVE_TX_BEGIN_UNVERIFIED' ];
		}
		$commit_attempted = false;
		try {
			$claim = $this->ledger->claim( $intent );
			if ( 'duplicate' === $claim ) {
				if ( ! $this->rollback() ) {
					return [ 'status' => 'unknown', 'reason' => 'SAVE_TX_ROLLBACK_UNVERIFIED' ];
				}
				// The duplicate index serializes concurrent operation IDs. Read the
				// original receipt, never take a new ID or construct a new receipt.
				return $this->recover( $intent, $port );
			}
			if ( 'claimed' !== $claim ) {
				return $this->rollback()
					? [ 'status' => 'unknown', 'reason' => 'SAVE_OPERATION_CLAIM_UNVERIFIED' ]
					: [ 'status' => 'unknown', 'reason' => 'SAVE_TX_ROLLBACK_UNVERIFIED' ];
			}

			$locked = $port->load_locked( $intent );
			if ( ! in_array( $locked['status'] ?? null, [ 'found', 'missing' ], true ) ) {
				return $this->finish_refusal( 'refused', 'SAVE_DOCUMENT_LOAD_REFUSED' );
			}
			if ( 'missing' === $locked['status'] ) {
				// Only the authorized first creation at revision zero may insert.
				// The domain port independently checks owner, capability, resource
				// and native v1 value. A pre-existing invalid row is NOT missing.
				if ( '0' !== $intent->expected_revision
					|| ! in_array( $intent->resource_type, [ Repository::MAIL, Repository::REPORTS ], true ) ) {
					return $this->finish_refusal( 'conflict' );
				}
			} elseif ( ! is_string( $locked['revision'] ?? null )
				|| $locked['revision'] !== $intent->expected_revision
				|| ! SaveEnvelope::decimal( $locked['revision'] ) ) {
				return $this->finish_refusal( 'conflict' );
			}
			// No client scope or preflight authorization is sufficient by itself.
			if ( ! $port->authorize( $intent ) ) {
				return $this->finish_refusal( 'refused', 'SAVE_DOCUMENT_AUTHORIZATION_REFUSED' );
			}
			$written = $port->write_locked( $intent, $locked );
			if ( ( $written['status'] ?? null ) !== 'accepted'
				|| ! is_string( $written['revision'] ?? null )
				|| ! ( $written['project'] ?? null ) instanceof \stdClass
				|| ! ( $written['fields'] ?? null ) instanceof \stdClass ) {
				return $this->finish_refusal(
					( $written['status'] ?? null ) === 'conflict' ? 'conflict' : 'refused',
					is_string( $written['reason'] ?? null ) ? $written['reason'] : 'SAVE_DOCUMENT_WRITE_REFUSED'
				);
			}
			$next = $written['revision'];
			$expected_next = (string) ( (int) $intent->expected_revision + 1 );
			if ( ! SaveEnvelope::decimal( $next ) || $next !== $expected_next
				|| ! $port->verify_locked( $intent, $next, $written['project'], $written['fields'] ) ) {
				return $this->finish_refusal( 'refused', 'SAVE_DOCUMENT_VERIFY_REFUSED' );
			}

			// All normalization is inside the port's authoritative domain write.
			// Receipt contains the FULL accepted project AND FULL accepted fields.
			$accepted_project = SaveEnvelope::decode_object( SaveEnvelope::canonical_json( $written['project'] ) );
			$accepted_fields = SaveEnvelope::decode_object( SaveEnvelope::canonical_json( $written['fields'] ) );
			$receipt_json = SaveEnvelope::canonical_json( (object) [
				'contractVersion' => 1,
				'status' => 'committed',
				'operationId' => $intent->operation_id,
				'blogId' => (string) $intent->blog_id,
				'actorId' => (string) $intent->actor_id,
				'resourceType' => $intent->resource_type,
				'resourceKey' => $intent->resource_key,
				'profileId' => $intent->profile_id,
				'projectSchema' => $intent->project_schema,
				'registryFingerprint' => $intent->registry_fingerprint,
				'requestDigest' => $intent->request_digest,
				'submittedProjectDigest' => $intent->project_digest,
				'submittedFieldsDigest' => $intent->fields_digest,
				'previousStoreRevision' => $intent->expected_revision,
				'acceptedStoreRevision' => $next,
				'acceptedProject' => $accepted_project,
				'acceptedFields' => $accepted_fields,
				'acceptedProjectDigest' => hash( 'sha256', SaveEnvelope::canonical_json( $accepted_project ) ),
				'acceptedFieldsDigest' => hash( 'sha256', SaveEnvelope::canonical_json( $accepted_fields ) ),
				'acceptedDigest' => $intent->digest( $accepted_project, $accepted_fields ),
			] );
			if ( ! $this->ledger->mark_committed( $intent, $next, $receipt_json ) ) {
				return $this->finish_refusal( 'refused', 'SAVE_OPERATION_RECEIPT_REFUSED' );
			}
			$commit_attempted = true;
			$wpdb->last_error = '';
			if ( false === $wpdb->query( 'COMMIT' ) || '' !== (string) $wpdb->last_error ) {
				return [ 'status' => 'unknown', 'reason' => 'SAVE_TX_COMMIT_UNVERIFIED' ];
			}
			// No success claim based solely on COMMIT's transport result.
			$resolved = $this->recover( $intent, $port );
			if ( 'committed' !== $resolved['status'] || $resolved['receipt_json'] !== $receipt_json ) {
				return [ 'status' => 'unknown', 'reason' => 'SAVE_POSTCOMMIT_RECEIPT_UNVERIFIED' ];
			}
			return $resolved;
		} catch ( \Throwable $exception ) {
			if ( ! $commit_attempted ) {
				$this->rollback();
			}
			return [ 'status' => 'unknown', 'reason' => 'SAVE_TX_EXCEPTION_UNVERIFIED' ];
		}
	}

	/**
	 * Authorized lookup of the ORIGINAL operation; never retries the write.
	 * @return array{status:string,receipt_json?:string}
	 */
	public function recover( SaveEnvelope $intent, BaseDocumentTransactionPort $port ): array {
		if ( ! $this->preflight( $intent, $port ) ) {
			return [ 'status' => 'unknown', 'reason' => 'SAVE_RECOVERY_PREFLIGHT_UNVERIFIED' ];
		}
		return $this->ledger->lookup( $intent );
	}

	private function preflight( SaveEnvelope $intent, BaseDocumentTransactionPort $port ): bool {
		return function_exists( 'get_current_blog_id' )
			&& function_exists( 'get_current_user_id' )
			&& (int) get_current_blog_id() === $intent->blog_id
			&& (int) get_current_user_id() === $intent->actor_id
			&& Repository::valid_identity( $intent->resource_type, $intent->resource_key )
			&& Repository::valid_revision( $intent->expected_revision, true )
			// This is a NEW native-v1 product, not the earlier development
			// cutover authority. Physical primary DB schema health, checked
			// on each request, is mandatory alongside domain authorization.
			&& Schema::healthy()
			&& OperationSchema::healthy()
			&& $port->authorize( $intent );
	}

	/** Return a conclusive refusal only when ROLLBACK was acknowledged. */
	private function finish_refusal( string $status = 'refused', string $reason = 'SAVE_DOCUMENT_WRITE_REFUSED' ): array {
		if ( ! $this->rollback() ) {
			return [ 'status' => 'unknown', 'reason' => 'SAVE_TX_ROLLBACK_UNVERIFIED' ];
		}
		return 'refused' === $status
			? [ 'status' => 'refused', 'reason' => $reason ]
			: [ 'status' => $status ];
	}

	private function rollback(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		return false !== $wpdb->query( 'ROLLBACK' ) && '' === (string) $wpdb->last_error;
	}
}
