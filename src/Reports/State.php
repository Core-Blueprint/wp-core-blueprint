<?php
declare(strict_types=1);
/**
 * State - subsystem master switch for Reports.
 *
 * Single source of truth for whether the Reports subsystem is active.
 * Read by the conditional admin-page registration in
 * {@see Bootstrap::register_admin_page()}, the AJAX guards on the
 * generate-report endpoint, the retention-pruner short-circuit in
 * {@see Storage::cleanup_expired_registered()}, and the master-switch UI
 * from the Dashboard; written through the canonical module activation
 * authority in {@see \CB\Core\Modules\ActivationRegistry}.
 *
 * The `enabled` flag lives directly under the existing
 * `cb_core_settings['reports']` array (sibling of `branding` and
 * `retention_days`). Missing state resolves to disabled for the public v1
 * contract.
 *
 * Suite philosophy reminder: every CB subsystem must be deactivatable
 * so operators can cover a given concern with their own tool of choice
 * (an external reporting service, a different report generator, no
 * reports at all on agency-internal sites that don't need them).
 * For Reports specifically, "off" means:
 *
 *   - Reports top-level admin menu item disappears entirely
 *   - The maintenance-report generate AJAX endpoint returns 403
 *   - The retention pruner short-circuits - old reports are not
 *     deleted while disabled, so re-enabling brings the historical
 *     archive back exactly as it was when disabled
 *   - Branding settings stay editable on the Preferences > Reports tab
 *     (activation is managed from Dashboard) so operators can prepare
 *     branding before re-enabling
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Reports;

use CB\Core\Log\AuditLog;
use CB\Core\Settings;

use CB\Core\Modules\ModuleStateInterface;
defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {

	/**
	 * Whether the Reports subsystem is currently active.
	 *
	 * Missing state is intentionally disabled for the public v1 contract.
	 */
	public static function is_enabled(): bool {
		$cb_settings = Settings::get();
		$reports     = is_array( $cb_settings['reports'] ?? null ) ? $cb_settings['reports'] : [];
		return (bool) ( $reports['enabled'] ?? false );
	}

	/**
	 * Persist the master state and emit an audit event on transitions.
	 *
	 * Idempotent - calls that don't change the state return early
	 * without logging. Notice severity (not info) because turning
	 * a whole subsystem on or off is operationally meaningful.
	 *
	 * Uses {@see Settings::set_key()} directly rather than going
	 * through a SettingsRepository because Reports doesn't have one
	 * - the existing Branding AJAX handler also writes via
	 * `Settings::set_key('reports', ...)`.
	 *
	 * @param bool   $enabled Target state.
	 * @param string $actor   Identifier for the audit row.
	 */
	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$was = self::is_enabled();
		if ( $was === $enabled ) {
			return;
		}

		$cb_settings        = Settings::get();
		$reports            = is_array( $cb_settings['reports'] ?? null ) ? $cb_settings['reports'] : [];
		$reports['enabled'] = $enabled;

		Settings::set_key( 'reports', $reports, 'reports' );

		if ( self::is_enabled() !== $enabled ) {
			throw new \RuntimeException( __( 'Reports state could not be persisted.', 'core-blueprint' ) );
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'reports_subsystem_enabled' : 'reports_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}
	}
}
