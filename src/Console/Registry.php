<?php
declare(strict_types=1);
/**
 * Console Registry - flat command index for the Console runner.
 *
 * Where {@see \CoreBlueprint\Core\CLI\Registry::commands()} groups commands by
 * top-level namespace ("scan", "beacon", "logs"…) for WP-CLI to walk
 * with its own subcommand-resolution, the Console needs a flat list of
 * runnable atomic commands keyed by their full name ("scan latest",
 * "beacon status", "logs prune").
 *
 * Each entry is the contract the Console UI consumes:
 *
 *   id              Stable kebab-case identifier; URL-safe.
 *   name            Full command path as the user types it (`cb scan latest`)
 *                   minus the `cb` prefix.
 *   class           Fully-qualified Commands class implementing execute()
 *                   + args_schema() + side_effects().
 *   description     Single-line summary - same text the Preferences › CLI
 *                   tab uses, kept inline here so the picker can show it
 *                   without duplicating the catalog.
 *   group           Picker section heading.
 *   capability      Required capability for clicking Run. Defaults to
 *                   'cb_use_cli'; future commands may require more.
 * *
 * Sibling plugins extend the runner via the `core_blueprint_console_register_commands`
 * filter - same pattern as `core_blueprint_cli_register_commands` but flat.
 *
 * @package Core_Blueprint
 */


namespace CoreBlueprint\Core\Console;

use CoreBlueprint\Core\CLI\Commands as CLICommands;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/**
	 * Build the full command list. Filter `core_blueprint_console_register_commands`
	 * lets siblings append; built-in entries cannot be removed.
	 *
	 * @return array<int, array{
	 *   id: string,
	 *   name: string,
	 *   class: string,
	 *   description: string,
	 *   group: string,
	 *   capability: string,
	 * }>
	 */
	public static function commands(): array {
		$commands = self::builtin();

		/**
		 * Filter: core_blueprint_console_register_commands
		 *
		 * Lets sibling plugins register their own atomic commands in the
		 * Console runner. Each entry must declare an `id`, `name`,
		 * `class` (implementing the Console command contract), and
		 * `description`; `group` and `capability` are optional.
		 */
		$commands = (array) apply_filters( 'core_blueprint_console_register_commands', $commands );

		// Normalise - drop malformed entries silently, default missing
		// optional fields.
		$out = [];
		$seen = [];
		foreach ( $commands as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id    = isset( $entry['id'] )    ? sanitize_key( (string) $entry['id'] )    : '';
			$name  = isset( $entry['name'] )  ? (string) $entry['name']  : '';
			$class = isset( $entry['class'] ) ? ltrim( (string) $entry['class'], '\\' ) : '';
			if ( '' === $id || '' === $name || '' === $class || isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( ! class_exists( $class ) || ! is_subclass_of( $class, CommandInterface::class ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[] = [
				'id'          => $id,
				'name'        => $name,
				'class'       => $class,
				'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
				'group'       => isset( $entry['group'] )       ? (string) $entry['group']       : 'other',
				'capability'  => isset( $entry['capability'] )  ? (string) $entry['capability']  : 'cb_use_cli',
			];
		}
		return $out;
	}

	/**
	 * Look up a single command by its id. Returns null when not found -
	 * the REST controller returns 404 in that case.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		$id = sanitize_key( $id );
		foreach ( self::commands() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Built-in CB Base commands.
	 *
	 * Read-only commands have a concrete `class` referencing an atomic
	 * Commands subclass with execute() + args_schema() + side_effects().
	 *
	 * Write-action commands list with `class` referencing their CLI
	 * class but the Console UI disables their Run button on the basis
	 * of side_effects() returning anything other than 'none'.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function builtin(): array {
		return [
			// ─── Read-only ──────────────────────────────────────────
			[
				'id'          => 'cb-status',
				'name'        => 'cb status',
				'class'       => CLICommands\Status::class,
				'description' => 'Operator-friendly snapshot of this Core Blueprint install.',
				'group'       => 'observe',
			],
			[
				'id'          => 'cb-version',
				'name'        => 'cb version',
				'class'       => CLICommands\Version::class,
				'description' => 'Core Blueprint version, schema version, and runtime baselines.',
				'group'       => 'observe',
			],
			[
				'id'          => 'cb-scan-latest',
				'name'        => 'cb scan latest',
				'class'       => CLICommands\Scan\Latest::class,
				'description' => 'Print the most recent Core Scanner result.',
				'group'       => 'observe',
			],
			[
				'id'          => 'cb-logs',
				'name'        => 'cb logs',
				'class'       => CLICommands\Logs\Tail::class,
				'description' => 'Tail recent audit log entries with filters.',
				'group'       => 'observe',
			],
			[
				'id'          => 'cb-permissions-status',
				'name'        => 'cb permissions status',
				'class'       => CLICommands\Permissions\Status::class,
				'description' => 'Print current permissions configuration.',
				'group'       => 'observe',
			],
			[
				'id'          => 'cb-operator-list',
				'name'        => 'cb operator list',
				'class'       => CLICommands\Operator\ListOperators::class,
				'description' => 'List all Core Blueprint operators on this site.',
				'group'       => 'observe',
			],
			[
				'id'          => 'cb-operator-status',
				'name'        => 'cb operator status',
				'class'       => CLICommands\Operator\Status::class,
				'description' => 'Inspect one user’s Operator role and signed trust state.',
				'group'       => 'observe',
				'capability'  => 'cb_manage_permissions',
			],
			[
				'id'          => 'cb-failsafe-status',
				'name'        => 'cb failsafe status',
				'class'       => CLICommands\Failsafe\Status::class,
				'description' => 'Failsafe state: layers active, bypass status, token presence.',
				'group'       => 'observe',
			],
			[
				'id'          => 'cb-diag-i18n',
				'name'        => 'cb diag i18n',
				'class'       => CLICommands\Diag\I18n::class,
				'description' => 'Translation-loading diagnostic: locale, MO presence, textdomain state.',
				'group'       => 'observe',
			],

			// ─── State-change + destructive ─────────────────────────
			//
			// Runnable from the Console: 'state' commands run directly,
			// 'destructive' commands run behind a confirm-token modal
			// (see Console\Rest\RunController). The same classes also
			// back the `wp cb …` CLI entrypoints via CLI\Registry.
			[
				'id'          => 'cb-scan-run',
				'name'        => 'cb scan run',
				'class'       => CLICommands\Scan\Run::class,
				'description' => 'Trigger a Core Scanner integrity scan.',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-logs-prune',
				'name'        => 'cb logs prune',
				'class'       => CLICommands\Logs\Prune::class,
				'description' => 'Run the audit log retention prune immediately.',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-reports-generate',
				'name'        => 'cb reports generate',
				'class'       => CLICommands\Reports\Generate::class,
				'description' => 'Generate a maintenance report.',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-permissions-show',
				'name'        => 'cb permissions show-page',
				'class'       => CLICommands\Permissions\ShowPage::class,
				'description' => 'Make the Permissions tab visible to administrators.',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-permissions-hide',
				'name'        => 'cb permissions hide-page',
				'class'       => CLICommands\Permissions\HidePage::class,
				'description' => 'Hide the Permissions tab from administrators (operators only).',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-permissions-repair-role-policy',
				'name'        => 'cb permissions repair-role-policy',
				'class'       => CLICommands\Permissions\RepairRolePolicy::class,
				'description' => 'Repair canonical Base-owned role definitions and capabilities.',
				'group'       => 'destructive',
				'capability'  => 'cb_manage_roles',
			],
			[
				'id'          => 'cb-operator-add',
				'name'        => 'cb operator add',
				'class'       => CLICommands\Operator\Add::class,
				'description' => 'Promote a user to the cb_operator role.',
				'group'       => 'mutate',
			],

			// ─── Destructive - confirm-modal protected ──────────────
			[
				'id'          => 'cb-failsafe-disable',
				'name'        => 'cb failsafe disable',
				'class'       => CLICommands\Failsafe\Disable::class,
				'description' => 'Activate the emergency bypass.',
				'group'       => 'destructive',
			],
			[
				'id'          => 'cb-failsafe-enable',
				'name'        => 'cb failsafe enable',
				'class'       => CLICommands\Failsafe\Enable::class,
				'description' => 'Deactivate the emergency bypass and resume enforcement.',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-failsafe-rotate-token',
				'name'        => 'cb failsafe rotate-token',
				'class'       => CLICommands\Failsafe\RotateToken::class,
				'description' => 'Rotate the secret bypass URL token.',
				'group'       => 'destructive',
			],
			[
				'id'          => 'cb-failsafe-test',
				'name'        => 'cb failsafe test',
				'class'       => CLICommands\Failsafe\Test::class,
				'description' => 'Run the failsafe self-test suite.',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-failsafe-close-window',
				'name'        => 'cb failsafe close-window',
				'class'       => CLICommands\Failsafe\CloseWindow::class,
				'description' => 'Close any active 60-minute bypass window.',
				'group'       => 'mutate',
			],
			[
				'id'          => 'cb-operator-remove',
				'name'        => 'cb operator remove',
				'class'       => CLICommands\Operator\Remove::class,
				'description' => 'Demote a user from the cb_operator role.',
				'group'       => 'destructive',
			],
		];
	}
}
