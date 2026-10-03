<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use RuntimeException;

final class ScanLockedException extends RuntimeException {
	public function __construct( private readonly array $lock ) {
		parent::__construct( __( 'Another Core Scanner job is already running.', 'core-blueprint' ) );
	}

	public function lock(): array {
		return $this->lock;
	}
}
