<?php
declare(strict_types=1);
/**
 * Presets - governance starter templates.
 *
 * Four presets arranged on an intensity scale, independent of industry or
 * vertical. Each preset applies a coherent set of privacy, verbosity, and
 * retention settings as a single choice.
 *
 *   - 'minimal'  - light-touch logging for dev / staging / demo
 *   - 'standard' - balanced defaults (default choice)
 *   - 'enhanced' - extended retention for sites collecting contact data
 *   - 'strict'   - maximum auditability for sites under compliance obligations
 *
 * A preset is just a flat array of settings. Applying a preset writes
 * each key to the relevant option. After that, every setting remains
 * individually overrideable - a preset doesn't lock anything.
 *
 * The detection logic compares the current config against each preset's
 * defaults; if they match exactly, that preset is "active", otherwise
 * the active preset is whatever the user selected last, with a "Custom
 * (based on X)" indicator in the UI.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Privacy;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Log\Verbosity;
use CoreBlueprint\Core\Governance\RetentionPolicy;

defined( 'ABSPATH' ) || exit;

final class Presets {

	const OPTION_ACTIVE_KEY = 'cb_core_privacy_active_preset';

	const PRESET_MINIMAL  = 'minimal';
	const PRESET_STANDARD = 'standard';
	const PRESET_ENHANCED = 'enhanced';
	const PRESET_STRICT   = 'strict';
	const PRESET_CUSTOM   = 'custom';

	const PRESETS = [ self::PRESET_MINIMAL, self::PRESET_STANDARD, self::PRESET_ENHANCED, self::PRESET_STRICT ];

	/**
	 * Preset defaults. Keys reference settings managed by other classes:
	 *   privacy.ip_mode   → Anonymizer
	 *   verbosity.{cat}   → Verbosity
	 *   retention.{cat}   → RetentionPolicy
	 *
	 * Retention values are in days. 'security' covers security.* events,
	 * AuditLog retention uses exactly security / maintenance / logins / settings / general.
	 */
	public static function definitions(): array {
		return [
			self::PRESET_MINIMAL => [
				'label'       => __( 'Minimal', 'core-blueprint' ),
				'description' => __( 'Light-touch logging for development, staging, and private demo sites. Short retention, minimal event capture. Not recommended once a site handles real visitor data.', 'core-blueprint' ),
				'privacy'     => [
					'ip_mode' => Anonymizer::MODE_NONE,
				],
				'verbosity'   => [
					'logins'   => Verbosity::LEVEL_DISABLED,
					'plugins'  => Verbosity::LEVEL_CRITICAL_ONLY,
					'themes'   => Verbosity::LEVEL_CRITICAL_ONLY,
					'core'     => Verbosity::LEVEL_ALWAYS,
					'users'    => Verbosity::LEVEL_ALWAYS,
					'settings' => Verbosity::LEVEL_ADMINS_ONLY,
				],
				'retention'   => [
					'security'    => 90,
					'maintenance' => 90,
					'logins'      => 30,
					'settings'    => 90,
					'general'     => 90,
				],
			],
			self::PRESET_STANDARD => [
				'label'       => __( 'Standard', 'core-blueprint' ),
				'description' => __( 'Balanced defaults for marketing sites, portfolios, and internal tools. One-year retention, admin logins tracked, IPs anonymised. Recommended as a starting point for most sites.', 'core-blueprint' ),
				'privacy'     => [
					'ip_mode' => Anonymizer::MODE_ANONYMIZED,
				],
				'verbosity'   => [
					'logins'   => Verbosity::LEVEL_ADMINS_ONLY,
					'plugins'  => Verbosity::LEVEL_ALWAYS,
					'themes'   => Verbosity::LEVEL_ALWAYS,
					'core'     => Verbosity::LEVEL_ALWAYS,
					'users'    => Verbosity::LEVEL_ALWAYS,
					'settings' => Verbosity::LEVEL_ALWAYS,
				],
				'retention'   => [
					'security'    => 365,
					'maintenance' => 365,
					'logins'      => 90,
					'settings'    => 365,
					'general'     => 365,
				],
			],
			self::PRESET_ENHANCED => [
				'label'       => __( 'Enhanced', 'core-blueprint' ),
				'description' => __( 'Extended retention for sites that collect or process contact data. Full event capture and longer login history. Recommended once a site accepts form submissions, processes orders, or keeps any client-identifiable records.', 'core-blueprint' ),
				'privacy'     => [
					'ip_mode' => Anonymizer::MODE_ANONYMIZED,
				],
				'verbosity'   => [
					'logins'   => Verbosity::LEVEL_ADMINS_ONLY,
					'plugins'  => Verbosity::LEVEL_ALWAYS,
					'themes'   => Verbosity::LEVEL_ALWAYS,
					'core'     => Verbosity::LEVEL_ALWAYS,
					'users'    => Verbosity::LEVEL_ALWAYS,
					'settings' => Verbosity::LEVEL_ALWAYS,
				],
				'retention'   => [
					'security'    => 365,
					'maintenance' => 365,
					'logins'      => 180,
					'settings'    => 365,
					'general'     => 365,
				],
			],
			self::PRESET_STRICT => [
				'label'       => __( 'Strict', 'core-blueprint' ),
				'description' => __( 'Maximum auditability for sites operating under compliance obligations. Full IP retention (required for forensic trails), multi-year retention across all categories, every event logged. Use when legal or regulatory requirements apply.', 'core-blueprint' ),
				'privacy'     => [
					// Full IP is required for forensic trail when compliance
					// obligations apply. The processor agreement covers this.
					'ip_mode' => Anonymizer::MODE_FULL,
				],
				'verbosity'   => [
					'logins'   => Verbosity::LEVEL_ALWAYS,
					'plugins'  => Verbosity::LEVEL_ALWAYS,
					'themes'   => Verbosity::LEVEL_ALWAYS,
					'core'     => Verbosity::LEVEL_ALWAYS,
					'users'    => Verbosity::LEVEL_ALWAYS,
					'settings' => Verbosity::LEVEL_ALWAYS,
				],
				'retention'   => [
					'security'    => 1095,   // 3 years
					'maintenance' => 730,    // 2 years
					'logins'      => 365,    // 1 year
					'settings'    => 1095,
					'general'     => 1095,
				],
			],
		];
	}

	/**
	 * The currently-selected preset slug (or 'custom').
	 */
	public static function active(): string {
		$slug = (string) get_option( self::OPTION_ACTIVE_KEY, self::PRESET_STANDARD );
		if ( self::PRESET_CUSTOM === $slug ) {
			return self::PRESET_CUSTOM;
		}
		return in_array( $slug, self::PRESETS, true ) ? $slug : self::PRESET_STANDARD;
	}

	/**
	 * Apply a preset - write all its settings to the relevant options.
	 * The 'active preset' marker is updated to match. Returns false on
	 * invalid slug.
	 */
	public static function apply( string $slug, string $actor = 'admin' ): bool {
		$defs = self::definitions();
		if ( ! isset( $defs[ $slug ] ) ) {
			return false;
		}
		$preset = $defs[ $slug ];

		// Privacy.
		if ( isset( $preset['privacy']['ip_mode'] ) ) {
			Anonymizer::set_ip_mode( $preset['privacy']['ip_mode'], $actor );
		}

		// Verbosity.
		foreach ( $preset['verbosity'] as $category => $level ) {
			Verbosity::set_level( $category, $level );
		}

		// Retention.
		RetentionPolicy::update( $preset['retention'] );

		// Mark this preset active.
		update_option( self::OPTION_ACTIVE_KEY, $slug, false );

		// Governance event - who changed the preset, when, from what.
		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'privacy.preset_applied', 'notice', [
				'preset' => $slug,
				'actor'  => $actor,
			] );
		}

		return true;
	}

	/**
	 * Compare current config against a preset - does the current state
	 * match the preset exactly? Used by the UI to decide whether to show
	 * "Custom (based on X)" next to the active preset name.
	 */
	public static function matches( string $slug ): bool {
		$defs = self::definitions();
		if ( ! isset( $defs[ $slug ] ) ) {
			return false;
		}
		$preset = $defs[ $slug ];

		if ( Anonymizer::ip_mode() !== ( $preset['privacy']['ip_mode'] ?? '' ) ) {
			return false;
		}

		foreach ( $preset['verbosity'] as $category => $level ) {
			if ( Verbosity::level_for_category( $category ) !== $level ) {
				return false;
			}
		}

		$current_retention = RetentionPolicy::all();
		foreach ( $preset['retention'] as $category => $days ) {
			if ( (int) ( $current_retention[ $category ] ?? 0 ) !== (int) $days ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark the configuration as "custom" - called when an individual
	 * setting is changed outside a preset-apply flow.
	 */
	public static function mark_custom(): void {
		update_option( self::OPTION_ACTIVE_KEY, self::PRESET_CUSTOM, false );
	}
}
