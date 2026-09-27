<?php
declare(strict_types=1);

namespace CB\Core\Snippets;

use CB\Core\Admin\PageRegistry;
use CB\Core\RequestContext;
use CB\Core\Snippets\Admin\Actions;
use CB\Core\Snippets\Admin\Page;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	public static function boot(): void {
		// Runtime registration happens during plugin load so an enabled PHP snippet
		// may still target plugins_loaded. State + safe-mode gates fail closed.
		Runtime::boot();

		add_action( 'cb_core_register_pages', static function (): void {
			if ( ! State::is_enabled() ) {
				return;
			}
			PageRegistry::register_base( new Page() );
		} );
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_item' ] );
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_quick_action' ] );
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );

		if ( RequestContext::is_admin_post() ) {
			Actions::boot();
		}
		if ( RequestContext::is_admin_screen() ) {
			add_action( 'admin_notices', [ __CLASS__, 'conflict_notice' ] );
		}
	}

	public static function conflict_notice(): void {
		if ( ! State::is_enabled() || ! current_user_can( 'cb_manage_snippets' ) ) {
			return;
		}
		$conflicts = ConflictDetector::active();
		if ( empty( $conflicts ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Another snippets runtime is active.', 'core-blueprint' ),
			esc_html( sprintf(
				/* translators: %s: comma-separated list of active snippet plugins */
				__( '%s may execute code alongside Core Blueprint Snippets. Migrate snippets as disabled copies, review them, then disable the old runtime to avoid duplicate execution.', 'core-blueprint' ),
				implode( ', ', $conflicts )
			) ),
			esc_url( admin_url( 'admin.php?page=' . Page::SLUG . '&tab=import-export' ) ),
			esc_html__( 'Review migration', 'core-blueprint' )
		);
	}

	public static function register_hud_item( string $registry ): void {
		if ( ! current_user_can( 'cb_manage_snippets' ) || ! class_exists( $registry ) ) {
			return;
		}
		$registry::add_item( [
			'id'            => 'cb-hud-cb-snippets',
			'label'         => __( 'Snippets', 'core-blueprint' ),
			'section'       => 'cb-core',
			'url'           => admin_url( 'admin.php?page=' . Page::SLUG ),
			'order'         => 24,
			'capability'    => 'cb_manage_snippets',
			'icon'          => 'editor-code',
			'module'        => 'snippets',
			'status'        => 'snippets',
		] );
	}

	public static function register_hud_quick_action( string $registry ): void {
		if ( ! current_user_can( 'cb_manage_snippets' ) || ! class_exists( $registry ) ) {
			return;
		}
		$registry::add_item( [
			'id'         => 'cb-hud-quick-add-snippet',
			'label'      => __( 'Add snippet', 'core-blueprint' ),
			'section'    => 'quick-actions',
			'url'        => admin_url( 'admin.php?page=' . Page::SLUG . '&tab=snippets&view=edit' ),
			'order'      => 12,
			'capability' => 'cb_manage_snippets',
			'icon'       => 'plus-alt2',
			'module'     => 'snippets',
		] );
	}

	/** Register translation-bearing metadata filters after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CB\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
		add_filter( 'cb_core_capability_catalog', [ __CLASS__, 'register_capability' ] );
	}

	public static function register_event_labels( array $labels ): array {
		$labels['snippets.subsystem.enabled']  = __( 'Snippets: subsystem enabled', 'core-blueprint' );
		$labels['snippets.subsystem.disabled'] = __( 'Snippets: subsystem disabled', 'core-blueprint' );
		$labels['snippet.created']       = __( 'Snippets: snippet created', 'core-blueprint' );
		$labels['snippet.updated']       = __( 'Snippets: snippet updated', 'core-blueprint' );
		$labels['snippet.enabled']       = __( 'Snippets: snippet enabled', 'core-blueprint' );
		$labels['snippet.disabled']      = __( 'Snippets: snippet disabled', 'core-blueprint' );
		$labels['snippet.duplicated']    = __( 'Snippets: snippet duplicated', 'core-blueprint' );
		$labels['snippet.deleted']       = __( 'Snippets: snippet deleted', 'core-blueprint' );
		$labels['snippet.auto.disabled'] = __( 'Snippets: snippet auto-disabled after runtime error', 'core-blueprint' );
		$labels['snippets.exported']     = __( 'Snippets: snippets exported', 'core-blueprint' );
		$labels['snippets.imported']     = __( 'Snippets: snippets imported', 'core-blueprint' );
		$labels['snippets.restore.acknowledged'] = __( 'Snippets: restore backup and recovery responsibility acknowledged', 'core-blueprint' );
		return $labels;
	}

	public static function register_capability( array $catalog ): array {
		$catalog['cb_manage_snippets'] = [
			'label'       => __( 'Manage code snippets', 'core-blueprint' ),
			'group'       => __( 'Core Blueprint', 'core-blueprint' ),
			'source'      => 'Core Blueprint',
			'description' => __( 'Create, edit, import, enable and execute managed PHP, CSS, JavaScript and HTML snippets.', 'core-blueprint' ),
		];
		return $catalog;
	}
}
