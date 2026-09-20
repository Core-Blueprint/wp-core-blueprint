<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

abstract class ExactSection implements SectionInterface {
	public function description(): string { return ''; }

	public function supports_schema_version( int $schema_version ): bool {
		return $this->schema_version() === $schema_version;
	}

	public function migrate( array $incoming, int $source_schema_version ): array {
		if ( ! $this->supports_schema_version( $source_schema_version ) ) {
			throw new \InvalidArgumentException( __( 'This profile section schema version is not supported.', 'core-blueprint' ) );
		}
		return $this->normalize( $incoming );
	}

	public function preflight( array $incoming, array $current ): void {
		unset( $incoming, $current );
	}

	public function snapshot(): array {
		return $this->export();
	}

	public function warnings( array $incoming ): array {
		unset( $incoming );
		return [];
	}

	public function preview( array $current, array $incoming ): array {
		return Diff::between( $current, $incoming );
	}

	public function restore( array $snapshot, array $incoming, string $actor ): void {
		$current = $this->snapshot();
		if ( ! StateGuard::is_between( $current, $snapshot, $incoming ) ) {
			throw new \RuntimeException( __( 'Configuration changed during Profile rollback and was not overwritten.', 'core-blueprint' ) );
		}
		$this->apply( $snapshot, $actor );
	}

	public function verify( array $incoming ): bool {
		return CanonicalJson::encode( $this->export() ) === CanonicalJson::encode( $incoming );
	}
}
