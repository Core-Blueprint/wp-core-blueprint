<?php
declare(strict_types=1);
/**
 * Permissions\HidePage - `wp cb permissions hide-page` (state).
 *
 * Hides the Permissions tab from administrators (operators only see it).
 * Refuses to enable hide-mode when zero operators exist on the site -
 * that would lock everyone out of permissions configuration.
 *
 * State-change rather than destructive because it's reversible (run
 * show-page to undo) and the lockout-guard prevents the dangerous case.
 * Banner-warning, no confirm-modal.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Permissions;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class HidePage implements CommandInterface {

	public function execute( array $args ): Result {
		if ( 0 === Roles::operator_count() ) {
			return Result::error(
				__( 'Refusing to hide the Permissions tab while zero operators exist - no one would be able to change these settings. Add at least one operator first.', 'core-blueprint' ),
				[ 'Cannot hide - zero operators on this site.', 'Add at least one via `cb operator add` first.' ],
				[ 'operator_count' => 0, 'changed' => false ]
			);
		}

		$settings    = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];

		if ( ! empty( $permissions['hide_from_admins'] ) ) {
			return Result::warning(
				__( 'Permissions tab is already hidden from administrators.', 'core-blueprint' ),
				[ 'No change - Permissions tab was already hidden.' ],
				[ 'hide_from_admins' => true, 'changed' => false ]
			);
		}

		$origin = self::execution_origin();
		$permissions['hide_from_admins'] = true;
		Settings::set_key( 'permissions', $permissions, $origin );

		AuditLog::log( 'permissions.hide_changed', 'notice', [
			'enabled' => true,
			'by'      => $origin,
		] );

		return Result::success(
			__( 'Permissions tab is now hidden from administrators (operators only).', 'core-blueprint' ),
			[ 'Permissions tab is now hidden from administrators.', 'Only users with the cb_operator role can see and edit permissions.' ],
			[ 'hide_from_admins' => true, 'changed' => true ]
		);
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'state';
	}

	/**
	 * Hide the Permissions tab from administrators (operators only).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb permissions hide-page
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $args );

		if ( 'error' === $result->status ) {
			\WP_CLI::error( $result->message );
		}
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
