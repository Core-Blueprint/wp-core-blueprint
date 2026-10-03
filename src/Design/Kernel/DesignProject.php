<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final readonly class DesignProject {
	/** @param array<string,mixed> $root */
	public function __construct(
		private int $schema_version,
		private string $design_type,
		private array $root
	) {
		if ( $schema_version < 0 || ! Identifier::design_type( $design_type ) ) {
			throw new \InvalidArgumentException( 'Invalid DesignProject envelope.' );
		}
	}

	public function schema_version(): int {
		return $this->schema_version;
	}

	public function design_type(): string {
		return $this->design_type;
	}

	/** @return array<string,mixed> */
	public function root(): array {
		return $this->root;
	}

	/** @return array{schema_version:int,design_type:string,root:array<string,mixed>} */
	public function to_array(): array {
		return [
			'schema_version' => $this->schema_version,
			'design_type'    => $this->design_type,
			'root'           => $this->root,
		];
	}
}
