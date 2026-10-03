<?php
declare(strict_types=1);
/**
 * Immutable Automation Foundation trigger event value object.
 *
 * Base does not persist these events. The object exists only for the current
 * dispatch so optional orchestration consumers can receive a validated,
 * versioned transport payload.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Automation;

defined( 'ABSPATH' ) || exit;

final class TriggerEvent {

	/** @param array<string,mixed> $payload */
	public function __construct(
		private string $event_id,
		private string $provider,
		private string $trigger_id,
		private string $schema_version,
		private array $payload,
		private string $occurred_at
	) {}

	public function event_id(): string {
		return $this->event_id;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function trigger_id(): string {
		return $this->trigger_id;
	}

	public function schema_version(): string {
		return $this->schema_version;
	}

	/** @return array<string,mixed> */
	public function payload(): array {
		return $this->payload;
	}

	public function occurred_at(): string {
		return $this->occurred_at;
	}
}
