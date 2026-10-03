<?php
declare(strict_types=1);
/**
 * Module
 *
 * Contract that every Core Blueprint feature module implements.
 *
 * Modules are registered via the `cb_core_modules` filter. The registry
 * collects them on `plugins_loaded` priority 20 and calls ::boot() on each
 * after all registrations have been gathered.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

defined( 'ABSPATH' ) || exit;

interface Module {

	/**
	 * Unique machine slug. Used as the key in settings and registry.
	 */
	public function slug(): string;

	/**
	 * Human-readable label for admin UI.
	 */
	public function label(): string;

	/**
	 * Description shown in the module card. May be a plain string, or an
	 * array with 'plain' and 'technical' keys (preferred - dual description).
	 *
	 * @return string|array
	 */
	public function description();

	/**
	 * List of feature definitions this module exposes. Each feature is an
	 * array with keys:
	 *   - id           string      Unique within the module
	 *   - label        string      Human-readable feature label
	 *   - description  string|array See ::description() for shape
	 *   - restrictive  bool        If true, respects the failsafe bypass
	 *   - conflict     string      Optional conflict-id for delegation detector
	 *   - badges       array       Optional per-feature badges
	 *
	 * @return array<int, array>
	 */
	public function features(): array;

	/**
	 * Optional badges for the module as a whole (tech-spec, security-standard,
	 * CWE, compliance, CB-baseline). See UI for the rendering contract.
	 *
	 * @return array<int, array>
	 */
	public function badges(): array;

	/**
	 * Called after all modules are registered. Should add WordPress hooks.
	 * Implementations must be defensive - a boot failure must never crash the
	 * plugin. The registry wraps this in try/catch.
	 */
	public function boot(): void;
}
