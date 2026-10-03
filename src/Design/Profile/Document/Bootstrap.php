<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		// Document contracts are autoloaded on demand.
		// Fixed and Flow remain concrete profile-local behavior with no global profile registry.
	}
}
