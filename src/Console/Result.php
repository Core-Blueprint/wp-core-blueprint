<?php
declare(strict_types=1);
/**
 * Result - value object returned from every CB CLI command's execute().
 *
 * Lives at the boundary between business-logic execution and presentation.
 * The Commands\* classes return a Result; the WP-CLI dispatch wrapper
 * formats it as terminal output, the Console REST endpoint sends it as
 * JSON to the browser. Same data, two presentations.
 *
 * Shape:
 *   status   'success' | 'warning' | 'error'
 *            High-level outcome flag for UI affordance - green / amber / red.
 *
 *   message  Single-line summary suitable for a top banner. Optional;
 *            commands that print N lines but no overall summary leave
 *            this empty.
 *
 *   lines    Plain-text rendering, one line per array entry. Both the
 *            CLI and Console render these monospace, line-by-line. UI
 *            does not interpret these - no markdown, no HTML, no ANSI.
 *
 *   data     Optional structured payload. The Console renders this as
 *            a foldable JSON view alongside the line output for commands
 *            that benefit from it (status snapshots, scan results,
 *            operator listings). Commands without a structured shape
 *            leave this null.
 *
 *   meta     Reserved for execution metadata (duration, started_at,
 *            actor) that the audit-log writer reads. Not surfaced to
 *            the UI directly.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\Console;

defined( 'ABSPATH' ) || exit;

final class Result {

	public string $status;
	public string $message;

	/** @var array<int, string> */
	public array $lines;

	/** @var array<string, mixed>|null */
	public ?array $data;

	/** @var array<string, mixed> */
	public array $meta;

	/**
	 * @param array<int, string>          $lines
	 * @param array<string, mixed>|null   $data
	 * @param array<string, mixed>        $meta
	 */
	public function __construct(
		string $status = 'success',
		string $message = '',
		array $lines = [],
		?array $data = null,
		array $meta = []
	) {
		$this->status  = self::normalize_status( $status );
		$this->message = $message;
		$this->lines   = array_values( array_map( 'strval', $lines ) );
		$this->data    = $data;
		$this->meta    = $meta;
	}

	/** Convenience constructor - success with optional message + lines. */
	public static function success( string $message = '', array $lines = [], ?array $data = null ): self {
		return new self( 'success', $message, $lines, $data );
	}

	/** Convenience constructor - warning. */
	public static function warning( string $message, array $lines = [], ?array $data = null ): self {
		return new self( 'warning', $message, $lines, $data );
	}

	/** Convenience constructor - error. */
	public static function error( string $message, array $lines = [], ?array $data = null ): self {
		return new self( 'error', $message, $lines, $data );
	}

	/**
	 * Append a line. Returns the same Result so callers can chain.
	 */
	public function add_line( string $line ): self {
		$this->lines[] = $line;
		return $this;
	}

	/** JSON-serialisable representation for the REST response. */
	public function to_array(): array {
		return [
			'status'  => $this->status,
			'message' => $this->message,
			'lines'   => $this->lines,
			'data'    => $this->data,
		];
	}

	private static function normalize_status( string $value ): string {
		$value = strtolower( $value );
		return in_array( $value, [ 'success', 'warning', 'error' ], true ) ? $value : 'success';
	}
}
