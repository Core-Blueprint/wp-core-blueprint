<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Interoperability;

use CoreBlueprint\Core\DataExchange\EntityInterface;
use CoreBlueprint\Core\DataExchange\Foundation;
use CoreBlueprint\Core\Mail\Designer\SavedTemplatePortability;

defined( 'ABSPATH' ) || exit;

/**
 * Trusted Base-owned implementations, separate from the public extension hook.
 * No third-party callback may claim the core-blueprint provider identity.
 */
final class BaseImplementationCatalog {
	public static function definitions(): array {
		return [ [
			'provider' => 'core-blueprint',
			'id' => SavedTemplatePortability::ENTITY,
			'label' => __( 'Saved Mail template', 'core-blueprint' ),
			'description' => __( 'Offline JSON import/export for native saved Mail templates.', 'core-blueprint' ),
			'contract_owner' => Foundation::CONTRACT_OWNER,
			'contract' => Foundation::CONTRACT_ID,
			'contract_version' => Foundation::CONTRACT_VERSION,
			'supports' => [ Foundation::SUPPORT_EXPORT, Foundation::SUPPORT_IMPORT, Foundation::SUPPORT_JSON ],
			'factory' => static fn(): EntityInterface => new SavedTemplatePortability(),
		] ];
	}

	private function __construct() {}
}
