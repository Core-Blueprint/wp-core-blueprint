<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Overview - shared Overview-tab renderer for Core Blueprint admin pages.
 *
 * Every Core Blueprint admin page with multiple tabs ships an Overview tab
 * as its first, default landing. The Overview acts as a wayfinder for
 * non-technical operators (Peter's target audience): it explains what the
 * page is for, shows status at a glance (where relevant), and points at
 * the individual feature tabs with enough context to pick the right one
 * without having to open each tab first.
 *
 * This class is the shared render contract so every Overview across the
 * Core Blueprint Suite looks and behaves the same. Sibling plugins
 * (CB Hub, CB Invoice, CB Access Control, CB Protected Content, future
 * modules) can depend on CB Base and render their own Overview by calling:
 *
 *     use CoreBlueprint\Core\Admin\Overview;
 *
 *     Overview::render( [
 *         'intro'         => __( 'Short one-sentence description.', 'my-plugin' ),
 *         'tab_cards'     => [
 *             [
 *                 'slug'  => 'widgets',
 *                 'url'   => admin_url( 'admin.php?page=my-page&tab=widgets' ),
 *                 'label' => __( 'Widgets', 'my-plugin' ),
 *                 'desc'  => __( 'Create and manage widgets.', 'my-plugin' ),
 *                 'icon'  => 'admin-generic', // optional dashicons slug
 *             ],
 *             // …
 *         ],
 *         'status_cards'  => [ // optional - omit for pages without meaningful status
 *             [
 *                 'label'  => __( 'Something', 'my-plugin' ),
 *                 'value'  => __( 'Active', 'my-plugin' ),
 *                 'detail' => __( 'Details…', 'my-plugin' ),
 *                 'state'  => 'ok', // 'ok' | 'warning' | 'critical' | ''
 *             ],
 *         ],
 *         'quick_actions' => [ // optional - omit when not relevant
 *             [ 'url' => '…', 'label' => __( 'Do thing', 'my-plugin' ), 'primary' => false ],
 *         ],
 *         'banner'        => '',  // optional raw HTML (escaped by caller), e.g. bypass banner
 *     ] );
 *
 * Layout sequence, top to bottom:
 *   1. H1 with tab label ("Overview" by default; override via 'title')
 *   2. Intro paragraph (cb-core-intro class - 68ch max-width for readability)
 *   3. Banner slot (if supplied)
 *   4. Status cards (if supplied)
 *   5. Tab cards - grid of clickable cards linking to sibling tabs
 *   6. Quick actions (buttons, optional - placed at bottom so they're
 *      reachable without scrolling past the status/tabs on typical desktops)
 *
 * API stability note: this class is part of the CB Base contract that
 * sibling plugins depend on. Field names in the $config array (intro,
 * tab_cards, status_cards, quick_actions, banner, title) are stable.
 * Additive changes (new optional keys) are safe; removing or renaming keys
 * is a breaking change for every sibling plugin that adopted Overview.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


final class Overview {

	/**
	 * Render a complete Overview tab body.
	 *
	 * Echos directly - matches the rendering contract used by other
	 * Core Blueprint admin templates (include-and-echo). Callers who need
	 * to capture the HTML (e.g. to wrap it with inject_tab_nav) should
	 * ob_start() around this call.
	 *
	 * @param array $config {
	 *     Overview content. All keys optional except where noted.
	 *
	 *     @type string $title         H1 text. Defaults to "Overview".
	 *     @type string $intro         Intro paragraph text. Required for a
	 *                                  meaningful Overview, but technically
	 *                                  optional.
	 *     @type string $banner        Raw HTML banner placed above the
	 *                                  status cards. Caller is responsible
	 *                                  for escaping.
	 *     @type array  $status_cards  List of status cards. Each card:
	 *                                  [ 'label' => string, 'value' => string,
	 *                                    'detail' => string|html, 'state' => string ].
	 *                                  Omit key entirely to hide the section.
	 *     @type array  $tab_cards     List of tab cards. Each card:
	 *                                  [ 'slug' => string, 'url' => string,
	 *                                    'label' => string, 'desc' => string,
	 *                                    'icon' => string ]. `icon` is an
	 *                                  optional Icon Foundation key or legacy
	 *                                  Overview icon slug. Legacy Dashicon-era
	 *                                  slugs are mapped to Lucide aliases.
	 *     @type array  $quick_actions List of action buttons. Each action:
	 *                                  [ 'url' => string, 'label' => string,
	 *                                    'primary' => bool ].
	 * }
	 */
	public static function render( array $config ): void {
		// Apply defaults so the template doesn't need to null-check every field.
		$config = array_merge( [
			'title'         => __( 'Overview', 'core-blueprint' ),
			'intro'         => '',
			'banner'        => '',
			'status_cards'  => [],
			'tab_cards'     => [],
			'quick_actions' => [],
		], $config );

		// Shared partial does the actual rendering so markup tweaks don't
		// require touching the dispatching class.
		include CB_CORE_DIR . 'templates/partials/overview.php';
	}
}
