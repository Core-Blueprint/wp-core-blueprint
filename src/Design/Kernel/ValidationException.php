<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class ValidationException extends \RuntimeException {
	public function __construct( private readonly Diagnostics $diagnostics ) {
		parent::__construct( sprintf( 'Design validation failed with %d diagnostic(s).', $diagnostics->count() ) );
	}

	public function diagnostics(): Diagnostics {
		return $this->diagnostics;
	}
}
