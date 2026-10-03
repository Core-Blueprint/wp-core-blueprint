<?php
declare(strict_types=1);
/**
 * Domain exception for Media Replace operations.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\MediaReplace;

defined( 'ABSPATH' ) || exit;

final class ReplaceException extends \RuntimeException {

	private string $reason;

	public function __construct( string $reason, string $message, ?\Throwable $previous = null ) {
		$this->reason = sanitize_key( $reason );
		parent::__construct( $message, 0, $previous );
	}

	public function reason(): string {
		return $this->reason;
	}
}
