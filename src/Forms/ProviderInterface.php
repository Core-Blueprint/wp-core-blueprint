<?php
declare(strict_types=1);
/**
 * Runtime contract for a Forms Foundation provider implementation.
 *
 * Functional feature support is declared through the Generic Interoperability
 * descriptor. This interface only answers whether the concrete provider is
 * currently usable at runtime (for example, whether its upstream builder or
 * forms plugin is actually available).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Forms;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	/** Whether the provider can service its declared Forms Foundation features now. */
	public function is_available(): bool;
}
