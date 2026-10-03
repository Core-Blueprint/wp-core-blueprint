<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final readonly class Diagnostic {
	public function __construct(
		public string $code,
		public string $message,
		public string $location = 'design'
	) {
		if ( '' === $code || '' === $message || '' === $location ) {
			throw new \InvalidArgumentException( 'Design diagnostic fields must not be empty.' );
		}
	}

	/** @return array{code:string,message:string,location:string} */
	public function to_array(): array {
		return [
			'code'     => $this->code,
			'message'  => $this->message,
			'location' => $this->location,
		];
	}
}
