<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class SafeMode {
	/**
	 * Emergency fail-open switch. Add `define( 'CB_CORE_DISABLE_SNIPPETS', true );`
	 * to wp-config.php to suppress every snippet without touching stored state.
	 */
	public static function is_active(): bool {
		$constant = defined( 'CB_CORE_DISABLE_SNIPPETS' ) && true === CB_CORE_DISABLE_SNIPPETS;
		return (bool) apply_filters( 'cb_core_snippets_safe_mode', $constant );
	}
}
