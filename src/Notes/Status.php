<?php
declare(strict_types=1);
/**
 * Notes\Status - health provider for Notes.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Notes;

defined( 'ABSPATH' ) || exit;

final class Status {
	/** @return array{state:string,detail:string,url:string} */
	public static function contribute(): array {
		$enabled = State::is_enabled();

		return [
			'state'  => $enabled ? 'ok' : 'off',
			'detail' => $enabled ? __( 'Notes active', 'core-blueprint' ) : __( 'Notes disabled', 'core-blueprint' ),
			'url'    => admin_url( 'admin.php?page=core-blueprint-notes' ),
		];
	}
}
