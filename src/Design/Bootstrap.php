<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design;

use CoreBlueprint\Core\Design\Profile\Document\Bootstrap as DocumentBootstrap;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		DocumentBootstrap::boot();
	}
}
