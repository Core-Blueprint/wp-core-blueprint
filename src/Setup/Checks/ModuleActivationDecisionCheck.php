<?php
declare(strict_types=1);
/**
 * Declarative Setup check for simple optional module activation decisions.
 *
 * Use only when activation itself is the complete Setup concern. Modules with
 * material configuration or runtime health own a dedicated check instead.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Modules\ActivationRegistry;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class ModuleActivationDecisionCheck implements CheckInterface {

	public function __construct(
		private readonly string $module_id,
		private readonly string $fallback_label,
		private readonly string $url
	) {}

	public function id(): string { return $this->module_id; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return $this->fallback_label; }
	public function kind(): string { return self::KIND_OPTIONAL; }

	public function capability(): string {
		$definition = ActivationRegistry::definition( $this->module_id );
		return is_array( $definition ) ? (string) $definition['capability'] : 'do_not_allow';
	}

	public function configuration_url(): string { return $this->url; }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			if ( null === ActivationRegistry::definition( $this->module_id ) ) {
				return Evidence::unavailable( 'cms-tool.module-unavailable' );
			}
			$enabled = ActivationRegistry::is_enabled( $this->module_id );
			return new Evidence(
				Evidence::HEALTH_OK,
				$enabled ? 'cms-tool.enabled' : 'cms-tool.disabled',
				[
					'module'  => $this->module_id,
					'enabled' => $enabled,
				],
				[
					'module'  => $this->module_id,
					'enabled' => $enabled,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'cms-tool.module-unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['enabled'] );
	}
}
