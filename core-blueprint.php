<?php
/**
 * Plugin Name: Core Blueprint
 * Plugin URI:  https://coreblueprint.io
 * Description: The Core Blueprint foundation plugin. Security baseline, audit logging, failsafe lockout prevention, admin theming, site-wide locale preference, and governed shared services for the Core Blueprint suite.
 * Version:     1.0.0-rc1
 * Author:      Core Blueprint
 * Author URI:  https://coreblueprint.io
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: core-blueprint
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP:      8.4
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

// Defensive duplicate-load guard. A mispackaged upgrade may temporarily exist
// beside the canonical plugin and WordPress can attempt to include both active
// entrypoints in one request. The first loaded copy owns the runtime; every
// subsequent copy exits before redefining constants or bootstrapping services.
// The duplicate path is still written to the PHP error log so packaging mistakes
// remain diagnosable without taking wp-admin down.
if ( defined( 'CB_CORE_FILE' ) || defined( 'CB_CORE_VERSION' ) ) {
	$loaded_file = defined( 'CB_CORE_FILE' ) ? (string) CB_CORE_FILE : '';
	if ( '' !== $loaded_file && $loaded_file !== __FILE__ ) {
		error_log( sprintf( '[Core Blueprint] Duplicate plugin load prevented. Active entrypoint: %s; skipped: %s', $loaded_file, __FILE__ ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- emergency bootstrap diagnostic.
	}
	return;
}

// ─── Plugin constants ─────────────────────────────────────────────────────────

define( 'CB_CORE_VERSION',     '1.0.0-rc1' );
define( 'CB_CORE_API_VERSION', '1.1' );
define( 'CB_CORE_MIN_PHP',         '8.4' );
define( 'CB_CORE_RECOMMENDED_PHP', '8.5' );
define( 'CB_CORE_DB_VERSION',  '1.0' );
define( 'CB_CORE_FILE',        __FILE__ );
define( 'CB_CORE_DIR',         plugin_dir_path( __FILE__ ) );
define( 'CB_CORE_URL',         plugin_dir_url( __FILE__ ) );
define( 'CB_CORE_BASENAME',    plugin_basename( __FILE__ ) );

// Option keys - Foundation layer
define( 'CB_CORE_SETTINGS',    'cb_core_settings' );
define( 'CB_CORE_BYPASS_OPT',  'cb_core_bypass_active' );
define( 'CB_CORE_BYPASS_TOK',  'cb_core_bypass_token' );

// Parent-menu slug - shared across all CB plugins that contribute submenus.
define( 'CB_CORE_PARENT_MENU', 'core-blueprint' );

// ─── Requirements check ───────────────────────────────────────────────────────

if ( ! function_exists( 'cb_core_get_requirement_errors' ) ) :
/**
 * Return bootstrap-safe requirement descriptors, or an empty array when satisfied.
 *
 * @return array<int,array{type:string,value?:string}>
 */
function cb_core_get_requirement_errors(): array {
	$errors = [];

	// Keep bootstrap diagnostics translation-free. WordPress 6.7+ forbids
	// resolving custom text domains before `init`; requirement checks run while
	// the plugin file itself is being included. Human-readable translations are
	// resolved later inside the admin notice callback.
	if ( version_compare( PHP_VERSION, CB_CORE_MIN_PHP, '<' ) ) {
		$errors[] = [ 'type' => 'php_version', 'value' => PHP_VERSION ];
	}

	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		$errors[] = [ 'type' => 'sodium_missing' ];
	}

	return $errors;
}
endif;

$cb_core_errors = cb_core_get_requirement_errors();

if ( ! empty( $cb_core_errors ) ) {
	// Core::instance() is intentionally not reached on an unsupported host, so
	// register the text-domain loader locally for this reduced bootstrap path.
	add_action( 'init', static function (): void {
		load_plugin_textdomain(
			'core-blueprint',
			false,
			dirname( plugin_basename( CB_CORE_FILE ) ) . '/languages'
		);
	}, 0 );

	add_action( 'admin_notices', static function () use ( $cb_core_errors ) {
		$messages = [];
		foreach ( $cb_core_errors as $error ) {
			$type = (string) ( $error['type'] ?? '' );
			if ( 'php_version' === $type ) {
				$messages[] = sprintf(
					/* translators: %s: current PHP version */
					__( 'Core Blueprint requires PHP 8.4 or higher. This server runs PHP %s.', 'core-blueprint' ),
					(string) ( $error['value'] ?? PHP_VERSION )
				);
			} elseif ( 'sodium_missing' === $type ) {
				$messages[] = __( 'Core Blueprint requires the PHP Sodium (libsodium) extension, which is not available on this server.', 'core-blueprint' );
			}
		}

		if ( empty( $messages ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Core Blueprint:</strong></p><ul><li>';
		echo implode( '</li><li>', array_map( 'esc_html', $messages ) );
		echo '</li></ul></div>';
	} );

	add_action( 'admin_init', static function () {
		deactivate_plugins( plugin_basename( CB_CORE_FILE ) );
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	} );

	return;
}

unset( $cb_core_errors );

// ─── PSR-4 autoloader for CB\Core\ ────────────────────────────────────────────
// Registered before any Core Blueprint code runs so class references resolve
// lazily. Core Blueprint extensions register their own autoloaders and consume
// Base through the public Foundation/services boundary.
//
// Namespace prefix 'CB\Core\' maps to src/ directory with one file per class,
// e.g.:
//   CB\Core\Core                         -> src/Core.php
//   CB\Core\Log\AuditLog                 -> src/Log/AuditLog.php
//   CB\Core\Admin\Pages\Dashboard        -> src/Admin/Pages/Dashboard.php

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Core\\';
	$len    = strlen( $prefix );

	if ( strncmp( $class, $prefix, $len ) !== 0 ) {
		return;
	}

	$relative = substr( $class, $len );
	$file     = CB_CORE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// ─── Procedural helpers ───────────────────────────────────────────────────────
// Non-class helpers that aren't subject to autoloading.

require_once CB_CORE_DIR . 'includes/cb-about-page.php';

// ─── Failsafe - loaded FIRST, before any other subsystem initialises ─────────
// Failsafe is critical: if any module crashes during bootstrap, the bypass
// mechanism must still be operational. Touching the class here triggers the
// autoloader; calling init() hooks the fail-open handlers.

\CB\Core\Security\Failsafe::init();

// ─── Core bootstrap (Foundation layer) ───────────────────────────────────────

register_activation_hook(   __FILE__, [ \CB\Core\Core::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \CB\Core\Core::class, 'deactivate' ] );

// The resolver is the sole WordPress hook owner for Core Admin screen assets.
// Screen-specific and open extension-tab requirements resolve through the
// canonical private asset/module catalogs.
if ( \CB\Core\RequestContext::is_admin_screen() ) {
	\CB\Core\Admin\AdminAssetResolver::init();
}

\CB\Core\Core::instance();

// AI Governance is a Base foundation concern rather than an extension module.
// Boot after the canonical Core hook owner so it can register its schema before
// the priority-5 reconciliation sweep and contribute its Base-owned admin page.
\CB\Core\AIGovernance\Bootstrap::boot();

// ─── WP-CLI commands ─────────────────────────────────────────────────────────

// Defer command registration until all active plugins have loaded so optional
// Core Blueprint extensions can contribute commands through the public CLI
// registry filters before WP-CLI resolves the final `cb` command tree. Web
// requests do not install CLI-only wiring.
if ( \CB\Core\RequestContext::is_cli() ) {
	add_action( 'plugins_loaded', [ \CB\Core\CLI\Bootstrap::class, 'register_cli' ], 30 );
}
