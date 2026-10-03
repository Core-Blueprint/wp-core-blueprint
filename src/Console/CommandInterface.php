<?php
declare(strict_types=1);
/**
 * CommandInterface - contract every Console-runnable atomic command
 * implements.
 *
 * Three methods, three concerns:
 *
 *   - execute()       - pure business logic. Receives normalised args,
 *                       returns a Result. No I/O to STDOUT, no
 *                       assumptions about presentation.
 *
 *   - args_schema()   - describes the arguments the command accepts so
 *                       the Console UI can render a form. Each entry
 *                       has a type (boolean / text / int / select /
 *                       user) and metadata (required, default, label).
 *
 *   - side_effects()  - declares what the command does to site state:
 *                         'none'        - read-only, safe to expose
 *                         'state'       - modifies durable state, banner
 *                         'destructive' - irreversible, modal-confirm
 *
 * The WP-CLI dispatch wrapper in each command's __invoke() calls
 * execute() and formats the Result as terminal output. The Console REST
 * controller calls execute() and serialises the Result as JSON. Same
 * source of truth, two presentations.
 *
 * Args contract
 * -------------
 * `execute()` receives an associative array of already-normalised
 * argument values (booleans cast to bool, ints to int, etc). The
 * normalisation happens at the CLI/REST boundary based on
 * `args_schema()`; the implementation can trust the shape.
 *
 * Result contract
 * ---------------
 * Always return a {@see Result}. Even errors - never throw out of
 * execute() unless the situation is genuinely unrecoverable; an
 * `Result::error()` is the standard failure path so the Console can
 * render it inline rather than show a 500.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\Console;

defined( 'ABSPATH' ) || exit;

interface CommandInterface {

	/**
	 * Run the command and return its Result.
	 *
	 * @param array<string, mixed> $args Normalised argument map.
	 */
	public function execute( array $args ): Result;

	/**
	 * Describe the arguments the command accepts. Each entry shape:
	 *
	 *   key       string   Argument key (matches `--foo=` in CLI).
	 *   type      string   'boolean' | 'text' | 'int' | 'select' | 'user'
	 *   label     string   Human-readable label for the form field.
	 *   required  bool     Default false.
	 *   default   mixed    Default value when omitted.
	 *   options   array    For 'select' - [ value => label, … ].
	 *   help      string   One-line help text below the field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args_schema(): array;

	/**
	 * Declare the command's effect on site state.
	 *
	 * @return 'none' | 'state' | 'destructive'
	 */
	public function side_effects(): string;
}
