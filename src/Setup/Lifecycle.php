<?php
declare(strict_types=1);
/**
 * Core Setup lifecycle classification.
 *
 * Fresh-install origin is granted only by the canonical plugin activation
 * boundary. Lazy initialization always classifies an untracked active site as
 * existing so missing metadata can never manufacture first-install semantics.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	public static function initialize_activation( bool $is_first_activation ): void {
		$origin = $is_first_activation
			? ReviewRepository::ORIGIN_FIRST_INSTALL
			: ReviewRepository::ORIGIN_EXISTING_INSTALL;

		if ( ! ReviewRepository::initialize_lifecycle( $origin, time() ) ) {
			throw new \RuntimeException( 'Core Setup lifecycle could not be initialized.' );
		}
	}

	/** @return array{origin:string,initialized_at:int,started_at:int,started_by:int} */
	public static function ensure_initialized(): array {
		$lifecycle = ReviewRepository::lifecycle();
		if ( in_array( $lifecycle['origin'], ReviewRepository::ORIGINS, true ) ) {
			return $lifecycle;
		}

		// Deliberately conservative. A truly fresh install is marked during
		// Core::activate(); an active site without that marker is established.
		if ( ! ReviewRepository::initialize_lifecycle( ReviewRepository::ORIGIN_EXISTING_INSTALL, time() ) ) {
			throw new \RuntimeException( 'Core Setup lifecycle could not be initialized.' );
		}

		return ReviewRepository::lifecycle();
	}

	public static function mark_started( int $user_id = 0 ): bool {
		$before = self::ensure_initialized();
		if ( $before['started_at'] > 0 ) {
			return false;
		}

		if ( ! ReviewRepository::mark_started( $user_id, time() ) ) {
			throw new \RuntimeException( 'Core Setup start state could not be persisted.' );
		}

		$after = ReviewRepository::lifecycle();
		if ( $after['started_at'] <= 0 ) {
			throw new \RuntimeException( 'Core Setup start state could not be verified.' );
		}

		AuditLog::log( 'core.setup.started', 'notice', [
			'origin' => $after['origin'],
		] );

		return true;
	}

	private function __construct() {}
}
