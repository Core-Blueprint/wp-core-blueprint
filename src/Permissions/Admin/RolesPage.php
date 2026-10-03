<?php
declare(strict_types=1);
/**
 * User Roles admin page.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions\Admin;

use CoreBlueprint\Core\Admin\PageBase;

defined( 'ABSPATH' ) || exit;

final class RolesPage extends PageBase {

	public const SLUG = 'core-blueprint-user-roles';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'User Roles', 'core-blueprint' );
	}

	public function position(): ?int {
		return 27; // Between Reports (25) and Safeguards (30).
	}

	public function capability(): string {
		return 'cb_manage_roles';
	}

	public function render(): void {
		$this->guard();
		?>
		<div class="wrap cb-core-wrap cb-user-roles-wrap" data-cb-user-roles>
			<h1 class="cb-core-title"><?php esc_html_e( 'User Roles', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Create and manage WordPress roles and primitive capabilities. Module activation is managed from the Core Blueprint Dashboard.', 'core-blueprint' ); ?></p>

			<div class="cb-user-roles-header">
				<button type="button" class="button cb-core-button cb-core-button--primary" data-cb-role-create><?php esc_html_e( '+ Add role', 'core-blueprint' ); ?></button>
			</div>

			<div class="cb-user-roles-notice" data-cb-role-notice hidden></div>

			<div class="cb-user-roles-layout" data-cb-role-app aria-live="polite">
				<section class="cb-user-roles-list-panel" aria-label="<?php esc_attr_e( 'Roles', 'core-blueprint' ); ?>">
					<label class="cb-user-roles-search">
						<span><?php esc_html_e( 'Search roles', 'core-blueprint' ); ?></span>
						<input type="search" data-cb-role-search placeholder="<?php esc_attr_e( 'Search roles…', 'core-blueprint' ); ?>" />
					</label>
					<div class="cb-user-roles-list" data-cb-role-list>
						<p class="cb-user-roles-loading"><?php esc_html_e( 'Loading roles…', 'core-blueprint' ); ?></p>
					</div>
				</section>

				<section class="cb-user-role-detail" data-cb-role-detail>
					<div class="cb-user-role-empty">
						<h2><?php esc_html_e( 'Select a role', 'core-blueprint' ); ?></h2>
						<p><?php esc_html_e( 'Choose a role on the left to inspect and manage its capabilities.', 'core-blueprint' ); ?></p>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

}
