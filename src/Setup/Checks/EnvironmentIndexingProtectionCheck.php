<?php
declare(strict_types=1);
/**
 * Core Setup evidence for non-production search-indexing governance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Safeguards;
use CoreBlueprint\Core\Environment\Governance;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class EnvironmentIndexingProtectionCheck implements CheckInterface {

	public function id(): string { return 'environment-indexing-protection'; }
	public function section(): string { return 'environment-availability'; }
	public function label(): string { return 'Environment indexing protection'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=environment' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$type      = Governance::current_type();
			$policy    = Governance::policy();
			$protect   = ! empty( $policy[ Governance::PROTECT_SEARCH_INDEXING ] );
			$attention = 'production' !== $type && ! $protect;

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention
					? 'environment.non-production-unprotected'
					: ( 'production' === $type ? 'environment.production' : 'environment.non-production-protected' ),
				[
					'environment_type'        => $type,
					'protect_search_indexing' => $protect,
				],
				[
					'environment_type'        => $type,
					'protect_search_indexing' => $protect,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'environment.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		$context = $evidence->context();
		return 'production' === (string) ( $context['environment_type'] ?? '' );
	}
}
