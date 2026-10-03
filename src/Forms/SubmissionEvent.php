<?php
declare(strict_types=1);
/**
 * Immutable normalized form-submission event.
 *
 * Submission bodies are request-local transport. Base does not persist this
 * object, copy its field values into Audit, or guarantee retry delivery.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Forms;

defined( 'ABSPATH' ) || exit;

final readonly class SubmissionEvent {

	/**
	 * @param list<array{id:string,value:mixed}> $fields
	 */
	public function __construct(
		private string $event_id,
		private string $provider,
		private string $implementation,
		private string $form_id,
		private ?string $submission_id,
		private array $fields,
		private string $occurred_at
	) {}

	public function event_id(): string {
		return $this->event_id;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function implementation(): string {
		return $this->implementation;
	}

	public function form_id(): string {
		return $this->form_id;
	}

	public function submission_id(): ?string {
		return $this->submission_id;
	}

	/** @return list<array{id:string,value:mixed}> */
	public function fields(): array {
		return $this->fields;
	}

	public function occurred_at(): string {
		return $this->occurred_at;
	}
}
