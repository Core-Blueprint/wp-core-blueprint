<?php
declare(strict_types=1);
/**
 * Permissions\ShowPage - `wp cb permissions show-page` (state).
 *
 * Reverses hide-from-admins. The Permissions tab becomes visible to
 * administrators again. State-change because it widens the audience that
 * can see (and modify) permissions; banner-warning, no confirm-modal.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Permissions;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ShowPage implements CommandInterface {

	public function execute( array $args ): Result {
		$settings    = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];

		if ( empty( $permissions['hide_from_admins'] ) ) {
			return Result::warning(
				__( 'Permissions tab is already visible to administrators.', 'core-blueprint' ),
				[ 'No change - Permissions tab was already visible.' ],
				[ 'hide_from_admins' => false, 'changed' => false ]
			);
		}

		$origin = self::execution_origin();
		$permissions['hide_from_admins'] = false;
		Settings::set_key( 'permissions', $permissions, $origin );

		AuditLog::log( 'permissions.hide_changed', 'notice', [
			'enabled' => false,
			'by'      => $origin,
		] );

		return Result::success(
			__( 'Permissions tab is now visible to administrators.', 'core-blueprint' ),
			[ 'Permissions tab is now visible to administrators.' ],
			[ 'hide_from_admins' => false, 'changed' => true ]
		);
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'state';
	}

	/**
	 * Make the Permissions tab visible to administrators.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb permissions show-page
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $args );

		if ( 'warning' === $result->status ) {
			\WP_CLI::warning( $result->message );
			return;
		}
		\WP_CLI::success( $result->message );
	}

	private static function execution_origin(): string {
		return defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'console';
	}
}
