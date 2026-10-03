<?php
declare(strict_types=1);
/**
 * PageBase - shared behaviour for Core Blueprint admin pages.
 *
 * Provides sensible defaults (manage_options capability, menu_title
 * falls back to title, no position preference) plus a guard helper
 * that every page's render() calls before emitting output.
 *
 * Internal convenience implementation for Base-owned pages. It is not part
 * of the public v1 extension contract; extensions implement Page directly.
 *
 * @package Core_Blueprint
 * @internal
 */

namespace CoreBlueprint\Core\Admin;

defined( 'ABSPATH' ) || exit;

abstract class PageBase implements Page {

	public function capability(): string {
		return 'manage_options';
	}

	public function menu_title(): string {
		return $this->title();
	}

	public function position(): ?int {
		return null;
	}

	/**
	 * Capability guard - terminates the request with a standard WP
	 * permission error when the current user isn't allowed to see
	 * this page. Call at the top of every render().
	 */
	protected function guard(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
	}
}
