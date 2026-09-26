<?php
declare(strict_types=1);
/**
 * SecurityRouter - AJAX coordinator for Core Blueprint's admin actions.
 *
 * Thin dispatcher that boots the feature-focused handler classes. Each
 * handler owns its own domain:
 *
 *   - Failsafe           - rotate_token, panic buttons, close_window
 *   - Settings           - site_mode, shield, modules/features, defaults, email alerts
 *   - Preferences        - description mode, header test
 *   - Exports            - CSV streams for audit/system/mr logs
 *   - Privacy            - privacy panel state + mode switching
 *   - LoginShield        - config save + custom-URL self-test
 *   - ExtensionLifecycle - native plugin activation/deactivation for extensions
 *   - Reports            - maintenance-report generation + PDF download streaming
 *   - Permissions        - operator-assignment + hide-toggle + admin-can-generate
 *   - TwoFactorPolicy    - site-wide privileged-account 2FA policy mutation
 *   - Branding           - reports-tab branding save + reset
 *
 * Class loading is handled by the PSR-4 autoloader - no includes here.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Ajax;

use CB\Core\Ajax\Handlers\Branding;
use CB\Core\Ajax\Handlers\Exports;
use CB\Core\Ajax\Handlers\ExtensionLifecycle;
use CB\Core\Ajax\Handlers\Failsafe;
use CB\Core\Ajax\Handlers\LoginShield;
use CB\Core\Ajax\Handlers\Modules;
use CB\Core\Ajax\Handlers\Permissions;
use CB\Core\Ajax\Handlers\Preferences;
use CB\Core\Ajax\Handlers\Privacy;
use CB\Core\Ajax\Handlers\Reports;
use CB\Core\Ajax\Handlers\Settings;
use CB\Core\Ajax\Handlers\TwoFactorPolicy;

defined( 'ABSPATH' ) || exit;

final class SecurityRouter {

	public static function init(): void {
		Failsafe::init();
		Settings::init();
		Preferences::init();
		Exports::init();
		Privacy::init();
		LoginShield::init();
		Modules::init();
		ExtensionLifecycle::init();
		Reports::init();
		Permissions::init();
		TwoFactorPolicy::init();
		Branding::init();
	}
}
