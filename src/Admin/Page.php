<?php
declare(strict_types=1);
/**
 * Page - public interface for Core Blueprint admin pages.
 *
 * PageRegistry is the canonical boundary for pages contributed beneath the
 * shared Core Blueprint menu. MenuGroupRegistry reuses the same Page contract
 * for extension-owned top-level product areas. Base owns WordPress menu wiring
 * and shared presentation requirements in both placements.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Admin;
defined( 'ABSPATH' ) || exit;

interface Page {

	/**
	 * Globally unique lower-case kebab-case WordPress admin page slug.
	 * Base-owned slugs are reserved and cannot be claimed by extensions.
	 */
	public function slug(): string;

	/**
	 * Page title - translated. Shown in the browser tab and used by the
	 * registered WordPress admin page.
	 */
	public function title(): string;

	/**
	 * Menu title - translated. Defaults to title() when not overridden.
	 * Separate method so menu navigation can use a shorter product-local label.
	 */
	public function menu_title(): string;

	/**
	 * Required WordPress capability for this page.
	 */
	public function capability(): string;

	/**
	 * Ordering position within the page's owning menu.
	 *
	 * For PageRegistry extension pages beneath Core Blueprint, public extensions
	 * return null or a position >= 100; positions 1-99 remain Base-owned.
	 * MenuGroupRegistry interprets this value only inside the extension-owned
	 * product group, where the extension owns its child-page ordering.
	 *
	 * Current Core Blueprint Base positions:
	 *   10  Dashboard
	 *   20  Logs
	 *   22  Notes
	 *   25  Reports
	 *   30  Safeguards
	 *   40  Compliance
	 *   90  Preferences
	 *   99  Extensions
	 */
	public function position(): ?int;

	/**
	 * Render the page. Called by WordPress when its registered admin route is
	 * active. Implementations output directly; return value is unused.
	 */
	public function render(): void;
}
