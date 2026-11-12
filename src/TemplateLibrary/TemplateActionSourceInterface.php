<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * TL-M1 optional provider-owned management operations.
 *
 * Base only discovers capabilities and routes authorized requests. Providers
 * own persistence, revisions, export formats and domain permission checks.
 * A provider that does not implement this interface advertises no actions.
 */
interface TemplateActionSourceInterface extends TemplateManagementSourceInterface {
	/**
	 * Return supported action IDs for this exact actor and saved template.
	 * Allowed IDs: rename, duplicate, export-json, delete.
	 *
	 * @return list<string>
	 */
	public function supported_actions( string $template_id, int $user_id ): array;

	/**
	 * Revalidate actor, record and expected revision at execution time.
	 * Never mutate a live Designer document through this contract.
	 *
	 * @param array<string,mixed> $input Validated action-specific fields.
	 * @return array<string,mixed> Status plus only operation-specific output.
	 */
	public function perform_action(
		string $template_id, string $action, int $expected_revision,
		array $input, int $user_id
	): array;
}
