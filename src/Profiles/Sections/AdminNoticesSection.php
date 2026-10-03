<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles\Sections;

use CoreBlueprint\Core\AdminNotices\Policy;
use CoreBlueprint\Core\Profiles\ExactSection;
use CoreBlueprint\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class AdminNoticesSection extends ExactSection {

	public function id(): string { return 'admin-notices'; }
	public function label(): string { return __( 'Admin Notices', 'core-blueprint' ); }
	public function description(): string {
		return __( 'Portable audience policy for supported WordPress admin notice sources. Runtime source observations are not included.', 'core-blueprint' );
	}
	public function schema_version(): int { return 1; }

	public function export(): array {
		$policy = Policy::get();
		return [ 'rules' => $policy['rules'] ];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'rules' ], 'Admin Notices' );
		$normalized = Policy::normalize( [
			'version' => Policy::VERSION,
			'rules'   => $incoming['rules'] ?? null,
		] );

		return [ 'rules' => $normalized['rules'] ];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );
		$ok = Policy::replace( [
			'version' => Policy::VERSION,
			'rules'   => $incoming['rules'],
		], $actor );

		if ( ! $ok || ! $this->verify( $incoming ) ) {
			throw new \RuntimeException( __( 'Could not apply the Admin Notices profile policy.', 'core-blueprint' ) );
		}
	}
}
