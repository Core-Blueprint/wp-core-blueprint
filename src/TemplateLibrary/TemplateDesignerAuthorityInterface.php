<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\TemplateLibrary;
defined( 'ABSPATH' ) || exit;
/** Consumer-controlled authorization for an existing Designer resource. */
interface TemplateDesignerAuthorityInterface extends TemplateSourceInterface {
    public function can_launch_designer( string $resource_type, string $resource_key, int $user_id ): bool;
    public function can_access_designer_resource( string $resource_type, string $resource_key, int $user_id ): bool;
}
