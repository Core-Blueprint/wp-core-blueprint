<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Optional T3 provider contract. Extends the T1 metadata source additively.
 *
 * Base presents safe local navigation only. The source implements Save as
 * template, copy, export and deletion in its own Designer/endpoints with
 * independent authorization and CSRF checks.
 */
interface TemplateManagementSourceInterface extends TemplateSourceInterface {
	/** Per-record write authority, distinct from can_browse and can_view. */
	public function can_manage( string $template_id, int $user_id ): bool;

	/**
	 * Return translated, local wp-admin preview/management navigation.
	 * This must NOT point to a GET handler that mutates or exports secrets.
	 */
	public function navigation( TemplateMetadata $item, int $user_id ): TemplateNavigation;
}
