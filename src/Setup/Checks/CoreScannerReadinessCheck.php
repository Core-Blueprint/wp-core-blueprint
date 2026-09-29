<?php
declare(strict_types=1);
/**
 * Core Setup evidence for current Core Scanner readiness/results.
 *
 * Timestamps are presentation context only. A new clean scan with materially
 * identical outcome therefore does not invalidate a prior Setup review.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Integrity\State;
use CB\Core\Integrity\Storage\ResultRepository;
use CB\Core\Modules\Status as ModuleStatus;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class CoreScannerReadinessCheck implements CheckInterface {

	public function id(): string { return 'core-scanner-readiness'; }
	public function section(): string { return 'safeguards'; }
	public function label(): string { return 'Core Scanner readiness'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'cb_manage_integrity_policy'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=core-scanner' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$enabled = State::is_enabled();
			if ( ! $enabled ) {
				return new Evidence(
					Evidence::HEALTH_OK,
					'core-scanner.disabled',
					[ 'enabled' => false ],
					[ 'enabled' => false, 'has_result' => ResultRepository::hasResult() ]
				);
			}

			$status = ModuleStatus::get( 'core-scanner' );
			if ( ! is_array( $status ) ) {
				return Evidence::unavailable( 'core-scanner.status-unavailable' );
			}

			$status_state = (string) ( $status['state'] ?? '' );
			$summary = ResultRepository::getSummary();
			$findings = is_array( $summary['summary'] ?? null ) ? $summary['summary'] : [];
			$coverage = is_array( $summary['coverage'] ?? null ) ? $summary['coverage'] : [];
			$has_result = ResultRepository::hasResult();
			$attention = in_array( $status_state, [ 'warn', 'err', 'off' ], true );

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention
					? 'core-scanner.findings'
					: ( $has_result ? 'core-scanner.clean' : 'core-scanner.not-run' ),
				[
					'enabled'      => true,
					'has_result'   => $has_result,
					'status_state' => $status_state,
					'completion'   => (string) ( $summary['completion'] ?? 'not_run' ),
					'coverage'     => (string) ( $coverage['state'] ?? '' ),
					'findings'     => [
						'total'    => (int) ( $findings['total'] ?? 0 ),
						'ok'       => (int) ( $findings['ok'] ?? 0 ),
						'warning'  => (int) ( $findings['warning'] ?? 0 ),
						'critical' => (int) ( $findings['critical'] ?? 0 ),
					],
					'has_baseline' => ResultRepository::hasBaseline(),
				],
				[
					'enabled'      => true,
					'has_result'   => $has_result,
					'status_state' => $status_state,
					'last_scan'    => (string) ( $summary['last_scan'] ?? '' ),
					'warning'      => (int) ( $findings['warning'] ?? 0 ),
					'critical'     => (int) ( $findings['critical'] ?? 0 ),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'core-scanner.readiness-unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['enabled'] );
	}
}
