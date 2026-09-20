<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

interface SectionInterface {
	public function id(): string;
	public function label(): string;
	public function description(): string;
	public function schema_version(): int;
	public function supports_schema_version( int $schema_version ): bool;
	public function migrate( array $incoming, int $source_schema_version ): array;
	public function export(): array;
	public function normalize( array $incoming ): array;
	public function preflight( array $incoming, array $current ): void;
	public function snapshot(): array;
	/** @return list<string> */
	public function warnings( array $incoming ): array;
	/** @return list<array{path:string,before:mixed,after:mixed}> */
	public function preview( array $current, array $incoming ): array;
	public function apply( array $incoming, string $actor ): void;
	public function restore( array $snapshot, array $incoming, string $actor ): void;
	public function verify( array $incoming ): bool;
}
