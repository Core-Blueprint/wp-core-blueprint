<?php
declare(strict_types=1);
/**
 * Core Setup review mutation control plane.
 *
 * This service may change Setup review metadata only. It never mutates the
 * canonical configuration inspected by a check.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup;

use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ReviewManager {

	public static function record( string $check_id, string $disposition, string $reason = '', int $user_id = 0 ): bool {
		$check = self::authorized_check( $check_id );

		try {
			$evidence = $check->evidence();
		} catch ( \Throwable $e ) {
			$evidence = Evidence::unavailable( 'setup.provider-unavailable' );
		}
		if ( Evidence::HEALTH_UNAVAILABLE === $evidence->health() ) {
			throw new \RuntimeException( 'Core Setup evidence is unavailable.' );
		}

		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ReviewRepository::LATER === $disposition ) {
			if ( ! $check->allows_later() ) {
				throw new \InvalidArgumentException( 'This Core Setup check cannot be deferred.' );
			}
			if ( '' === $reason ) {
				throw new \InvalidArgumentException( 'A reason is required when deferring a Core Setup check.' );
			}
		} elseif ( ReviewRepository::NOT_APPLICABLE === $disposition ) {
			if ( ! $check->allows_not_applicable( $evidence ) ) {
				throw new \InvalidArgumentException( 'This Core Setup check is currently applicable.' );
			}
			if ( '' === $reason ) {
				throw new \InvalidArgumentException( 'A reason is required for Not applicable.' );
			}
		} elseif ( ReviewRepository::REVIEWED !== $disposition ) {
			throw new \InvalidArgumentException( 'Invalid Core Setup review disposition.' );
		}

		$before = ReviewRepository::check( $check_id );
		$saved = match ( $disposition ) {
			ReviewRepository::REVIEWED => ReviewRepository::mark_reviewed( $check_id, $evidence, $user_id ),
			ReviewRepository::LATER => ReviewRepository::mark_later( $check_id, $evidence, $reason, $user_id ),
			ReviewRepository::NOT_APPLICABLE => ReviewRepository::mark_not_applicable( $check_id, $evidence, $reason, $user_id ),
		};
		if ( ! $saved ) {
			throw new \RuntimeException( 'Core Setup review metadata could not be saved.' );
		}

		$after = ReviewRepository::check( $check_id );
		if ( self::same_review( $before, $after ) ) {
			return false;
		}

		$event = match ( $disposition ) {
			ReviewRepository::LATER          => 'core.setup.deferred',
			ReviewRepository::NOT_APPLICABLE => 'core.setup.not.applicable',
			default                          => 'core.setup.reviewed',
		};
		AuditLog::log( $event, 'notice', [
			'check_id'       => $check_id,
			'disposition'    => $disposition,
			'evidence_code'  => $evidence->code(),
			'reason_present' => '' !== $reason,
			'reason_length'  => strlen( $reason ),
		] );

		return true;
	}

	public static function clear( string $check_id ): bool {
		self::authorized_check( $check_id );
		if ( null === ReviewRepository::check( $check_id ) ) {
			return false;
		}
		if ( ! ReviewRepository::clear_check( $check_id ) ) {
			throw new \RuntimeException( 'Core Setup review metadata could not be cleared.' );
		}

		AuditLog::log( 'core.setup.review.cleared', 'notice', [
			'check_id' => $check_id,
		] );
		return true;
	}

	public static function save_section_note( string $section_id, string $note, int $user_id = 0 ): bool {
		if ( ! SectionRegistry::is_known( $section_id ) ) {
			throw new \InvalidArgumentException( 'Unknown Core Setup section.' );
		}
		if ( ! SectionRegistry::can_manage_note( $section_id ) ) {
			throw new \RuntimeException( 'Core Setup section access denied.' );
		}

		$before = ReviewRepository::section_note( $section_id );
		if ( ! ReviewRepository::save_section_note( $section_id, $note, $user_id ) ) {
			throw new \RuntimeException( 'Core Setup section note could not be saved.' );
		}
		$after = ReviewRepository::section_note( $section_id );

		$before_note = is_array( $before ) ? (string) ( $before['note'] ?? '' ) : '';
		$after_note  = is_array( $after ) ? (string) ( $after['note'] ?? '' ) : '';
		if ( $before_note === $after_note ) {
			return false;
		}

		AuditLog::log( 'core.setup.note.updated', 'notice', [
			'section_id'   => $section_id,
			'note_present' => '' !== $after_note,
			'note_length'  => strlen( $after_note ),
		] );
		return true;
	}

	private static function authorized_check( string $check_id ): CheckInterface {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( 'Core Setup access denied.' );
		}

		$check = Registry::get( $check_id );
		if ( ! $check instanceof CheckInterface ) {
			throw new \InvalidArgumentException( 'Unknown Core Setup check.' );
		}
		if ( ! current_user_can( $check->capability() ) ) {
			throw new \RuntimeException( 'Core Setup check access denied.' );
		}

		return $check;
	}

	/** @param array<string,mixed>|null $a @param array<string,mixed>|null $b */
	private static function same_review( ?array $a, ?array $b ): bool {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}

		foreach ( [ 'disposition', 'fingerprint', 'reason', 'updated_by' ] as $key ) {
			if ( ( $a[ $key ] ?? null ) !== ( $b[ $key ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	private function __construct() {}
}
