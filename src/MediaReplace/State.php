<?php
declare(strict_types=1);
/**
 * Media Replace module master-switch state.
 *
 * Missing state is intentionally disabled for the public v1 contract.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\MediaReplace;

use CoreBlueprint\Core\Log\AuditLog;

use CoreBlueprint\Core\Modules\ModuleStateInterface;
defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {
	private const OPTION = 'cb_core_media_replace_enabled';

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
			throw new \RuntimeException( __( 'Media Replace state could not be persisted.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'media_replace_subsystem_enabled' : 'media_replace_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}
	}
}
