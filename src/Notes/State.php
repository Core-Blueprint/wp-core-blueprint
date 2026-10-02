<?php
declare(strict_types=1);
/**
 * State - subsystem master switch for Notes.
 *
 * Single source of truth for whether the Notes subsystem is active. Read by
 * the conditional admin-page registration in {@see Bootstrap::boot()}, the
 * REST guards in {@see Rest\NotesController}, and the master-switch UI in
 * {@see Admin\PreferencesPage::render_body()}; written by the toggle
 * endpoint at POST /notes/enable.
 *
 * The `enabled` flag lives inside the existing `cb_core_settings['notes']`
 * subkey alongside the per-feature defaults (default_type, default_status,
 * default_layout, etc.). Missing state resolves to disabled for the public v1
 * contract.
 *
 * Suite philosophy reminder: every CB subsystem must be deactivatable so
 * operators can cover a given concern with their own tool of choice. For
 * Notes specifically: when disabled the top-level admin menu item
 * disappears entirely, the REST write paths return 403, and any stored
 * notes are preserved untouched. The Dashboard remains the activation recovery surface so re-enabling is
 * always one click away.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Notes;

use CB\Core\Log\AuditLog;
use CB\Core\Notes\Settings\SettingsRepository;

use CB\Core\Modules\ModuleStateInterface;
defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {

	/**
	 * Whether the Notes subsystem is currently active.
	 *
	 * Missing state is intentionally disabled for the public v1 contract.
	 */
	public static function is_enabled(): bool {
		$settings = SettingsRepository::all();
		return (bool) ( $settings['enabled'] ?? false );
	}

	/**
	 * Persist the master state and emit an audit event on transitions.
	 *
	 * Idempotent - calls that don't change the state return early
	 * without logging. Notice severity (not info) because turning a
	 * whole subsystem on or off is operationally meaningful enough
	 * to warrant the bump above the default.
	 *
	 * @param bool   $enabled Target state.
	 * @param string $actor   Identifier for the audit row.
	 */
	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$was = self::is_enabled();
		if ( $was === $enabled ) {
			return;
		}

		// Partial update - SettingsRepository::update() merges with current
		// state internally so the other Notes preferences (default_type
		// etc.) are preserved without us having to re-read and re-pass them.
		SettingsRepository::update( [ 'enabled' => $enabled ] );

		if ( self::is_enabled() !== $enabled ) {
			throw new \RuntimeException( __( 'Notes state could not be persisted.', 'core-blueprint' ) );
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'notes_subsystem_enabled' : 'notes_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}
	}
}
