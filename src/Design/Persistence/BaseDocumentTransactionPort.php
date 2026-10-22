<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Persistence;

use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow, Base-owned bridge contract for a future integrator. Implementations
 * must use the *same* wpdb connection and canonical Repository identity/schema
 * rules: one locked document, CAS, and authoritative readback. No remote I/O,
 * DDL, options cache, independent connection or third-party hooks may execute
 * under this port. This abstract port is NOT a new writable public API.
 */
abstract class BaseDocumentTransactionPort {
	/** Verify actor, nonce, resource and every editable field against SERVER authority. */
	abstract public function authorize( SaveEnvelope $intent ): bool;

	/** @return array{status:string,revision?:string} */
	abstract public function load_locked( SaveEnvelope $intent ): array;

	/**
	 * Perform exactly one locked canonical document CAS/create, including
	 * current-domain normalization. No independent COMMIT.
	 * @param array{status:string,revision?:string} $locked
	 * @return array{status:string,revision?:string,project?:object,fields?:object}
	 */
	abstract public function write_locked( SaveEnvelope $intent, array $locked ): array;

	/** Authoritative same-connection locked document readback, not cached. */
	abstract public function verify_locked( SaveEnvelope $intent, string $revision, object $project, object $fields ): bool;
}

