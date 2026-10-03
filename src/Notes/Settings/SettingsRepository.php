<?php
declare(strict_types=1);
/**
 * Settings repository for Core Blueprint Notes.
 *
 * Reads from / writes to the central CB Base settings array
 * (`cb_core_settings['notes']` subkey) via {@see \CoreBlueprint\Core\Settings}.
 * Notes does NOT own a separate option; configuration is part of the
 * single CB Base settings transaction so site-mode changes and module
 * toggles can stay atomic.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Notes\Settings;

use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsRepository {

	/**
	 * Return the merged Notes settings (defaults overlaid by stored
	 * values, then re-sanitised so callers can rely on every key being
	 * present and valid).
	 */
	public static function all(): array {
		$cb_settings = Settings::get();
		$stored      = is_array( $cb_settings['notes'] ?? null ) ? $cb_settings['notes'] : [];

		return Defaults::sanitize( array_merge( Defaults::values(), $stored ) );
	}

	/**
	 * Persist Notes settings to the central CB Base settings array.
	 *
	 * Incoming settings are merged over the current stored state before
	 * sanitisation, so partial updates (e.g. just `enabled`, just the
	 * form fields) preserve every key the caller didn't touch. Without
	 * this merge, the form-POST handler - which submits only its own
	 * five fields - would silently flip `enabled` back to the default
	 * on every save, undoing any prior master-switch toggle.
	 *
	 * Sanitises before write; the actor 'notes' surfaces in the audit
	 * log entry that {@see Settings::set_key()} emits.
	 */
	public static function update( array $settings ): void {
		$merged = array_merge( self::all(), $settings );
		Settings::set_key( 'notes', Defaults::sanitize( $merged ), 'notes' );
	}

	public static function get( string $key ) {
		$settings = self::all();
		return $settings[ $key ] ?? null;
	}
}
