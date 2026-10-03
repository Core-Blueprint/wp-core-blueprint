<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class Diagnostics {
	/** @var list<Diagnostic> */
	private array $items = [];

	public function add( Diagnostic $diagnostic ): void {
		$this->items[] = $diagnostic;
	}

	public function error( string $code, string $message, string $location = 'design' ): void {
		$this->add( new Diagnostic( $code, $message, $location ) );
	}

	public function has_errors(): bool {
		return [] !== $this->items;
	}

	public function count(): int {
		return count( $this->items );
	}

	/** @return list<Diagnostic> */
	public function all(): array {
		return $this->items;
	}

	/** @return list<array{code:string,message:string,location:string}> */
	public function to_array(): array {
		return array_map(
			static fn ( Diagnostic $diagnostic ): array => $diagnostic->to_array(),
			$this->items
		);
	}
}
