<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Core Blueprint - Audit log viewer template.
 *
 * Delegates to the shared log-events-page partial. Sets the variables that
 * differ from the System Log (page title, description copy, tab slug,
 * placeholder, export button class) and lets the partial handle the rest.
 *
 * Variables set by caller ({@see \CoreBlueprint\Core\Admin\Pages\Logs\Tabs\AuditTab}):
 *   $result          - \CoreBlueprint\Core\Log\AuditLog::query() output (rows/total/page/per_page)
 *   $args            - original filter arguments
 *   $current_period  - resolved time-filter preset
 *   $chart_daily     - daily counts for the activity chart
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$page_title          = __( 'Audit Log', 'core-blueprint' );
/* translators: %s: formatted number of total events */
$page_description    = __( 'Total events: %s', 'core-blueprint' );
$tab_slug            = 'audit';
$event_placeholder   = 'e.g. login.failed';
$export_button_class = 'cb-core-export-audit';
$log_type            = 'audit';

include CB_CORE_DIR . 'templates/partials/log-events-page.php';
