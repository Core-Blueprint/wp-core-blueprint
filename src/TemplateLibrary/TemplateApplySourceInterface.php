<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, read-only native template instantiation. The source retains
 * catalog ownership; Base admits the document but never persists it.
 */
interface TemplateApplySourceInterface extends TemplateSourceInterface {
	public function target_profile_id(): string;
	public function can_apply_to( string $template_id, string $target_type, string $target_key, int $actor_id ): bool;
	/** @return array{project:array<string,mixed>,fields:array<string,mixed>}|null */
	public function instantiate_for_apply( string $template_id, string $target_type, string $target_key, int $actor_id ): ?array;
}
