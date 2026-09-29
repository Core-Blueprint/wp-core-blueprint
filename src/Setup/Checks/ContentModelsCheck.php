<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Content Models activation and schema.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\ContentModels\Admin\Page;
use CB\Core\ContentModels\Repository;
use CB\Core\Modules\ActivationRegistry;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;
use CB\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class ContentModelsCheck implements CheckInterface {

	public function id(): string { return 'content-models'; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return 'Content Models'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'cb_manage_content_models'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Page::SLUG ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$enabled = ActivationRegistry::is_enabled( 'content-models' );
			if ( ! $enabled ) {
				return new Evidence(
					Evidence::HEALTH_OK,
					'content-models.disabled',
					[ 'enabled' => false ],
					[ 'enabled' => false ]
				);
			}

			$schema = Repository::all();
			$counts = [
				'post_types'    => count( $schema['post_types'] ?? [] ),
				'taxonomies'    => count( $schema['taxonomies'] ?? [] ),
				'option_pages'  => count( $schema['option_pages'] ?? [] ),
				'field_groups'  => count( $schema['field_groups'] ?? [] ),
			];

			return new Evidence(
				Evidence::HEALTH_OK,
				'content-models.enabled',
				[
					'enabled'        => true,
					'schema_version' => Repository::SCHEMA_VERSION,
					'schema_hash'    => Fingerprint::hash( [ 'schema' => $schema ] ),
					'counts'         => $counts,
				],
				[
					'enabled' => true,
					'counts'  => $counts,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'content-models.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['enabled'] );
	}
}
