<?php
declare(strict_types=1);
/**
 * Public integration facade for Core Blueprint Notes.
 *
 * External plugins should use this class instead of calling Repository
 * directly. Keeps the public surface explicit and lets the storage layer
 * evolve internally without breaking integrations.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Notes\Api;

use CoreBlueprint\Core\Notes\Repository;

defined( 'ABSPATH' ) || exit;

final class NotesApi {

	public static function create( array $data ): int {
		global $wpdb;

		$created = Repository::create( $data );

		if ( ! $created ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		return Repository::update( $id, $data );
	}

	public static function delete( int $id ): bool {
		return Repository::delete( $id );
	}

	public static function archive( int $id ): bool {
		return Repository::archive( $id );
	}
}
