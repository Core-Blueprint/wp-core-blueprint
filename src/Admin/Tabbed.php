<?php
declare(strict_types=1);
/**
 * Tabbed - tabs helper for pages under Core Blueprint.
 *
 * Pages that dispatch sub-tabs (Security, Preferences, etc.) include
 * this trait to pick up consistent behaviour:
 *   - active_tab()   - read ?tab= from $_GET, allowlist + default
 *   - inject_tab_nav() - splice a WP nav-tab-wrapper into rendered HTML
 *
 * Tab definitions themselves live in each page's render() - the trait
 * only provides mechanics, not policy.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

trait Tabbed {

	/**
	 * Resolve the active tab from ?tab=… with allowlist + default fallback.
	 *
	 * @param string[] $allowed All permitted tab slugs.
	 * @param string   $default Fallback when the requested tab is unknown.
	 */
	protected function active_tab( array $allowed, string $default ): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, $allowed, true ) ? $tab : $default;
	}

	/**
	 * Inject a WP-native nav-tab-wrapper right after the first </h1> in
	 * the given HTML blob. Falls back to prepending the nav if no H1.
	 *
	 * @param string              $html       The rendered tab content.
	 * @param string              $page_slug  The admin page slug for link URLs.
	 * @param string              $active_tab The currently-active tab slug.
	 * @param array<string,string> $tabs      Map of tab-slug → translated label.
	 */
	protected function inject_tab_nav( string $html, string $page_slug, string $active_tab, array $tabs ): string {
		$nav = $this->build_tab_nav_html( $page_slug, $active_tab, $tabs );

		$pos = stripos( $html, '</h1>' );
		if ( false !== $pos ) {
			$insert_at = $pos + strlen( '</h1>' );
			return substr( $html, 0, $insert_at ) . "\n" . $nav . substr( $html, $insert_at );
		}
		return $nav . $html;
	}

	private function build_tab_nav_html( string $page_slug, string $active_tab, array $tabs ): string {
		return TabNav::build( $page_slug, $active_tab, $tabs );
	}

	/**
	 * Shared error-rendering helper for subsystem-missing cases.
	 */
	protected function render_subsystem_missing( string $message ): void {
		echo '<div class="wrap"><p>' . esc_html( $message ) . '</p></div>';
	}
}
