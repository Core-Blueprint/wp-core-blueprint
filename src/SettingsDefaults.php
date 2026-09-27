<?php
declare(strict_types=1);
/**
 * Canonical Base-owned settings defaults.
 *
 * Extensions own their own persistent settings. This schema intentionally
 * contains only Base state; Settings::defaults() remains the stable public
 * facade for callers that need the complete Base defaults document.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core;

defined( 'ABSPATH' ) || exit;

final class SettingsDefaults {

	public static function all(): array {
		return [
			'site_mode'      => 'production',
			'shield_enabled' => true,
			'modules'        => [],
			'login_shield'   => \CB\Core\Security\LoginShield::default_config(),
			'two_factor'     => \CB\Core\Security\TwoFactor\Policy::default_config(),
			'audit'          => [
				'email_recipient' => '',
				'email_alerts'    => [
					'critical' => true,
					'warning'  => false,
					'notice'   => false,
					'info'     => false,
				],
			],
			'integrity'      => [
				'schedule'                     => 'disabled',
				'email_recipient'              => '',
				'email_alerts'                 => [
					'critical_anomaly' => true,
					'warning_anomaly'  => false,
					'resolved'         => true,
				],
				'plugin_checksums'             => true,
				'theme_checksums'              => true,
				'uploads_scan'                 => true,
				'max_visible_findings'         => 50,
				'admin_can_run'                => false,
				'distribution_locale_mode'     => 'fallback',
				'distribution_locale_detected' => '',
				'distribution_locale_override' => '',
				'distribution_locale_meta'     => [
					'last_detected_at' => '',
					'tried'            => [],
					'matched_file'     => '',
					'cross_check'      => '',
				],
			],
			'notes'          => [
				'default_type'          => 'General',
				'default_status'        => 'Backlog',
				'default_assigned_to'   => 0,
				'details_initial_state' => 'remember',
				'default_layout'        => 'list',
			],
			'reports'        => [
				'admin_can_generate' => [
					'maintenance' => false,
				],
				'retention_days'     => 365,
				'email_recipient'    => '',
				'email_alerts'       => [
					'generation_failed' => true,
				],
				'branding'           => [
					'logo_attachment_id' => 0,
					'provider_name'       => '',
					'provider_contact'    => '',
					'accent_color'        => '#0064c8',
				],
				'composer'           => [
					'maintenance' => \CB\Core\Reports\Composer\MaintenanceTemplate::defaults(),
				],
			],
			'permissions'    => [
				'hide_from_admins'       => false,
				'privileged_access_mode' => 'enforce',
				'email_recipient'        => '',
				'email_alerts'           => [
					'role_change'              => true,
					'operator_guard_triggered' => true,
					'privileged_review'        => true,
				],
			],
			'schema_version' => Settings::SCHEMA_VERSION,
		];
	}
}
