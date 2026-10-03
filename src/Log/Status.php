<?php
declare(strict_types=1);
/**
 * Log\Status - health provider for the always-on audit/log subsystem.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Log;

defined( 'ABSPATH' ) || exit;

final class Status {
	/** @return array{state:string,detail:string,url:string} */
	public static function contribute(): array {
		return [
			'state'  => 'ok',
			'detail' => __( 'Logging active', 'core-blueprint' ),
			'url'    => admin_url( 'admin.php?page=core-blueprint-logs' ),
		];
	}
}
