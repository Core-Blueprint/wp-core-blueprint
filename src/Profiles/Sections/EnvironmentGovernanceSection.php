<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles\Sections;

use CoreBlueprint\Core\Environment\Governance;
use CoreBlueprint\Core\Profiles\ExactSection;
use CoreBlueprint\Core\Profiles\SchemaGuard;
use CoreBlueprint\Core\Settings;

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
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'Could not apply the Environment Governance policy.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
