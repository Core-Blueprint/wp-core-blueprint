<?php
declare(strict_types=1);
/**
 * Core Blueprint Admin Notices capabilities.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\AdminNotices;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const MANAGE = 'cb_manage_admin_notices';

	private function __construct() {}
}
