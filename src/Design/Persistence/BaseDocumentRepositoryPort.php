<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Persistence;

use CoreBlueprint\Core\Design\Document\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * One first-party, same-wpdb bridge to the EXISTING canonical Repository.
 *
 * The trusted domain owner must supply synchronous, pure and deterministic
 * authorization/normalization. In particular, the authorizer checks its
 * original WordPress nonce, resource ownership, profile capability, and
 * editable field allowlist; the normalizer validates the full project and
 * persisted fields before returning a complete canonical document.
 *
 * Neither callback may perform I/O, call WordPress hooks, open a transaction,
 * commit, or use an independent database connection. Missing callbacks are
 * never replaced by a permissive default. This port is not a public writer.
 *
 * @internal
 */
final class BaseDocumentRepositoryPort extends BaseDocumentTransactionPort {
	private readonly \Closure $authorize_document;
	private readonly \Closure $normalize_document;
	private readonly \Closure $authorize_creation;
	private ?array $last_document = null;
	private ?SaveEnvelope $last_intent = null;

	/**
	 * @param callable(SaveEnvelope):bool $authorize_document
	 * @param callable(SaveEnvelope,?array):array{document:array<string,mixed>,project:object,fields:object} $normalize_document
	 * @param callable(SaveEnvelope):bool $authorize_creation
	 */
	public function __construct(
		callable $authorize_document,
		callable $normalize_document,
		callable $authorize_creation
	) {
		$this->authorize_document = \Closure::fromCallable( $authorize_document );
		$this->normalize_document = \Closure::fromCallable( $normalize_document );
		$this->authorize_creation = \Closure::fromCallable( $authorize_creation );
	}

	public function authorize( SaveEnvelope $intent ): bool {
		if ( ! function_exists( 'get_current_blog_id' ) || ! function_exists( 'get_current_user_id' )
			|| (int) get_current_blog_id() !== $intent->blog_id
			|| (int) get_current_user_id() !== $intent->actor_id
			|| ! function_exists( 'current_user_can' )
			|| ! current_user_can( 'edit_posts' )
			|| ! Repository::valid_identity( $intent->resource_type, $intent->resource_key ) ) {
			return false;
		}
		try {
			return ( $this->authorize_document )( $intent ) === true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Called exclusively after TransactionalSave owns START TRANSACTION. */
	public function load_locked( SaveEnvelope $intent ): array {
		if ( ! $this->authorize( $intent ) ) {
			return [ 'status' => 'unavailable' ];
		}
		$read = Repository::read_for_update( $intent->resource_type, $intent->resource_key );
		if ( 'missing' === ( $read['status'] ?? null ) ) {
			return [ 'status' => 'missing' ];
		}
		if ( 'found' !== ( $read['status'] ?? null )
			|| ! is_string( $read['revision'] ?? null ) ) {
			return [ 'status' => 'unavailable' ];
		}
		return [ 'status' => 'found', 'revision' => $read['revision'] ];
	}

	public function write_locked( SaveEnvelope $intent, array $locked ): array {
		$this->last_document = null;
		$this->last_intent = null;
		if ( ! $this->authorize( $intent ) ) {
			return [ 'status' => 'refused' ];
		}
		$read = Repository::read_for_update( $intent->resource_type, $intent->resource_key );
		if ( ( $locked['status'] ?? null ) === 'missing' ) {
			if ( ( $read['status'] ?? null ) !== 'missing'
				|| '0' !== $intent->expected_revision
				|| ! in_array( $intent->resource_type, [ Repository::MAIL, Repository::REPORTS ], true ) ) {
				return [ 'status' => 'conflict' ];
			}
			try {
				if ( ( $this->authorize_creation )( $intent ) !== true ) {
					return [ 'status' => 'refused' ];
				}
			} catch ( \Throwable $error ) {
				return [ 'status' => 'refused' ];
			}
		} elseif ( ( $locked['status'] ?? null ) !== 'found'
			|| ( $read['status'] ?? null ) !== 'found'
			|| ! is_string( $read['revision'] ?? null )
			|| $read['revision'] !== $intent->expected_revision
			|| ( $locked['revision'] ?? null ) !== $intent->expected_revision ) {
			return [ 'status' => 'conflict' ];
		}
		try {
			$accepted = ( $this->normalize_document )(
				$intent, 'found' === ( $read['status'] ?? null ) ? $read['document'] : null
			);
			if ( ! is_array( $accepted )
				|| ! is_array( $accepted['document'] ?? null )
				|| ! ( $accepted['project'] ?? null ) instanceof \stdClass
				|| ! ( $accepted['fields'] ?? null ) instanceof \stdClass
				|| ! Repository::valid_document( $intent->resource_type, $accepted['document'] ) ) {
				return [ 'status' => 'refused' ];
			}
			// The returned receipt values must represent precisely what domain
			// normalization will actually persist; verify this after CAS.
			$project = SaveEnvelope::decode_object( SaveEnvelope::canonical_json( $accepted['project'] ) );
			$fields = SaveEnvelope::decode_object( SaveEnvelope::canonical_json( $accepted['fields'] ) );
			$result = Repository::write_in_transaction(
				$intent->resource_type, $intent->resource_key,
				$intent->expected_revision, $accepted['document']
			);
			if ( ( $result['status'] ?? null ) !== 'saved'
				|| ! is_string( $result['revision'] ?? null ) ) {
				return [ 'status' => ( $result['status'] ?? null ) === 'conflict' ? 'conflict' : 'refused' ];
			}
			$this->last_document = $accepted['document'];
			$this->last_intent = $intent;
			return [
				'status' => 'accepted',
				'revision' => $result['revision'],
				'project' => $project,
				'fields' => $fields,
			];
		} catch ( \Throwable $error ) {
			return [ 'status' => 'refused' ];
		}
	}

	public function verify_locked(
		SaveEnvelope $intent,
		string $revision,
		object $project,
		object $fields
	): bool {
		if ( $intent !== $this->last_intent || null === $this->last_document
			|| ! $this->authorize( $intent ) ) {
			return false;
		}
		try {
			// Do not trust a receipt solely because the CAS returned 'saved'.
			// Re-run canonical domain normalization against the locked row and
			// compare both accepted values with the authoritative readback.
			if ( ! Repository::verify_in_transaction(
				$intent->resource_type, $intent->resource_key, $revision, $this->last_document
			) ) {
				return false;
			}
			$accepted = ( $this->normalize_document )( $intent, $this->last_document );
			return is_array( $accepted )
				&& is_array( $accepted['document'] ?? null )
				&& ( $accepted['project'] ?? null ) instanceof \stdClass
				&& ( $accepted['fields'] ?? null ) instanceof \stdClass
				&& SaveEnvelope::canonical_json( (object) $accepted['project'] ) === SaveEnvelope::canonical_json( $project )
				&& SaveEnvelope::canonical_json( (object) $accepted['fields'] ) === SaveEnvelope::canonical_json( $fields )
				&& wp_json_encode( $accepted['document'] ) === wp_json_encode( $this->last_document );
		} catch ( \Throwable $error ) {
			return false;
		}
	}
}
