<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Core Blueprint - System log viewer template.
 *
 * Shows local maintenance activity - plugin/theme/core lifecycle, user
 * management. Reads the same audit_log table as the Audit log tab, but
 * filters to the `system.*` prefix. Events are rendered in plain language
 * so non-technical clients can follow what was changed.
 *
 * Delegates to the shared log-events-page partial; only the parameters
 * unique to this tab are set here.
 *
 * Variables set by caller ({@see \CoreBlueprint\Core\Admin\Pages\Logs\Tabs\SystemTab}):
 *   $sys_result       - \CoreBlueprint\Core\Log\AuditLog::query() output
 *   $sys_args         - original filter arguments
 *   $current_period   - resolved time-filter preset
 *   $sys_chart_daily  - daily counts for the activity chart
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

// Normalize to the names the shared partial expects.
$result      = $sys_result;
$args        = $sys_args;
$chart_daily = $sys_chart_daily ?? [];

$page_title          = __( 'System Log', 'core-blueprint' );
/* translators: %s: formatted number of total events */
$page_description    = __( 'Total recorded: %s', 'core-blueprint' );
$tab_slug            = 'system';
$event_placeholder   = 'e.g. plugin_updated';
$export_button_class = 'cb-core-export-system-log';
$log_type            = 'system';

include CB_CORE_DIR . 'templates/partials/log-events-page.php';
