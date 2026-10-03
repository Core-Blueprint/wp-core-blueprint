<?php
declare(strict_types=1);
/**
 * User Roles module master-switch state.
 *
 * Missing state is intentionally disabled for the public v1 contract.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\Log\AuditLog;

use CoreBlueprint\Core\Modules\ModuleStateInterface;
defined( 'ABSPATH' ) || exit;

final class UserRolesState implements ModuleStateInterface {
	private const OPTION = 'cb_core_user_roles_enabled';

	public static function is_enabled(): bool {
		return '0' !== (string) get_option( self::OPTION, '0' );
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$was = self::is_enabled();
		if ( $was === $enabled ) {
			return;
		}

		update_option( self::OPTION, $enabled ? '1' : '0', false );
		if ( self::is_enabled() !== $enabled ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'User Roles state could not be persisted.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'user_roles_subsystem_enabled' : 'user_roles_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}
	}
}
