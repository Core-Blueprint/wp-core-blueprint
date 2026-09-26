<?php
declare(strict_types=1);
/**
 * CLI Registry
 *
 * Filter-driven registration helper for `wp cb` subcommands. Lives parallel
 * to {@see \CB\Core\HUD\Registry} but for the command line: built-in CB Base
 * commands register here on bootstrap, and sibling plugins (Hub, Invoice,
 * etc.) hook the `cb_core_cli_register_commands` filter to add their own.
 *
 * Each registered command is a child of the top-level `cb` namespace -
 * registration takes a sub-name plus a class-name string (or [class, method]
 * shape) and the registry walks the resulting list at boot time, calling
 * `WP_CLI::add_command( "cb {$sub_name}", ... )` for each one.
 *
 * Phase 1 (1.5.0-dev) ships only Foundation commands. Phase 2 will surface
 * the same Commands classes inside an in-browser terminal emulator without
 * changing this contract - the registry stays the single source of truth.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CB\Core\CLI;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/**
	 * Apply the public registration filter and return the resulting list.
	 *
	 * Each entry is a normalised array:
	 *
	 *   [
	 *       'name'        => 'scan',
	 *       'class'       => 'CB\\Core\\CLI\\Commands\\Scan',
	 *       'description' => 'Run and inspect file integrity scans.',
	 *   ]
	 *
	 * Sibling plugins hook the filter and append their own entries:
	 *
	 *     add_filter( 'cb_core_cli_register_commands', function ( array $commands ): array {
	 *         $commands[] = [
	 *             'name'  => 'hub',
	 *             'class' => '\\CB\\Hub\\CLI\\HubCommand',
	 *         ];
	 *         return $commands;
	 *     } );
	 *
	 * Malformed entries (missing name or class) are silently dropped - a
	 * misconfigured sibling shouldn't stop other commands from registering.
	 *
	 * @return array<int, array{name: string, class: string, description: string}>
	 */
	public static function commands(): array {
		$commands = self::builtin_commands();

		/**
		 * Filter: cb_core_cli_register_commands
		 *
		 * Lets sibling plugins register their own `wp cb <name>` subcommands.
		 *
		 * @param array<int, array{name: string, class: string, description?: string}> $commands
		 */
		$commands = (array) apply_filters( 'cb_core_cli_register_commands', $commands );

		$normalised = [];
		foreach ( $commands as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name  = isset( $entry['name'] ) ? self::normalise_command_name( (string) $entry['name'] ) : '';
			$class = isset( $entry['class'] ) ? (string) $entry['class'] : '';
			if ( '' === $name || '' === $class ) {
				continue;
			}
			$normalised[] = [
				'name'        => $name,
				'class'       => ltrim( $class, '\\' ),
				'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
			];
		}

		return $normalised;
	}

	/**
	 * Normalise a possibly multi-word WP-CLI subcommand without collapsing
	 * its namespace separators. Each command segment is sanitised independently
	 * so names such as `failsafe disable` remain two words.
	 */
	private static function normalise_command_name( string $name ): string {
		$segments = preg_split( '/\s+/', trim( $name ) ) ?: [];
		$segments = array_map( 'sanitize_key', $segments );
		$segments = array_values( array_filter( $segments, static fn ( string $segment ): bool => '' !== $segment ) );

		return implode( ' ', $segments );
	}

	/**
	 * The CB Base built-in command surface. After the 1.6.0-dev refactor,
	 * subcommands like `scan run`, `scan latest`, etc. are
	 * each their own atomic class. WP-CLI accepts dotted-name registrations
	 * (`cb scan run`) directly, so this list maps the full path → class.
	 *
	 * @return array<int, array{name: string, class: string, description: string}>
	 */
	private static function builtin_commands(): array {
		return [
			// Top-level read-only commands
			[
				'name'        => 'status',
				'class'       => Commands\Status::class,
				'description' => 'Operator-friendly snapshot of this Core Blueprint install.',
			],
			[
				'name'        => 'version',
				'class'       => Commands\Version::class,
				'description' => 'Print the Core Blueprint version and component build numbers.',
			],

			// scan namespace - split into atomic classes
			[
				'name'        => 'scan run',
				'class'       => Commands\Scan\Run::class,
				'description' => 'Trigger a Core Scanner integrity scan.',
			],
			[
				'name'        => 'scan latest',
				'class'       => Commands\Scan\Latest::class,
				'description' => 'Print the most recent scan result.',
			],

			// logs namespace - Tail (default) + Prune
			[
				'name'        => 'logs tail',
				'class'       => Commands\Logs\Tail::class,
				'description' => 'Tail recent audit log entries.',
			],
			[
				'name'        => 'logs prune',
				'class'       => Commands\Logs\Prune::class,
				'description' => 'Run the audit log retention prune immediately.',
			],

			// failsafe namespace - Status atomic, write-actions split into atomic classes
			[
				'name'        => 'failsafe status',
				'class'       => Commands\Failsafe\Status::class,
				'description' => 'Failsafe state snapshot.',
			],
			[
				'name'        => 'failsafe disable',
				'class'       => Commands\Failsafe\Disable::class,
				'description' => 'Activate the emergency bypass.',
			],
			[
				'name'        => 'failsafe enable',
				'class'       => Commands\Failsafe\Enable::class,
				'description' => 'Deactivate the emergency bypass and resume enforcement.',
			],
			[
				'name'        => 'failsafe test',
				'class'       => Commands\Failsafe\Test::class,
				'description' => 'Run the failsafe self-test suite.',
			],
			[
				'name'        => 'failsafe rotate-token',
				'class'       => Commands\Failsafe\RotateToken::class,
				'description' => 'Rotate the secret bypass URL token.',
			],
			[
				'name'        => 'failsafe close-window',
				'class'       => Commands\Failsafe\CloseWindow::class,
				'description' => 'Close any active 60-minute bypass window.',
			],

			// Operator + Permissions + Diag - atomic across the board after 1.6.2-dev
			[
				'name'        => 'operator list',
				'class'       => Commands\Operator\ListOperators::class,
				'description' => 'List all current Core Blueprint operators.',
			],
			[
				'name'        => 'operator status',
				'class'       => Commands\Operator\Status::class,
				'description' => 'Inspect one user’s Operator role and signed trust state.',
			],
			[
				'name'        => 'operator add',
				'class'       => Commands\Operator\Add::class,
				'description' => 'Promote a user to the cb_operator role.',
			],
			[
				'name'        => 'operator remove',
				'class'       => Commands\Operator\Remove::class,
				'description' => 'Demote a user from the cb_operator role.',
			],
			[
				'name'        => 'operator recover',
				'class'       => Commands\Operator\Recover::class,
				'description' => 'Trusted server-side recovery for an Operator approval state.',
			],
			[
				'name'        => 'two-factor status',
				'class'       => Commands\TwoFactor\Status::class,
				'description' => 'Inspect Base-owned two-factor state for one user.',
			],
			[
				'name'        => 'two-factor reset',
				'class'       => Commands\TwoFactor\Reset::class,
				'description' => 'Trusted server-side reset of Base-owned two-factor state.',
			],
			[
				'name'        => 'permissions status',
				'class'       => Commands\Permissions\Status::class,
				'description' => 'Print permissions configuration.',
			],
			[
				'name'        => 'permissions show-page',
				'class'       => Commands\Permissions\ShowPage::class,
				'description' => 'Make the Permissions tab visible to administrators.',
			],
			[
				'name'        => 'permissions hide-page',
				'class'       => Commands\Permissions\HidePage::class,
				'description' => 'Hide the Permissions tab from administrators.',
			],
			[
				'name'        => 'permissions repair-role-policy',
				'class'       => Commands\Permissions\RepairRolePolicy::class,
				'description' => 'Repair canonical Base-owned role definitions and capabilities.',
			],
			[
				'name'        => 'reports generate',
				'class'       => Commands\Reports\Generate::class,
				'description' => 'Generate a maintenance report.',
			],
			[
				'name'        => 'diag i18n',
				'class'       => Commands\Diag\I18n::class,
				'description' => 'Translation-loading diagnostic.',
			],
		];
	}
}
