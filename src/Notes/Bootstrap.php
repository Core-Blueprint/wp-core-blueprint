<?php
declare(strict_types=1);
/**
 * Bootstrap - wires the Notes subsystem into Core Blueprint.
 *
 * Called from {@see \CB\Core\Core::init()} alongside Reports,
 * Permissions, and Integrity bootstraps. Registers:
 *
 *   - REST routes (list + action endpoints under core-blueprint/v1/notes/*)
 *   - Schema install/upgrade (idempotent, runs on every boot until the
 *     stored DB version matches the current target)
 *   - Audit-log event metadata via the canonical Governance EventRegistry
 *
 * Asset enqueue (script module + stylesheet) is registered in the central
 * {@see \CB\Core\Admin\Admin::enqueue_assets()} alongside every other CB
 * Base module - keeps a single enqueue surface, single place to read
 * which modules ship.
 *
 * The Notes UI itself renders as a top-level page under Core Blueprint;
 * see {@see \CB\Core\Notes\Admin\Page}. Notes preferences are surfaced as
 * a tab inside the central Preferences page via
 * {@see \CB\Core\Notes\Admin\PreferencesPage::render_body()}.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Notes;

use CB\Core\Admin\PageRegistry;
use CB\Core\Notes\Admin\Page;
use CB\Core\Notes\DB\Install;
use CB\Core\Notes\Rest\NotesController;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Register all Notes hooks. Called once from
	 * {@see \CB\Core\Core::init()} during plugin load.
	 */
	public static function boot(): void {
		// Register Notes before the central DB migration sweep at priority 5.
		// Version mismatches migrate immediately; table-health probes are
		// centrally throttled so Notes does not add its own per-request check.
		add_action( 'plugins_loaded', [ Install::class, 'register_schema' ], 4 );

		add_action( 'rest_api_init', [ NotesController::class, 'register' ] );
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );

		// HUD items - registered every request; the new ActivationRegistry
		// gate (via the `module` field) decides whether they survive the
		// add_item visibility chain. When Notes is disabled, the items
		// drop out before render — no separate State::is_enabled() check
		// needed here.
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_item' ] );
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_quick_action' ] );

		// Top-level Notes page registered via the existing PageRegistry
		// channel - same hook every other CB page uses, so menu position
		// and capability gating stay consistent.
		//
		// Master-switch gate (1.3.25-dev): only register the menu item when
		// Notes is enabled. When disabled, the page disappears from the
		// admin sidebar entirely; the Dashboard status menu remains the
		// activation surface. Stored notes are not touched - re-enabling
		// brings the page right back with all data intact.
		add_action( 'cb_core_register_pages', static function (): void {
			if ( ! State::is_enabled() ) {
				return;
			}
			PageRegistry::register_base( new Page() );
		} );
	}

	/**
	 * Register the Notes entry in the HUD's cb-core section. The Notes
	 * master-switch gate is delegated to ActivationRegistry via the
	 * `module` field; if Notes is disabled, this item is dropped before
	 * render.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 */
	public static function register_hud_item( string $registry ): void {
		if ( ! current_user_can( 'cb_manage_notes' ) ) {
			return;
		}
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::add_item( [
			'id'            => 'cb-hud-cb-notes',
			'label'         => __( 'Notes', 'core-blueprint' ),
			'section'       => 'cb-core',
			'url'           => admin_url( 'admin.php?page=core-blueprint-notes' ),
			'order'         => 25,
			'capability'    => 'cb_manage_notes',
			'icon'          => 'admin-page',
			'module'        => 'notes',
			'status'        => 'notes',
		] );
	}

	/**
	 * Register the "Add note" verb-action in the HUD's quick-actions
	 * section. Links straight to the Notes admin page with a query
	 * arg the page picks up to open the New Note modal — operator
	 * is one click from creating a note from anywhere.
	 *
	 * Capability matches the create-permission for notes; module-gated
	 * so the action drops out when Notes is disabled.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 */
	public static function register_hud_quick_action( string $registry ): void {
		if ( ! current_user_can( 'cb_manage_notes' ) ) {
			return;
		}
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::add_item( [
			'id'         => 'cb-hud-quick-add-note',
			'label'      => __( 'Add note', 'core-blueprint' ),
			'section'    => 'quick-actions',
			'url'        => admin_url( 'admin.php?page=core-blueprint-notes&cb_action=new' ),
			'order'      => 10,
			'capability' => 'cb_manage_notes',
			'icon'       => 'plus-alt2',
			'module'     => 'notes',
		] );
	}

	/** Register translation-bearing metadata filters after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CB\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/**
	 * Contribute Notes event labels to the central audit-log label
	 * registry. Labels follow CB Base convention: no `cb_` prefix
	 * (subsystem-prefix-only), present tense for the action.
	 *
	 * @param array<string,string> $labels Existing labels.
	 *
	 * @return array<string,string>
	 */
	public static function register_event_labels( array $labels ): array {
		$labels['notes.subsystem.enabled']  = __( 'Notes: subsystem enabled',  'core-blueprint' );
		$labels['notes.subsystem.disabled'] = __( 'Notes: subsystem disabled', 'core-blueprint' );
		$labels['note.created']        = __( 'Notes: note created',         'core-blueprint' );
		$labels['note.updated']        = __( 'Notes: note updated',         'core-blueprint' );
		$labels['note.duplicated']     = __( 'Notes: note duplicated',      'core-blueprint' );
		$labels['note.status.changed'] = __( 'Notes: note status changed',  'core-blueprint' );
		$labels['note.archived']       = __( 'Notes: note archived',        'core-blueprint' );
		$labels['note.deleted']        = __( 'Notes: note deleted',         'core-blueprint' );
		$labels['notes.bulk.deleted']  = __( 'Notes: bulk deleted',         'core-blueprint' );
		$labels['notes.exported']      = __( 'Notes: exported',             'core-blueprint' );
		$labels['notes.imported']      = __( 'Notes: imported',             'core-blueprint' );
		$labels['notes.import.overwrite.acknowledged'] = __( 'Notes: import overwrite backup and recovery responsibility acknowledged', 'core-blueprint' );

		return $labels;
	}
}
