<?php
declare(strict_types=1);
/**
 * CLI Bootstrap
 *
 * Wires the `wp cb` command surface into WP-CLI. Called from the plugin
 * bootstrap when WP-CLI is active.
 *
 * Two registrations happen here:
 *
 *   1. The bare `wp cb` itself - a namespace marker so `wp help cb` lists
 *      the subcommands cleanly. The class is empty; WP-CLI only needs a
 *      target to attach the namespace to.
 *
 *   2. Each `wp cb <name>` subcommand from {@see Registry::commands()} -
 *      built-in CB Base commands plus any registered through the public
 *      `cb_core_cli_register_commands` filter.
 *
 * Sibling plugins (Hub, Invoice, etc.) extend the command surface by
 * hooking the filter rather than calling WP_CLI::add_command directly -
 * that keeps registration ordered and visible to introspection tools, and
 * gives Phase 2 (in-browser terminal emulator) a single list to consume.
 *
 * Also registers an item in the HUD's cb-core section linking to the
 * Preferences › CLI documentation tab. The HUD-item registration lives
 * here (not in a separate Bootstrap) because there is no other CLI
 * subsystem boot work - keeping it in one file matches the per-subsystem
 * pattern Notes/Reports use.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI;

use CoreBlueprint\Core\Admin\Admin;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Register the WP-CLI commands. Must run during plugin bootstrap so the
	 * dispatcher sees the commands before WP-CLI's own command-resolution
	 * pass. No-op when WP-CLI isn't loaded.
	 */
	public static function register_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		// The bare `wp cb` namespace - empty class so help output reads
		// cleanly. Without this, `wp help cb` shows the leaf commands but
		// without a top-level summary line.
		\WP_CLI::add_command( 'cb', RootCommand::class );

		$commands = Registry::commands();
		$names    = array_column( $commands, 'name' );
		$seen     = [];

		foreach ( $commands as $command ) {
			$name  = $command['name'];
			$class = $command['class'];

			if ( isset( $seen[ $name ] ) ) {
				\WP_CLI::warning( sprintf( 'Skipping duplicate Core Blueprint CLI command: cb %s', $name ) );
				continue;
			}
			$seen[ $name ] = true;

			if ( self::is_executable_namespace( $name, $names ) ) {
				\WP_CLI::warning( sprintf( 'Skipping Core Blueprint CLI command "cb %s" because it is also a namespace for child commands.', $name ) );
				continue;
			}

			if ( ! class_exists( $class ) ) {
				continue;
			}

			\WP_CLI::add_command( 'cb ' . $name, $class );
		}
	}

	/**
	 * Whether an executable command path is also being used as a namespace.
	 *
	 * WP-CLI cannot attach child commands below an already executable command.
	 * Namespace paths therefore win and the conflicting parent leaf is skipped.
	 *
	 * @param string        $name  Normalised command path without the `cb` root.
	 * @param array<int,string> $names All registered command paths.
	 */
	private static function is_executable_namespace( string $name, array $names ): bool {
		$prefix = $name . ' ';

		foreach ( $names as $candidate ) {
			if ( $candidate !== $name && str_starts_with( $candidate, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register the HUD-item that links to the CLI documentation tab.
	 * Hooked from {@see \CoreBlueprint\Core\Core::init()} on every request (admin and
	 * frontend) so the HUD picker shows the entry to operators.
	 */
	public static function boot(): void {
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_item' ] );
	}

	/**
	 * Register the CLI documentation HUD item. Capability-gated on
	 * cb_use_cli - only operators see it. Deep-links to the Preferences ›
	 * CLI tab so an operator one click away from the command reference.
	 *
	 * @param string $registry The HUD Registry class name (passed by the action).
	 */
	public static function register_hud_item( string $registry ): void {
		if ( ! current_user_can( 'cb_use_cli' ) ) {
			return;
		}
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::add_item( [
			'id'         => 'cb-hud-cli',
			'label'      => __( 'CLI', 'core-blueprint' ),
			'section'    => 'cb-core',
			'url'        => admin_url( 'admin.php?page=' . Admin::PREFERENCES_SLUG . '&tab=cli' ),
			'order'      => 60,
			'capability' => 'cb_use_cli',
			'icon'       => 'editor-code',
		] );
	}
}
