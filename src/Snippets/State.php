<?php
declare(strict_types=1);
/**
 * Snippets module master-switch state.
 *
 * The switch is deliberately fail-closed: a requested state transition is not
 * considered complete until the generated runtime index matches it. Stored
 * snippet metadata/code remains intact while the module is disabled.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Snippets;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Modules\ModuleStateInterface;

\defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {
	public static function is_enabled(): bool {
		return ! empty( Settings::all()['enabled'] );
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$before = self::is_enabled();
		if ( $before === $enabled ) {
			return;
		}

		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'File modifications are disabled by this WordPress installation. Snippets cannot change runtime state.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		Settings::save( [ 'enabled' => $enabled ] );
		if ( self::is_enabled() !== $enabled ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'The Snippets module state could not be saved.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( ! Repository::rebuild_index() ) {
			Settings::save( [ 'enabled' => $before ] );
			Repository::rebuild_index();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'The snippet runtime index could not be rebuilt. The previous module state was restored.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'snippets_subsystem_enabled' : 'snippets_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}
	}
}
