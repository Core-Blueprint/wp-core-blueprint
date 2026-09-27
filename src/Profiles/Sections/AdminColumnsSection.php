<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\AdminColumns\PolicyRepository;
use CB\Core\Profiles\ExactSection;

defined( 'ABSPATH' ) || exit;

final class AdminColumnsSection extends ExactSection {
	public function id(): string { return 'admin-columns'; }
	public function label(): string { return __( 'Admin Columns Governance', 'core-blueprint' ); }
	public function description(): string { return __( 'Portable site-wide column order, visibility and supported additional-column sources. Discovery labels, user Screen Options and content values are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }

	public function export(): array {
		return PolicyRepository::get();
	}

	public function normalize( array $incoming ): array {
		return PolicyRepository::normalize( $incoming );
	}

	public function apply( array $incoming, string $actor ): void {
		PolicyRepository::replace( $this->normalize( $incoming ), $actor );
	}
}
