<?php
declare(strict_types=1);
/** Logs\Prune - run canonical AuditLog retention policy immediately. */
namespace CoreBlueprint\Core\CLI\Commands\Logs;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Governance\RetentionPolicy;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Log\Retention;

defined( 'ABSPATH' ) || exit;

final class Prune implements CommandInterface {
	public function execute( array $args ): Result {
		$category = isset( $args['category'] ) && '' !== (string) $args['category'] ? sanitize_key( (string) $args['category'] ) : null;
		if ( null !== $category && ! RetentionPolicy::is_category( $category ) ) {
			return Result::error( __( 'Unknown retention category.', 'core-blueprint' ) );
		}
		$result = Retention::prune_audit( $category );
		$lines = [];
		foreach ( $result['breakdown'] as $name => $deleted ) {
			$lines[] = sprintf( '%s: %d', $name, $deleted );
		}
		return Result::success(
			/* translators: %d: number of pruned audit entries. */
			sprintf( _n( 'Pruned %d audit entry.', 'Pruned %d audit entries.', $result['total'], 'core-blueprint' ), $result['total'] ),
			$lines,
			$result
		);
	}

	public function args_schema(): array {
		return [
			'category' => [
				'type' => 'string',
				'label' => __( 'Retention category', 'core-blueprint' ),
				'help' => __( 'Optional canonical category: security, maintenance, logins, settings, or general.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string { return 'state'; }

	/**
	 * ## OPTIONS
	 * [--category=<category>]
	 * : Prune only one canonical category. Without it, apply the configured policy to all five categories.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$category = isset( $assoc_args['category'] ) ? sanitize_key( (string) $assoc_args['category'] ) : null;
		if ( null !== $category && ! RetentionPolicy::is_category( $category ) ) {
			\WP_CLI::error( 'Unknown retention category. Use security, maintenance, logins, settings, or general.' );
		}
		$result = Retention::prune_audit( $category );

		AuditLog::log( 'logs.pruned', 'notice', [
			'category'  => $category ?? 'all',
			'total'     => (int) $result['total'],
			'breakdown' => $result['breakdown'],
			'via'       => 'cli',
		] );

		foreach ( $result['breakdown'] as $name => $deleted ) {
			\WP_CLI::log( sprintf( '%s: %d', $name, $deleted ) );
		}
		\WP_CLI::success( sprintf( 'Pruned %d audit log entries using the configured retention policy.', $result['total'] ) );
	}
}
