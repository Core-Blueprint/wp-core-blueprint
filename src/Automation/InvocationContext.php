<?php
declare(strict_types=1);
/**
 * Immutable governed Automation Foundation invocation context.
 *
 * Base carries execution identity and correlation metadata across the public
 * invocation boundary. It does not persist runs or interpret workflow state.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Automation;
defined( 'ABSPATH' ) || exit;

final class InvocationContext {

	public function __construct(
		private int $principal_user_id,
		private string $source,
		private string $correlation_id,
		private string $run_id,
		private string $step_id,
		private int $attempt,
		private ?string $workflow_id = null,
		private ?string $workflow_revision = null
	) {}

	public function principal_user_id(): int {
		return $this->principal_user_id;
	}

	public function source(): string {
		return $this->source;
	}

	public function correlation_id(): string {
		return $this->correlation_id;
	}

	public function run_id(): string {
		return $this->run_id;
	}

	public function step_id(): string {
		return $this->step_id;
	}

	public function attempt(): int {
		return $this->attempt;
	}

	public function workflow_id(): ?string {
		return $this->workflow_id;
	}

	public function workflow_revision(): ?string {
		return $this->workflow_revision;
	}
}
