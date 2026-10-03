<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Preferences › CLI tab
 *
 * Pure documentation page - lists every `wp cb` subcommand with a copy-
 * ready example, the required capability, and a short description. Plus
 * a setup section explaining how to activate WP-CLI on a host.
 *
 * No form state, no POST handling, no server-side persistence. The only
 * dynamic behaviour enhances the command buttons through the shared
 * Clipboard Foundation; the admin asset layer enqueues that runtime only
 * while this tab is active.
 *
 * Cap-gated upstream by `Preferences::render()` - this template only
 * renders when the viewer holds `cb_use_cli`.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Command catalog. One entry per public `wp cb …` subcommand. Kept inline
 * (not pulled from {@see \CoreBlueprint\Core\CLI\Registry::commands()}) because
 * documentation has different needs than runtime registration:
 *
 *   - human-readable copy with examples and capability hints
 *   - intentional grouping by use case rather than alphabetical
 *   - separate "what does it do" text for plain readers
 *
 * Schema:
 *   group        section heading the command appears under
 *   cmd          the command users type (excluding `wp `)
 *   example      a copy-paste example, may include flags
 *   description  one-line plain-English summary
 *   capability   the WP capability that grants access (display only -
 *                actual gating happens in the command class via WP-CLI's
 *                @when handlers and inline current_user_can checks)
 */
$cb_cli_commands = [
	// ─── Daily-driver operator commands ─────────────────────────────────
	[
		'group'       => __( 'Daily operator commands', 'core-blueprint' ),
		'cmd'         => 'cb status',
		'example'     => 'wp cb status',
		'description' => __( 'Operator-friendly snapshot: version, modules, last scan, and pending updates.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb version',
		'example'     => 'wp cb version',
		'description' => __( 'Print Core Blueprint version, schema version, and runtime baselines.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb scan run',
		'example'     => 'wp cb scan run --user=chris --wait',
		'description' => __( 'Trigger a Core Scanner integrity scan. With --wait, blocks until completion.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb scan latest',
		'example'     => 'wp cb scan latest',
		'description' => __( 'Print the most recent scan result. Pass --format=json for the full payload.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb logs tail',
		'example'     => 'wp cb logs tail --limit=50 --since="yesterday"',
		'description' => __( 'Tail recent audit log entries with optional time, severity, and event-prefix filters.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],

	// ─── Maintenance + housekeeping ─────────────────────────────────────
	[
		'group'       => __( 'Maintenance', 'core-blueprint' ),
		'cmd'         => 'cb logs prune',
		'example'     => 'wp cb logs prune --category=security',
		'description' => __( 'Run the audit log retention prune immediately. Useful when WP-Cron is disabled.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb reports generate',
		'example'     => 'wp cb reports generate maintenance',
		'description' => __( 'Generate a maintenance report. Defaults to the previous calendar month.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb diag i18n',
		'example'     => 'wp cb diag i18n',
		'description' => __( 'Translation-loading diagnostic: locale, MO presence, textdomain state, round-trip probe.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],

	// ─── Permissions + role management ──────────────────────────────────
	[
		'group'       => __( 'Permissions', 'core-blueprint' ),
		'cmd'         => 'cb operator add',
		'example'     => 'wp cb operator add chris',
		'description' => __( 'Promote a user (ID, login, or email) to the cb_operator role.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb operator remove',
		'example'     => 'wp cb operator remove chris',
		'description' => __( 'Demote a user from the cb_operator role. Refuses to remove the last operator without --force.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb operator list',
		'example'     => 'wp cb operator list',
		'description' => __( 'List all current Core Blueprint operators on this site.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb permissions status',
		'example'     => 'wp cb permissions status',
		'description' => __( 'Print the current permissions configuration: operator count, hide-tab state, admin-toggles.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb permissions show-page',
		'example'     => 'wp cb permissions show-page',
		'description' => __( 'Make the Permissions tab visible to administrators again.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb permissions hide-page',
		'example'     => 'wp cb permissions hide-page',
		'description' => __( 'Hide the Permissions tab from administrators (operators only). Refuses when zero operators exist.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],

	// ─── Recovery / failsafe ────────────────────────────────────────────
	[
		'group'       => __( 'Failsafe (recovery)', 'core-blueprint' ),
		'cmd'         => 'cb failsafe status',
		'example'     => 'wp cb failsafe status',
		'description' => __( 'Print failsafe state: layers active, bypass status, token presence, detected security plugins.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb failsafe disable',
		'example'     => 'wp cb failsafe disable --reason="Locked out"',
		'description' => __( 'Activate the emergency bypass: every restrictive feature becomes a no-op until re-enabled.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb failsafe enable',
		'example'     => 'wp cb failsafe enable',
		'description' => __( 'Deactivate the emergency bypass and resume enforcement.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb failsafe test',
		'example'     => 'wp cb failsafe test',
		'description' => __( 'Run the failsafe self-test suite.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb failsafe rotate-token',
		'example'     => 'wp cb failsafe rotate-token',
		'description' => __( 'Rotate the secret bypass URL token. The new URL is printed once.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
	[
		'cmd'         => 'cb failsafe close-window',
		'example'     => 'wp cb failsafe close-window',
		'description' => __( 'Close any active 60-minute bypass window.', 'core-blueprint' ),
		'capability'  => 'cb_use_cli',
	],
];

// Group commands by their `group` key. The first entry of each section
// declares the heading; subsequent entries inherit until a new heading
// appears. Re-flow into a section-keyed array for rendering.
$cb_cli_sections = [];
$cb_cli_current  = '';
foreach ( $cb_cli_commands as $entry ) {
	if ( ! empty( $entry['group'] ) ) {
		$cb_cli_current = (string) $entry['group'];
	}
	if ( '' === $cb_cli_current ) {
		continue;
	}
	if ( ! isset( $cb_cli_sections[ $cb_cli_current ] ) ) {
		$cb_cli_sections[ $cb_cli_current ] = [];
	}
	$cb_cli_sections[ $cb_cli_current ][] = $entry;
}
?>
<div class="wrap cb-core-wrap cb-core-cli">

	<h1 class="cb-core-title"><?php esc_html_e( 'Command-line tool (wp cb)', 'core-blueprint' ); ?></h1>

	<p class="cb-core-cli__intro">
		<?php esc_html_e( 'Core Blueprint ships a set of WP-CLI commands under the wp cb namespace. Use them in deploy scripts, recovery situations, or whenever a terminal is faster than the admin UI. Every command is non-interactive and scriptable; pass --format=json|yaml for machine-readable output where supported.', 'core-blueprint' ); ?>
	</p>

	<?php foreach ( $cb_cli_sections as $section_label => $entries ) : ?>
		<section class="cb-core-cli__section cb-core-preferences-section">
			<h2 class="cb-core-cli__heading"><?php echo esc_html( $section_label ); ?></h2>

			<div class="cb-core-cli__table-wrap">
				<table class="widefat striped cb-core-cli__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Required capability', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><code class="cb-core-cli__name"><?php echo esc_html( 'wp ' . $entry['cmd'] ); ?></code></td>
								<td class="cb-core-cli__desc"><?php echo esc_html( $entry['description'] ); ?></td>
								<td><span class="cb-core-badge cb-core-badge-tech"><?php echo esc_html( $entry['capability'] ); ?></span></td>
								<td>
									<div class="cb-core-cli__example">
										<code><?php echo esc_html( $entry['example'] ); ?></code>
										<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-cli__copy"><?php esc_html_e( 'Copy', 'core-blueprint' ); ?></button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endforeach; ?>

	<section class="cb-core-cli__section cb-core-preferences-section">
		<h2 class="cb-core-cli__heading"><?php esc_html_e( 'Setup', 'core-blueprint' ); ?></h2>
		<p class="cb-core-cli__lede">
			<?php esc_html_e( 'WP-CLI is a separate command-line tool that runs WordPress code from the terminal. Most managed hosts ship it pre-installed; if your host does not, the official installer at https://wp-cli.org/ takes a single command.', 'core-blueprint' ); ?>
		</p>

		<p><?php esc_html_e( 'Once WP-CLI is on the server, navigate to your WordPress install and run any wp cb command. WP-CLI auto-discovers the WordPress install in the current directory; no extra configuration is needed.', 'core-blueprint' ); ?></p>
		<p><?php esc_html_e( 'For SSH-based hosts, log in over SSH first, then cd into the WordPress directory before running the commands. For Docker-based local development, prefix commands with the appropriate exec invocation for your container.', 'core-blueprint' ); ?></p>

		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
			'title'   => __( 'Cloud86 users:', 'core-blueprint' ),
			'message' => __( 'Cloud86 ships WP-CLI pre-installed on every shared and Managed WordPress plan. SSH access is included by default; activate it from the control panel under "Hosting → SSH" and use the wp command from the home directory of your account. The shared executable already understands which install you are in based on the working directory.', 'core-blueprint' ),
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</section>
</div>
