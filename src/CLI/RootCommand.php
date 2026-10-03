<?php
declare(strict_types=1);
/**
 * RootCommand
 *
 * Empty target class for the `wp cb` namespace. WP-CLI requires a class to
 * attach a top-level command to even when that command has no methods of
 * its own - this is that target. All real work lives in subcommand
 * classes registered as `wp cb <name>`.
 *
 * Manage Core Blueprint sites from the command line.
 *
 * Quick reference:
 *
 *     wp cb status                      Operator-friendly snapshot
 *     wp cb scan run                    Trigger a Core Scanner integrity scan
 *     wp cb logs tail                   Tail recent audit log entries
 *     wp cb help                        List every subcommand
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI;

defined( 'ABSPATH' ) || exit;

final class RootCommand {
}
