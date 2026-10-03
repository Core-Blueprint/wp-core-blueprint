<?php
declare(strict_types=1);
/**
 * Content Models module master-switch state.
 *
 * Disabling the module stops custom post type and taxonomy registration while
 * preserving every saved model definition and all WordPress content rows.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels;

use CoreBlueprint\Core\Log\AuditLog;

use CoreBlueprint\Core\Modules\ModuleStateInterface;

defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {
	private const OPTION = 'cb_core_content_models_enabled';

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$before = self::is_enabled();
		if ( $before === $enabled ) {
			return;
		}

		update_option( self::OPTION, $enabled ? '1' : '0', false );
		Rewrite::mark_dirty();
		if ( self::is_enabled() !== $enabled ) {
			throw new \RuntimeException( __( 'The Content Models module state could not be saved.', 'core-blueprint' ) );
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log(
				$enabled ? 'content_models_subsystem_enabled' : 'content_models_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}
	}
}
