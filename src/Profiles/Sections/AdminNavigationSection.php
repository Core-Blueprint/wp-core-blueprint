<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles\Sections;

use CoreBlueprint\Core\AdminNavigation\Policy;
use CoreBlueprint\Core\Profiles\ExactSection;
use CoreBlueprint\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class AdminNavigationSection extends ExactSection {

	public function id(): string { return 'admin-navigation'; }
	public function label(): string { return __( 'Admin Navigation', 'core-blueprint' ); }
	public function description(): string {
		return __( 'Portable presentation policy for the native WordPress top-level admin menu and Toolbar. Discovery state, labels, URLs and user-specific state are not included.', 'core-blueprint' );
	}
	public function schema_version(): int { return 1; }

	public function export(): array {
		$policy = Policy::get();
		return [
			'menu'    => $policy['menu'],
			'toolbar' => $policy['toolbar'],
		];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'menu', 'toolbar' ], 'Admin Navigation' );
		$normalized = Policy::normalize( [
			'version' => Policy::VERSION,
			'menu'    => SchemaGuard::object( $incoming['menu'] ?? null, 'Admin Navigation menu policy' ),
			'toolbar' => SchemaGuard::object( $incoming['toolbar'] ?? null, 'Admin Navigation Toolbar policy' ),
		] );

		return [
			'menu'    => $normalized['menu'],
			'toolbar' => $normalized['toolbar'],
		];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		$ok = Policy::replace( [
			'version' => Policy::VERSION,
			'menu'    => $incoming['menu'],
			'toolbar' => $incoming['toolbar'],
		], $actor );
		if ( ! $ok || ! $this->verify( $incoming ) ) {
			throw new \RuntimeException( __( 'Could not apply the Admin Navigation profile policy.', 'core-blueprint' ) );
		}
	}
}
