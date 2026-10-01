<?php
declare(strict_types=1);
/**
 * Settings - HUD subsystem defaults and kill-switch resolution.
 *
 * Defaults are layered as: code → site option → user_meta → session
 * (localStorage cache). This class exposes the defaults and the
 * resolution helpers; per-user reads/writes live in {@see Storage}.
 *
 * Kill-switch:
 *
 *   `is_enabled()` resolves to `false` when EITHER the site option
 *   `cb_core_hud_disabled` is truthy OR the `cb_core_hud_enabled` filter
 *   returns false. This is the single boot gate - checked once in
 *   Bootstrap::boot() and never again. To re-enable mid-session, the
 *   page must reload (acceptable: kill-switch is a recovery path, not
 *   a hot-toggle).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\HUD;

use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** Site-wide kill-switch option. Truthy = HUD bootstrap returns early. */
	public const OPTION_DISABLED = 'cb_core_hud_disabled';

	/**
	 * Code-level defaults. Future role/user override layers stack on top
	 * via Storage; these are the bottom of the resolution stack and
	 * apply when no override is present.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'enabled'           => true,
			'show_admin'        => true,
			'show_frontend'     => true,
			'default_position'  => 'bottom-right',
			'default_ghost'     => false,
			'default_brand'     => 'core-blueprint',
		];
	}

	/**
	 * Resolve whether HUD should bootstrap on this request. False
	 * short-circuits everything - no hooks, no enqueue, no rendering.
	 *
	 * Two paths to disabled:
	 *   1. `cb_core_hud_disabled` site option is truthy (set via
	 *      Preferences › Appearance toggle - user-facing kill switch)
	 *   2. `cb_core_hud_enabled` filter returns false (developer kill
	 *      switch, e.g. mu-plugin override during incident response)
	 *
	 * Defaults to enabled. Both gates must clear for HUD to load.
	 */
	public static function site_enabled(): bool {
		return ! (bool) get_option( self::OPTION_DISABLED, false );
	}

	/**
	 * Persist the site-wide user-facing HUD preference.
	 *
	 * The developer filter remains authoritative at runtime and is deliberately
	 * not mutated here. This method changes only the site preference.
	 */
	public static function set_site_enabled( bool $enabled, string $actor = 'unknown' ): bool {
		$before   = self::site_enabled();
		$disabled = $enabled ? '0' : '1';
		$stored   = get_option( self::OPTION_DISABLED, null );

		if ( null !== $stored && (string) $stored === $disabled ) {
			return true;
		}

		$updated = update_option( self::OPTION_DISABLED, $disabled, false );
		if ( ! $updated && self::site_enabled() !== $enabled ) {
			return false;
		}

		if ( $before !== $enabled && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'hud.visibility.changed', 'notice', [
				'actor'   => $actor,
				'enabled' => $enabled,
			] );
		}

		return true;
	}

	public static function is_enabled(): bool {
		if ( ! self::site_enabled() ) {
			return false;
		}

		/**
		 * Filter: cb_core_hud_enabled
		 *
		 * Developer-level kill-switch. Useful for mu-plugins during
		 * conflict-debugging, or for staging environments that want HUD
		 * disabled regardless of the user-facing toggle. Returning false
		 * skips bootstrap entirely.
		 *
		 * @param bool $enabled  Whether HUD is enabled. Default true.
		 */
		return (bool) apply_filters( 'cb_core_hud_enabled', true );
	}

	/**
	 * Default position for new users / unconfigured installations.
	 * Filter `cb_core_hud_default_position` lets white-label plugins
	 * change the out-of-box default.
	 */
	public static function default_position(): string {
		$default = self::defaults()['default_position'];
		return (string) apply_filters( 'cb_core_hud_default_position', $default );
	}

	/**
	 * Default ghost-mode state. Most users want the HUD button visible
	 * so default is false (= not ghosted). Filter lets white-label
	 * plugins override for sites that prefer a quieter default.
	 */
	public static function default_ghost(): bool {
		$default = (bool) self::defaults()['default_ghost'];
		return (bool) apply_filters( 'cb_core_hud_default_ghost', $default );
	}

	/**
	 * Default brand id. Used as fallback when no per-user override is
	 * present. White-label plugins set this to their own brand id via
	 * the filter so a fresh install of their build picks up their
	 * branding without user intervention.
	 */
	public static function default_brand(): string {
		$default = (string) self::defaults()['default_brand'];
		return (string) apply_filters( 'cb_core_hud_default_brand', $default );
	}
}
