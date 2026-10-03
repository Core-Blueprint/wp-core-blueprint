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

namespace CoreBlueprint\Core\Ajax;

use CoreBlueprint\Core\Ajax\Handlers\Branding;
use CoreBlueprint\Core\Ajax\Handlers\Exports;
use CoreBlueprint\Core\Ajax\Handlers\ExtensionLifecycle;
use CoreBlueprint\Core\Ajax\Handlers\Failsafe;
use CoreBlueprint\Core\Ajax\Handlers\LoginShield;
use CoreBlueprint\Core\Ajax\Handlers\Modules;
use CoreBlueprint\Core\Ajax\Handlers\Permissions;
use CoreBlueprint\Core\Ajax\Handlers\Preferences;
use CoreBlueprint\Core\Ajax\Handlers\Privacy;
use CoreBlueprint\Core\Ajax\Handlers\Reports;
use CoreBlueprint\Core\Ajax\Handlers\Settings;
use CoreBlueprint\Core\Ajax\Handlers\TwoFactorPolicy;

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
