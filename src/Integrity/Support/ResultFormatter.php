<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use CoreBlueprint\Core\Integrity\Storage\ResultRepository;

use function array_slice;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function in_array;
use function max;
use function sprintf;
use function ucfirst;
use function usort;

use const CB_CORE_VERSION;

defined( 'ABSPATH' ) || exit;

final class ResultFormatter {
	public static function summary( ?array $result = null ): array {
		$result = $result ?? ResultRepository::getLatest();

		if ( ! is_array( $result ) ) {
			return [
				'status'     => 'idle',
				'last_scan'  => '',
				'source'     => '',
				'summary'    => [ 'total' => 0, 'ok' => 0, 'warning' => 0, 'critical' => 0 ],
				'components' => [],
				'completion' => 'not_run',
				'coverage'   => [],
				'baseline'   => self::baseline_summary(),
				'history'    => self::history_summary(),
				'diff'       => self::diff_summary( null ),
			];
		}

		return [
			'status'     => (string) ( $result['status'] ?? 'idle' ),
			'last_scan'  => (string) ( $result['completed_at'] ?? $result['timestamp'] ?? '' ),
			'source'     => (string) ( $result['source'] ?? '' ),
			'summary'    => (array) ( $result['summary'] ?? [ 'total' => 0, 'ok' => 0, 'warning' => 0, 'critical' => 0 ] ),
			'components' => (array) ( $result['components'] ?? [] ),
			'completion' => (string) ( $result['completion'] ?? ( is_array( $result['coverage'] ?? null ) ? ( $result['coverage']['state'] ?? 'incomplete' ) : 'incomplete' ) ),
			'coverage'   => is_array( $result['coverage'] ?? null ) ? $result['coverage'] : [],
			'baseline'   => self::baseline_summary(),
			'history'    => self::history_summary(),
			'diff'       => self::diff_summary( $result ),
		];
	}


	public static function diff_summary( ?array $result = null ): array {
		$result = $result ?? ResultRepository::getLatest();
		$diff = is_array( $result ) && is_array( $result["diff"] ?? null ) ? $result["diff"] : [];

		if ( [] === $diff ) {
			return [
				"has_previous" => false,
				"has_changes" => false,
				"summary" => [ "new" => 0, "changed" => 0, "missing" => 0, "unchanged" => 0 ],
				"components" => [],
			];
		}

		$summary = is_array( $diff["summary"] ?? null ) ? $diff["summary"] : [];

		return [
			"has_previous" => ! empty( $diff["has_previous"] ),
			"has_changes" => ! empty( $diff["has_changes"] ),
			"summary" => [
				"new" => (int) ( $summary["new"] ?? 0 ),
				"changed" => (int) ( $summary["changed"] ?? 0 ),
				"missing" => (int) ( $summary["missing"] ?? 0 ),
				"unchanged" => (int) ( $summary["unchanged"] ?? 0 ),
			],
			"components" => is_array( $diff["components"] ?? null ) ? $diff["components"] : [],
		];
	}

	public static function limited_findings( ?array $result = null, int $limit = 50 ): array {
		$result   = $result ?? ResultRepository::getLatest();
		$findings = self::review_findings( $result );

		return array_slice( $findings, 0, max( 1, $limit ) );
	}

	/**
	 * Findings that actually require human review.
	 *
	 * `checks()` is the complete persisted scan stream; findings are the
	 * anomaly-only operator projection.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function review_findings( ?array $result = null, string $component_filter = '' ): array {
		$result = $result ?? ResultRepository::getLatest();
		$out    = [];
		$index  = 0;

		$component_filter = self::normalise_component_filter( $component_filter );

		foreach ( self::checks( $result ) as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			if ( 'ok' === SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) ) ) {
				continue;
			}
			if ( '' !== $component_filter && $component_filter !== self::finding_component_filter_key( $finding ) ) {
				continue;
			}

			$out[] = [
				'finding'  => $finding,
				'index'    => $index++,
				'priority' => self::review_priority( $finding ),
			];
		}

		// Persisted checks follow scan execution order (Core → plugins → themes
		// → uploads). That order is useful internally but unsafe as the default
		// investigation order: dozens of first-run baseline notices could push a
		// later critical uploads anomaly beyond the first visible page. Sort only
		// the operator-facing view, always keeping the original order as the final
		// tie-breaker.
		usort( $out, static function ( array $a, array $b ): int {
			$ap = is_array( $a['priority'] ?? null ) ? $a['priority'] : [ 9, 9 ];
			$bp = is_array( $b['priority'] ?? null ) ? $b['priority'] : [ 9, 9 ];
			if ( $ap[0] !== $bp[0] ) {
				return $ap[0] <=> $bp[0];
			}
			if ( $ap[1] !== $bp[1] ) {
				return $ap[1] <=> $bp[1];
			}
			return (int) ( $a['index'] ?? 0 ) <=> (int) ( $b['index'] ?? 0 );
		} );

		return array_values( array_map( static fn( array $row ): array => (array) $row['finding'], $out ) );
	}

	/**
	 * Investigation priority for the operator-facing findings list.
	 *
	 * 0: actual anomaly
	 * 1: verification/coverage problem
	 * 2: baseline/setup requirement
	 *
	 * Severity is the primary key; this secondary key only orders findings
	 * within the same severity.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function review_priority( array $finding ): array {
		$severity = SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'warning' ) );
		$severity_rank = 'critical' === $severity ? 0 : 1;
		$status = (string) ( $finding['status'] ?? '' );

		if ( 'baseline_required' === $status ) {
			return [ $severity_rank, 2 ];
		}

		if ( in_array( $status, [ 'verification_failed', 'scan_incomplete', 'unreadable', 'unverifiable', 'symlink_skipped' ], true ) ) {
			return [ $severity_rank, 1 ];
		}

		return [ $severity_rank, 0 ];
	}

	/**
	 * Paginated anomaly findings for REST/Hub consumers.
	 *
	 * Page-size is controlled by the caller; the formatter itself does not
	 * impose a hidden total cap. This keeps large incident sets navigable while
	 * avoiding a huge response by default.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int,offset:int,limit:int,has_more:bool,next_offset:?int}
	 */
	public static function findings_page( ?array $result = null, int $offset = 0, int $limit = 50, string $component_filter = '' ): array {
		$findings = self::review_findings( $result, $component_filter );
		$total    = count( $findings );
		$offset   = max( 0, $offset );
		$limit    = max( 1, $limit );
		$items    = array_slice( $findings, $offset, $limit );
		$next     = $offset + count( $items );

		return [
			'items'       => $items,
			'total'       => $total,
			'offset'      => $offset,
			'limit'       => $limit,
			'has_more'    => $next < $total,
			'next_offset' => $next < $total ? $next : null,
		];
	}

	public static function grouped_findings( ?array $result = null, int $limit = 50, string $component_filter = '' ): array {
		$findings = array_slice( self::review_findings( $result, $component_filter ), 0, max( 1, $limit ) );
		return self::group_finding_list( $findings, false );
	}

	public static function grouped_findings_page( ?array $result = null, int $offset = 0, int $limit = 50, string $component_filter = '' ): array {
		$page = self::findings_page( $result, $offset, $limit, $component_filter );
		return self::group_finding_list( $page['items'], false );
	}

	/**
	 * Group an already-filtered review finding list for operator-facing views.
	 *
	 * @param array<int,array<string,mixed>> $findings
	 */
	public static function group_review_findings( array $findings ): array {
		return self::group_finding_list( $findings, false );
	}

	public static function grouped_passed( ?array $result = null, int $limit = 500 ): array {
		return self::grouped_checks( $result, $limit, true );
	}

	private static function grouped_checks( ?array $result = null, int $limit = 50, bool $passed = false ): array {
		$result   = $result ?? ResultRepository::getLatest();
		$filtered = [];

		foreach ( self::checks( $result ) as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}

			$is_ok = 'ok' === SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) );
			if ( $passed === $is_ok ) {
				$filtered[] = $finding;
			}
		}

		$findings = array_slice( $filtered, 0, max( 1, $limit ) );
		return self::group_finding_list( $findings, $passed );
	}

	private static function normalise_component_filter( string $component_filter ): string {
		$component_filter = sanitize_key( $component_filter );
		return in_array( $component_filter, [ 'core', 'plugins', 'themes', 'uploads' ], true ) ? $component_filter : '';
	}

	private static function finding_component_filter_key( array $finding ): string {
		$component = sanitize_key( (string) ( $finding['type'] ?? '' ) );
		return match ( $component ) {
			'plugin' => 'plugins',
			'theme'  => 'themes',
			default  => $component,
		};
	}

	/** @param array<int,array<string,mixed>> $findings */
	private static function group_finding_list( array $findings, bool $passed ): array {
		$groups   = [];

		foreach ( $findings as $finding ) {

			$component = (string) ( $finding['type'] ?? 'other' );
			$slug      = (string) ( is_array( $finding['target'] ?? null ) ? ( $finding['target']['slug'] ?? '' ) : '' );
			$type      = (string) ( $finding['status'] ?? $finding['type'] ?? 'notice' );
			$key       = self::group_key( $component, $slug, $type );

			if ( ! isset( $groups[ $component ] ) ) {
				$groups[ $component ] = [];
			}

			if ( ! isset( $groups[ $component ][ $key ] ) ) {
				$groups[ $component ][ $key ] = [
					'component' => $component,
					'slug'      => self::display_slug( $component, $slug, $type, $finding ),
					'path'      => self::target_path( $finding ),
					'status'    => self::status_for_finding( $finding ),
					'severity'  => SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) ),
					'message'   => self::plain_message( $finding ),
					'count'     => 0,
					'findings'  => [],
					'can_approve_baseline' => self::can_approve_baseline( $finding ),
					'baseline'  => self::component_baseline_meta( $finding ),
				];
			}

			$groups[ $component ][ $key ]['count']++;
			$groups[ $component ][ $key ]['findings'][] = $finding;
			$groups[ $component ][ $key ]['severity'] = SeverityMapper::highest(
				(string) $groups[ $component ][ $key ]['severity'],
				(string) ( $finding['severity'] ?? 'ok' )
			);
		}

		return $groups;
	}

	public static function checks( ?array $result ): array {
		if ( ! is_array( $result ) || ! is_array( $result['checks'] ?? null ) ) {
			return [];
		}

		return $result['checks'];
	}

	private static function target_path( array $finding ): string {
		$target = isset( $finding['target'] ) && is_array( $finding['target'] ) ? $finding['target'] : [];

		return (string) ( $target['path'] ?? '' );
	}

	private static function group_key( string $component, string $slug, string $type ): string {
		if ( 'plugin' === $component || 'theme' === $component ) {
			return '' !== $slug ? $slug : $component . '-unknown';
		}

		if ( 'core' === $component ) {
			return 'wordpress-core';
		}

		if ( 'uploads' === $component ) {
			return 'uploads-' . $type;
		}

		return $component . '-' . $type;
	}

	private static function display_slug( string $component, string $slug, string $type, array $finding = [] ): string {
		if ( 'core' === $component ) {
			return __( 'WordPress Core', 'core-blueprint' );
		}

		if ( 'uploads' === $component ) {
			return __( 'Uploads', 'core-blueprint' );
		}

		$target = isset( $finding['target'] ) && is_array( $finding['target'] ) ? $finding['target'] : [];
		$label  = (string) ( $target['label'] ?? '' );
		if ( '' !== $label ) {
			return $label;
		}

		if ( '' !== $slug ) {
			return $slug;
		}

		return ucfirst( $component );
	}

	private static function status_for_finding( array $finding ): string {
		$type     = (string) ( $finding['status'] ?? $finding['type'] ?? '' );
		$severity = SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) );

		if ( 'unsupported' === $type || 'baseline_required' === $type ) {
			return 'needs_baseline';
		}

		if ( 'verification_failed' === $type ) {
			return 'verification_failed';
		}

		if ( 'changed' === $type || 'new' === $type || 'missing' === $type ) {
			return $type;
		}

		if ( 'critical' === $severity ) {
			return 'critical';
		}

		if ( 'warning' === $severity ) {
			return 'warning';
		}

		return 'notice';
	}

	private static function plain_message( array $finding ): string {
		$component = (string) ( $finding['type'] ?? '' );
		$target    = isset( $finding['target'] ) && is_array( $finding['target'] ) ? $finding['target'] : [];
		$slug      = (string) ( $target['slug'] ?? '' );
		$label     = (string) ( $target['label'] ?? $slug );
		$type      = (string) ( $finding['status'] ?? $finding['type'] ?? '' );
		$message   = (string) ( $finding['message'] ?? '' );

		if ( 'ok' === $type && '' !== $message ) {
			return $message;
		}

		if ( ( 'unsupported' === $type || 'baseline_required' === $type ) && ( 'plugin' === $component || 'theme' === $component ) ) {
			return sprintf(
				/* translators: 1: component type, 2: component label. */
				__( '%1$s %2$s needs a local approved baseline because no official checksum is available.', 'core-blueprint' ),
				ucfirst( $component ),
				$label
			);
		}

		if ( 'verification_failed' === $type && ( 'plugin' === $component || 'theme' === $component ) ) {
			return sprintf(
				/* translators: 1: component type, 2: component label. */
				__( '%1$s %2$s is installed from WordPress.org, but checksum verification could not be completed.', 'core-blueprint' ),
				ucfirst( $component ),
				$label
			);
		}

		if ( '' !== $label ) {
			return sprintf( '%s: %s', $label, $message );
		}

		return $message;
	}

	private static function can_approve_baseline( array $finding ): bool {
		return ResultRepository::isBaselineCandidateCheck( $finding );
	}

	private static function component_baseline_meta( array $finding ): array {
		$component = (string) ( $finding['type'] ?? '' );
		$target    = is_array( $finding['target'] ?? null ) ? $finding['target'] : [];
		$slug      = (string) ( $target['slug'] ?? '' );

		if ( '' === $component || '' === $slug ) {
			return [ 'exists' => false ];
		}

		return ResultRepository::componentBaselineMeta( $component, $slug );
	}

	public static function history_summary( int $limit = 10 ): array {
		$history = ResultRepository::getHistory();
		$items   = [];

		foreach ( array_slice( $history, 0, max( 1, $limit ) ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$items[] = [
				'id'         => (string) ( $entry['id'] ?? $entry['timestamp'] ?? '' ),
				'timestamp'  => (string) ( $entry['timestamp'] ?? '' ),
				'source'     => (string) ( $entry['source'] ?? '' ),
				'status'     => (string) ( $entry['status'] ?? 'idle' ),
				'summary'    => is_array( $entry['summary'] ?? null ) ? $entry['summary'] : [],
				'components' => is_array( $entry['components'] ?? null ) ? $entry['components'] : [],
				'completion' => (string) ( $entry['completion'] ?? 'unknown' ),
				'coverage'   => is_array( $entry['coverage'] ?? null ) ? $entry['coverage'] : [],
			];
		}

		return [
			'total' => count( $history ),
			'items' => $items,
		];
	}

	private static function baseline_summary(): array {
		$baseline = ResultRepository::getBaseline();
		if ( ! is_array( $baseline ) ) {
			return [ 'exists' => false, 'created_at' => '', 'entry_count' => 0 ];
		}

		$entries = is_array( $baseline['entries'] ?? null ) ? $baseline['entries'] : [];

		return [
			'exists'      => [] !== $entries,
			'created_at'  => (string) ( $baseline['created_at'] ?? '' ),
			'entry_count' => count( $entries ),
			'version'     => (string) ( $baseline['plugin_version'] ?? CB_CORE_VERSION ),
			'approved_at' => (string) ( $baseline['approved_at'] ?? $baseline['created_at'] ?? '' ),
			'approved_by' => (int) ( $baseline['approved_by'] ?? 0 ),
			'updated_at'  => (string) ( $baseline['updated_at'] ?? '' ),
			'updated_by'  => (int) ( $baseline['updated_by'] ?? 0 ),
		];
	}
}
