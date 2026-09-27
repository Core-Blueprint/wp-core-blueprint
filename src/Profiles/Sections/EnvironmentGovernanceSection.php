<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Sections;

use CB\Core\Environment\Governance;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;
use CB\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class EnvironmentGovernanceSection extends ExactSection {

	public function id(): string {
		return 'environment-governance';
	}

	public function label(): string {
		return __( 'Environment Governance', 'core-blueprint' );
	}

	public function description(): string {
		return __( 'Portable non-production search-indexing policy. WordPress environment identity remains local to each site.', 'core-blueprint' );
	}

	public function schema_version(): int {
		return 1;
	}

	public function export(): array {
		return Governance::policy();
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys(
			$incoming,
			[ Governance::PROTECT_SEARCH_INDEXING ],
			'environment governance'
		);

		return [
			Governance::PROTECT_SEARCH_INDEXING => SchemaGuard::bool(
				$incoming[ Governance::PROTECT_SEARCH_INDEXING ] ?? null,
				'environment governance search-indexing policy'
			),
		];
	}

	public function warnings( array $incoming ): array {
		$incoming = $this->normalize( $incoming );

		return $incoming[ Governance::PROTECT_SEARCH_INDEXING ]
			? []
			: [
				__( 'This Profile disables the non-production noindex safeguard. Local, development, and staging environments will no longer receive Core Blueprint search-indexing protection from this policy.', 'core-blueprint' ),
			];
	}

	public function apply( array $incoming, string $actor ): void {
		$incoming = $this->normalize( $incoming );

		if ( ! Settings::set_key( Governance::POLICY_KEY, $incoming, $actor ) && $this->export() !== $incoming ) {
			throw new \RuntimeException( __( 'Could not apply the Environment Governance policy.', 'core-blueprint' ) );
		}
	}
}
