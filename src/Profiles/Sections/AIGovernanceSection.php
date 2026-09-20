<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\AIGovernance\Settings;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;

defined( 'ABSPATH' ) || exit;

final class AIGovernanceSection extends ExactSection {
	public function id(): string { return 'ai-governance'; }
	public function label(): string { return __( 'AI Governance', 'core-blueprint' ); }
	public function description(): string { return __( 'Portable AI activity retention policy. AI activity records are not included.', 'core-blueprint' ); }
	public function schema_version(): int { return 1; }
	public function export(): array { return [ 'retention_days' => Settings::retention_days() ]; }
	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'retention_days' ], 'AI Governance' );
		if ( ! array_key_exists( 'retention_days', $incoming ) ) {
			throw new \InvalidArgumentException( __( 'The profile contains an incomplete AI Governance policy.', 'core-blueprint' ) );
		}
		$days = SchemaGuard::int( $incoming['retention_days'], 'AI Governance retention days' );
		if ( $days < 0 || $days > Settings::MAX_RETENTION_DAYS ) {
			SchemaGuard::invalid_value( 'AI Governance retention days' );
		}
		return [ 'retention_days' => $days ];
	}
	public function apply( array $incoming, string $actor ): void {
		unset( $actor );
		$incoming = $this->normalize( $incoming );
		$ok = Settings::update_retention_days( $incoming['retention_days'] );
		if ( ! $ok && Settings::retention_days() !== $incoming['retention_days'] ) {
			throw new \RuntimeException( __( 'Could not apply the AI Governance retention policy.', 'core-blueprint' ) );
		}
	}
}
