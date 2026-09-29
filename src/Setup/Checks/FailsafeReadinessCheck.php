<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Failsafe readiness.
 *
 * The canonical Safeguards status provider owns the runtime self-test semantics.
 * Setup persists only a hash of bounded state, never the bypass token itself.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Modules\Status as ModuleStatus;
use CB\Core\Security\Failsafe;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class FailsafeReadinessCheck implements CheckInterface {

	public function id(): string { return 'failsafe-readiness'; }
	public function section(): string { return 'administrator-recovery'; }
	public function label(): string { return 'Failsafe readiness'; }
	public function kind(): string { return self::KIND_REQUIRED; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=failsafe' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$status = ModuleStatus::get( 'failsafe' );
			if ( ! is_array( $status ) ) {
				return Evidence::unavailable( 'failsafe.status-unavailable' );
			}

			$state = (string) ( $status['state'] ?? '' );
			$layers = Failsafe::active_layers();
			$token_present = defined( 'CB_CORE_BYPASS_TOK' )
				&& '' !== (string) get_option( CB_CORE_BYPASS_TOK, '' );
			$attention = in_array( $state, [ 'warn', 'err', 'off' ], true ) || ! $token_present;

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'failsafe.attention' : 'failsafe.ready',
				[
					'status_state'  => $state,
					'active_layers' => [
						'constant'  => ! empty( $layers['constant'] ),
						'option'    => ! empty( $layers['option'] ),
						'transient' => ! empty( $layers['transient'] ),
					],
					'token_present' => $token_present,
				],
				[
					'status_state'  => $state,
					'bypass_active' => in_array( true, array_map( 'boolval', $layers ), true ),
					'token_present' => $token_present,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'failsafe.unavailable' );
		}
	}
}
