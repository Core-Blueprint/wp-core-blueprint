<?php
declare(strict_types=1);
/**
 * Read-only Core Setup progress snapshot.
 *
 * No percentages are produced. The snapshot reports raw state counts and a
 * small overall vocabulary so presentation cannot invent completion logic.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;

defined( 'ABSPATH' ) || exit;

final class Summary {

	public const NEEDS_ATTENTION  = 'needs_attention';
	public const REVIEW_INCOMPLETE = 'review_incomplete';
	public const REVIEWED         = 'reviewed';
	public const OVERALL_STATES   = [ self::NEEDS_ATTENTION, self::REVIEW_INCOMPLETE, self::REVIEWED ];

	/** @return array<string,mixed> */
	public static function current_user(): array {
		return self::build( Registry::visible() );
	}

	/** @param array<string,CheckInterface> $checks @return array<string,mixed> */
	public static function build( array $checks ): array {
		$lifecycle = Lifecycle::ensure_initialized();
		$definitions = SectionRegistry::all();
		$sections = [];
		$counts = self::empty_counts();

		foreach ( $definitions as $section_id => $definition ) {
			$sections[ $section_id ] = [
				'id'         => $section_id,
				'label'      => $definition['label'],
				'order'      => $definition['order'],
				'total'      => 0,
				'counts'     => self::empty_counts(),
				'checks'     => [],
				'note'       => SectionRegistry::can_manage_note( $section_id )
					? ReviewRepository::section_note( $section_id )
					: null,
				'note_access'=> SectionRegistry::can_manage_note( $section_id ),
			];
		}

		foreach ( $checks as $id => $check ) {
			if ( ! $check instanceof CheckInterface || ! SectionRegistry::is_known( $check->section() ) ) {
				throw new \LogicException( 'Core Setup summary received an invalid check definition.' );
			}

			try {
				$evidence = $check->evidence();
			} catch ( \Throwable $e ) {
				$evidence = Evidence::unavailable( 'setup.provider-unavailable' );
			}
			$review = ReviewRepository::check( $check->id() );
			$review_current = is_array( $review )
				&& isset( $review['fingerprint'] )
				&& is_string( $review['fingerprint'] )
				&& hash_equals( $evidence->fingerprint(), strtolower( $review['fingerprint'] ) );
			$status = StatusResolver::resolve( $check, $evidence, $review );

			$counts[ $status ]++;
			$section_id = $check->section();
			$sections[ $section_id ]['total']++;
			$sections[ $section_id ]['counts'][ $status ]++;
			$sections[ $section_id ]['checks'][ $id ] = [
				'id'                    => $check->id(),
				'label'                 => $check->label(),
				'kind'                  => $check->kind(),
				'status'                => $status,
				'evidence_health'       => $evidence->health(),
				'evidence_code'         => $evidence->code(),
				'review_current'        => $review_current,
				'configuration_url'     => $check->configuration_url(),
				'allows_later'          => $check->allows_later(),
				'allows_not_applicable' => $check->allows_not_applicable( $evidence ),
				'review'                => $review,
			];
		}

		$sections = array_filter(
			$sections,
			static fn( array $section ): bool => $section['total'] > 0
		);

		$overall = self::REVIEWED;
		if ( $counts[ StatusResolver::ATTENTION ] > 0 ) {
			$overall = self::NEEDS_ATTENTION;
		} elseif ( $counts[ StatusResolver::NEEDS_REVIEW ] > 0 ) {
			$overall = self::REVIEW_INCOMPLETE;
		}

		return [
			'origin'      => $lifecycle['origin'],
			'started_at'  => $lifecycle['started_at'],
			'overall'     => $overall,
			'total'       => count( $checks ),
			'counts'      => $counts,
			'sections'    => $sections,
		];
	}

	/** @return array<string,int> */
	private static function empty_counts(): array {
		return [
			StatusResolver::CONFIGURED     => 0,
			StatusResolver::NEEDS_REVIEW   => 0,
			StatusResolver::ATTENTION      => 0,
			StatusResolver::LATER          => 0,
			StatusResolver::NOT_APPLICABLE => 0,
		];
	}

	private function __construct() {}
}
