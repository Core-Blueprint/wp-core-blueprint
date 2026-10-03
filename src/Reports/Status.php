<?php
declare(strict_types=1);
/**
 * Reports\Status - health provider for Reports.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Reports;

defined( 'ABSPATH' ) || exit;

final class Status {
	/** @return array{state:string,detail:string,url:string} */
	public static function contribute(): array {
		$enabled = State::is_enabled();

		return [
			'state'  => $enabled ? 'ok' : 'off',
			'detail' => $enabled ? __( 'Reports active', 'core-blueprint' ) : __( 'Reports disabled', 'core-blueprint' ),
			'url'    => admin_url( 'admin.php?page=core-blueprint-reports' ),
		];
	}
}
