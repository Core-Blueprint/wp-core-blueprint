<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class Status {
	public static function contribute(): array {
		$url = admin_url( 'admin.php?page=core-blueprint-snippets' );
		if ( SafeMode::is_active() ) {
			return [
				'state'  => 'warn',
				'detail' => __( 'Emergency safe mode is suppressing all snippets.', 'core-blueprint' ),
				'url'    => $url,
			];
		}
		if ( ! State::is_enabled() ) {
			return [
				'state'  => 'off',
				'detail' => __( 'Snippet runtime is disabled.', 'core-blueprint' ),
				'url'    => $url,
			];
		}

		if ( ConflictDetector::has_conflict() ) {
			return [
				'state'  => 'warn',
				'detail' => __( 'Another snippets runtime is active during migration.', 'core-blueprint' ),
				'url'    => $url,
			];
		}

		$health = Repository::health();
		if ( in_array( false, $health, true ) ) {
			return [
				'state'  => 'err',
				'detail' => __( 'Snippet storage or runtime index needs attention.', 'core-blueprint' ),
				'url'    => $url,
			];
		}

		$enabled = 0;
		foreach ( Repository::all() as $meta ) {
			$enabled += ! empty( $meta['enabled'] ) ? 1 : 0;
		}
		return [
			'state'  => 'ok',
			'detail' => sprintf(
				/* translators: %d: number of enabled snippets */
				_n( '%d snippet enabled.', '%d snippets enabled.', $enabled, 'core-blueprint' ),
				$enabled
			),
			'url'    => $url,
		];
	}
}
