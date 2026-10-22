<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Registry;

defined( 'ABSPATH' ) || exit;

/** Registered inspector control descriptors, data-only. */
final class ControlRegistry {
	private array $definitions = [];
	private array $failures = [];

	/** @param array<string,mixed> $descriptor */
	public function register( array $descriptor ): bool {
		$id = $descriptor['id'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( '~^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$~D', $id ) ) {
			$this->failures[] = 'REGISTRY_INVALID';
			return false;
		}
		if ( isset( $this->definitions[ $id ] ) ) {
			$this->failures[] = 'REGISTRY_COLLISION';
			return false;
		}
		$this->definitions[ $id ] = $descriptor;
		return true;
	}

	public function all(): array {
		$result = $this->definitions;
		ksort( $result, SORT_STRING );
		return array_values( $result );
	}

	public function failures(): array {
		return $this->failures;
	}
}
