<?php
declare(strict_types=1);
/**
 * Privacy - Privacy & Logging page handlers.
 *
 * Two endpoints:
 *   - cb_core_save_privacy  → save individual settings (IP, verbosity, retention)
 *   - cb_core_apply_preset  → bulk-apply a governance preset's defaults
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Guards;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Privacy\Anonymizer;
use CoreBlueprint\Core\Privacy\Presets;
use CoreBlueprint\Core\Log\Verbosity;
use CoreBlueprint\Core\Governance\RetentionPolicy;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_save_privacy',  [ __CLASS__, 'save_privacy' ] );
		add_action( 'wp_ajax_cb_core_apply_preset',  [ __CLASS__, 'apply_preset' ] );
	}

	public static function save_privacy(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$user  = wp_get_current_user();
		$actor = 'admin:' . $user->user_login;

		// IP mode.
		if ( isset( $_POST['ip_mode'] ) ) {
			$mode = sanitize_key( wp_unslash( $_POST['ip_mode'] ) );
			if ( in_array( $mode, Anonymizer::MODES, true ) ) {
				Anonymizer::set_ip_mode( $mode, $actor );
			}
		}

		// Verbosity - nested array keyed by category. Explicit $_POST access
		// stays here because Request helpers are designed for scalar fields;
		// nested structures are the caller's responsibility.
		$verbosity = isset( $_POST['verbosity'] ) ? wp_unslash( $_POST['verbosity'] ) : [];
		if ( is_array( $verbosity ) ) {
			foreach ( $verbosity as $category => $level ) {
				$cat   = sanitize_key( $category );
				$level = sanitize_key( (string) $level );
				if ( in_array( $level, Verbosity::LEVELS, true ) ) {
					Verbosity::set_level( $cat, $level );
				}
			}
		}

		// Retention - nested array keyed by category.
		$retention = isset( $_POST['retention'] ) ? wp_unslash( $_POST['retention'] ) : [];
		if ( is_array( $retention ) ) {
			$allowed = [ 30, 60, 90, 180, 365, 730, 1095 ];
			$next = [];
			foreach ( $retention as $category => $days ) {
				$cat  = sanitize_key( (string) $category );
				$days = (int) $days;
				if ( RetentionPolicy::is_category( $cat ) && in_array( $days, $allowed, true ) ) {
					$next[ $cat ] = $days;
				}
			}
			RetentionPolicy::update( $next );
		}

		// Any individual save means we've drifted from the preset - mark custom.
		Presets::mark_custom();

		AuditLog::log( 'privacy.settings_updated', 'notice', [
			'actor' => $actor,
		] );

		wp_send_json_success( [
			'message' => __( 'Privacy settings saved.', 'core-blueprint' ),
		] );
	}

	public static function apply_preset(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();
		$slug = Request::sanitize_key( 'preset', Presets::PRESETS );

		$user = wp_get_current_user();
		$ok   = Presets::apply( $slug, 'admin:' . $user->user_login );

		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'Could not apply preset.', 'core-blueprint' ) ] );
		}

		wp_send_json_success( [
			'message' => __( 'Preset applied. Reloading…', 'core-blueprint' ),
		] );
	}
}
