<?php
declare(strict_types=1);
/**
 * Public asset boundary for the shared Design Foundation editor engine.
 *
 * Extensions opt into this capability semantically through PageRegistry or
 * SettingsRegistry. Base owns module/script identifiers, versioning and private
 * source layout behind these public Design Foundation asset methods.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Design\Editor;

use CoreBlueprint\Core\Brand\CoreBlueprintLockup;
use CoreBlueprint\Core\Brand\CoreBlueprintMark;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const MODULE_ID = '@cb-core/design-editor';
	/** Public Designer v2 session contract; release parity requires same-ZIP proof. */
	public const DOMAIN_SESSION_API = 3;
	/** Public shared Elements/Dynamic Data renderer, same-ZIP ESM and global parity. */
	public const DESIGNER_ELEMENTS_PALETTE_API = 1;
	/** DF-C public contextual placement candidate; no consumer domain command authority. */
	public const DESIGNER_CONTEXT_PLACEMENT_API = 1;
	/** A consumer may authoritatively withhold Save without Base re-enabling it on idle/error. */
	public const SAVE_AUTHORITY_UI_API = 1;
	/** Public opt-in contract for compact Inspector sections and textarea growth. */
	public const INSPECTOR_COMPOSITION_API = 1;
	/** Optional shared graph-neutral canvas pan/fit controller. */
	public const CANVAS_NAVIGATION_API = 1;
	/** Optional shared flat context menu presenter; no domain authority. */
	public const DESIGNER_CONTEXT_MENU_API = 1;
	/** Public fail-closed Exit Guard, not an A2-06 receipt/recovery API. */
	public const DESIGNER_EXIT_GUARD_API = 2;
	public const SHELL_STYLE = 'cb-core-design-editor-shell';
	public const MAIL_RICH_TEXT_STYLE = 'cb-core-design-mail-rich-text';
	public const DESIGNER_MODE_STYLE = 'cb-core-designer-mode';
	public const DESIGNER_COMPOSITION_STYLE = 'cb-core-designer-composition';
	public const DESIGNER_CONTEXT_STYLE = 'cb-core-designer-context';
	public const DESIGNER_TOOLBAR_STYLE = 'cb-core-designer-toolbar';
	public const DESIGNER_EXIT_GUARD_SCRIPT = 'cb-core-designer-exit-guard';
	public const DESIGNER_MODE_SCRIPT = 'cb-core-designer-mode';
	public const DESIGNER_LAYOUT_SCRIPT = 'cb-core-designer-layout';
	public const DESIGNER_CONTEXT_SCRIPT = 'cb-core-designer-context';
	public const DESIGNER_TOOLBAR_SCRIPT = 'cb-core-designer-toolbar';
	private const TOKEN_STYLE = 'cb-core-css-tokens';
	private const BUTTON_STYLE = 'cb-core-css-buttons';
	private const FORM_CONTROL_STYLE = 'cb-core-css-form-controls';
	private const BRAND_LOCKUP_STYLE = 'cb-core-css-brand-lockup';
	private const MOTION_MODULE_ID = '@cb-core/design-motion';

	/**
	 * Canonical future platform ESM sources. Inventory only: all these
	 * files are owned by other Lunas and are NOT registered or loaded.
	 * The integration stage must bind static WP module IDs/dependencies,
	 * verify the frozen graph, installed artifact evidence and applied CSS
	 * before the existing @cb-core/design-editor entry can enable Host READY.
	 */
	private const PLATFORM_REQUIRED_SOURCES = [
		'assets/js/design/core/stable-schema.js',
		'assets/js/design/platform/registry.js',
		'assets/js/design/platform/manifest.js',
		'assets/js/design/platform/host.js',
		'assets/js/design/platform/lifecycle.js',
		'assets/js/design/platform/context.js',
		'assets/js/design/platform/preflight.js',
		'assets/js/design/platform/render-adapter.js',
		'assets/js/design/platform/inspector-binding.js',
		'assets/js/design/platform/elements-palette.js',
		'assets/js/design/platform/save-coordinator.js',
		'assets/js/design/platform/persisted-fields.js',
	];

	/**
	 * Source-only integration inventory, NOT registration or readiness.
	 *
	 * @return array{entry_module:string,entry_source:string,required_sources:list<string>}
	 */
	public static function platform_module_plan(): array {
		return [
			'entry_module' => self::MODULE_ID,
			'entry_source' => 'assets/js/design/editor.js',
			'required_sources' => self::PLATFORM_REQUIRED_SOURCES,
		];
	}

	public static function enqueue(): void {
		// The global Admin Theme normally enqueues semantic tokens first on
		// wp-admin. This public editor boundary still owns the same canonical
		// stylesheet dependency so its asset graph does not depend on incidental
		// enqueue order. Theme-state resolution remains owned by AdminTheme.
		wp_enqueue_style(
			self::TOKEN_STYLE,
			CB_CORE_URL . 'assets/css/tokens.css',
			[],
			self::asset_version( 'assets/css/tokens.css' )
		);

		wp_enqueue_style(
			self::SHELL_STYLE,
			CB_CORE_URL . 'assets/css/design/editor-shell.css',
			[ self::TOKEN_STYLE ],
			self::asset_version( 'assets/css/design/editor-shell.css' )
		);

		wp_register_script_module(
			self::MOTION_MODULE_ID,
			CB_CORE_URL . 'assets/js/design/core/motion.js',
			[],
			self::asset_version( 'assets/js/design/core/motion.js' )
		);

		wp_enqueue_script_module(
			self::MODULE_ID,
			CB_CORE_URL . 'assets/js/design/editor.js',
			[ self::MOTION_MODULE_ID ],
			self::asset_version( 'assets/js/design/editor.js' )
		);
	}

	/**
	 * Explicit editor route. The normal admin page must not enqueue or mount
	 * the native Designer until the operator clicks its launch button.
	 * Authorization is still enforced by each page and the REST transport.
	 */
	public static function platform_fullscreen_requested(): bool {
		return isset( $_GET['cb_designer'] ) && is_string( $_GET['cb_designer'] )
			&& '1' === wp_unslash( $_GET['cb_designer'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route selection.
	}

	/**
	 * Localized, presentation-only labels for Base native Designer profiles.
	 * Registry fingerprints, schema fields and Save identifiers stay unchanged.
	 *
	 * @return array<string,string>
	 */
	private static function platform_field_labels(): array {
		return [
			'accent_color' => __( 'Accent Color', 'core-blueprint' ),
			'corner_style' => __( 'Corner Style', 'core-blueprint' ),
			'density' => __( 'Density', 'core-blueprint' ),
			'logo_attachment_id' => __( 'Logo', 'core-blueprint' ),
			'page_format' => __( 'Page Format', 'core-blueprint' ),
			'page_orientation' => __( 'Page Orientation', 'core-blueprint' ),
			'page_margin_mm' => __( 'Page Margin (mm)', 'core-blueprint' ),
			'provider_contact' => __( 'Provider Contact', 'core-blueprint' ),
			'provider_name' => __( 'Provider Name', 'core-blueprint' ),
			'section_spacing_mm' => __( 'Section Spacing (mm)', 'core-blueprint' ),
			'show_logo' => __( 'Show Logo', 'core-blueprint' ),
			'surface_style' => __( 'Surface Style', 'core-blueprint' ),
			'text_scale' => __( 'Text Scale', 'core-blueprint' ),
			'subject' => __( 'Subject', 'core-blueprint' ),
			'preheader' => __( 'Preheader', 'core-blueprint' ),
			'title' => __( 'Title', 'core-blueprint' ),
			'enabled' => __( 'Enabled', 'core-blueprint' ),
			'showIntro' => __( 'Show Introduction', 'core-blueprint' ),
			'showNotes' => __( 'Show Notes', 'core-blueprint' ),
			'showPageNumber' => __( 'Show Page Number', 'core-blueprint' ),
			'showSiteUrl' => __( 'Show Site URL', 'core-blueprint' ),
			'showMetadata' => __( 'Show Metadata', 'core-blueprint' ),
			'text' => __( 'Text', 'core-blueprint' ),
			'showDetails' => __( 'Show Details', 'core-blueprint' ),
			'label' => __( 'Label', 'core-blueprint' ),
			'value' => __( 'Value', 'core-blueprint' ),
			'showHeading' => __( 'Show Heading', 'core-blueprint' ),
			'showSecurity' => __( 'Show Security', 'core-blueprint' ),
			'showBackups' => __( 'Show Backups', 'core-blueprint' ),
			'background' => __( 'Background', 'core-blueprint' ),
			'contentBackground' => __( 'Content Background', 'core-blueprint' ),
			'textColor' => __( 'Text Color', 'core-blueprint' ),
			'accentColor' => __( 'Accent Color', 'core-blueprint' ),
			'fontFamily' => __( 'Font Family', 'core-blueprint' ),
			'width' => __( 'Width', 'core-blueprint' ),
			'padding' => __( 'Padding', 'core-blueprint' ),
			'spacing' => __( 'Spacing', 'core-blueprint' ),
			'fontSize' => __( 'Font Size', 'core-blueprint' ),
			'align' => __( 'Alignment', 'core-blueprint' ),
			'color' => __( 'Color', 'core-blueprint' ),
			'markup' => __( 'Rich Text', 'core-blueprint' ),
			'url' => __( 'URL', 'core-blueprint' ),
			'radius' => __( 'Corner Radius', 'core-blueprint' ),
			'alt' => __( 'Alternative Text', 'core-blueprint' ),
			'height' => __( 'Height', 'core-blueprint' ),
			'ratio' => __( 'Column Ratio', 'core-blueprint' ),
			'gap' => __( 'Column Gap', 'core-blueprint' ),
			'heading' => __( 'Heading', 'core-blueprint' ),
			'section' => __( 'Section', 'core-blueprint' ),
			'rich-text' => __( 'Rich Text', 'core-blueprint' ),
			'button' => __( 'Button', 'core-blueprint' ),
			'image' => __( 'Image', 'core-blueprint' ),
			'divider' => __( 'Divider', 'core-blueprint' ),
			'spacer' => __( 'Spacer', 'core-blueprint' ),
			'columns' => __( 'Two Columns', 'core-blueprint' ),
			'column' => __( 'Column', 'core-blueprint' ),
			'activity' => __( 'Activity', 'core-blueprint' ),
			'current-state' => __( 'Current State', 'core-blueprint' ),
			'footer' => __( 'Footer', 'core-blueprint' ),
			'header' => __( 'Header', 'core-blueprint' ),
			'kpis' => __( 'KPIs', 'core-blueprint' ),
			'metric' => __( 'Metric', 'core-blueprint' ),
			'notes' => __( 'Notes', 'core-blueprint' ),
			'paragraph' => __( 'Paragraph', 'core-blueprint' ),
			'status' => __( 'Status', 'core-blueprint' ),
			'summary' => __( 'Summary', 'core-blueprint' ),
			'mail' => __( 'Mail', 'core-blueprint' ),
			'reports' => __( 'Reports', 'core-blueprint' ),
		];
	}

	/**
	 * Single native Platform v1 entrypoint for the TWO Base-owned Designers.
	 * Never enqueue the historical Mail/Reports editor engines on these routes.
	 * JavaScript imports precisely these statically registered WordPress modules.
	 */
	public static function enqueue_platform(): void {
		self::enqueue();
		// Native hosts share the exact same branded lockup stylesheet as
		// consumer Designers; no additional font request or logo copy.
		CoreBlueprintLockup::enqueue_assets();
		// Native Mail/Reports Host uses the same Core Toast Foundation as
		// every other Designer surface, including in fullscreen mode.
		\CoreBlueprint\Core\UI\Assets::enqueue_toasts( \CoreBlueprint\Core\UI\Assets::TOAST_PRESENTATION_CORE );
		wp_enqueue_style(
			self::BUTTON_STYLE, CB_CORE_URL . 'assets/css/components/buttons.css',
			[ self::TOKEN_STYLE ], self::asset_version( 'assets/css/components/buttons.css' )
		);
		wp_enqueue_style(
			self::FORM_CONTROL_STYLE, CB_CORE_URL . 'assets/css/components/form-controls.css',
			[ self::TOKEN_STYLE ], self::asset_version( 'assets/css/components/form-controls.css' )
		);
		wp_enqueue_style(
			self::DESIGNER_MODE_STYLE, CB_CORE_URL . 'assets/css/design/designer-mode.css',
			[ self::SHELL_STYLE, self::BUTTON_STYLE, self::BRAND_LOCKUP_STYLE ], self::asset_version( 'assets/css/design/designer-mode.css' )
		);
		wp_enqueue_style(
			self::DESIGNER_COMPOSITION_STYLE, CB_CORE_URL . 'assets/css/design/designer-composition.css',
			[ self::DESIGNER_MODE_STYLE, self::FORM_CONTROL_STYLE ],
			self::asset_version( 'assets/css/design/designer-composition.css' )
		);
		wp_enqueue_style(
			'cb-core-designer-platform-fullscreen',
			CB_CORE_URL . 'assets/css/design/platform-fullscreen.css',
			[ self::DESIGNER_COMPOSITION_STYLE ],
			self::asset_version( 'assets/css/design/platform-fullscreen.css' )
		);
		// The fullscreen Host must use the same coloured gradient mark as
		// the Base HUD and Brand Lockup, not the white admin-menu SVG.
		// Resolve the image from the canonical Base-owned brand source.
		wp_add_inline_style(
			'cb-core-designer-platform-fullscreen',
			'.cb-core-design-shell--native .cb-core-design-shell__brand-mark {'
			. 'background-image: url("' . CoreBlueprintMark::data_uri() . '");'
			. '}'
		);
		foreach ( [
			'@cb-core/mail-platform' => 'assets/js/design/mail/platform-adapter.js',
			'@cb-core/reports-platform' => 'assets/js/design/reports/platform-adapter.js',
		] as $handle => $path ) {
			wp_register_script_module( $handle, CB_CORE_URL . $path, [],
				self::asset_version( $path ) );
		}
		// A separately registered classic inline handle prints the REST nonce
		// before the module executes. This is a request CSRF token, not an
		// ArtifactEvidence identity or permission grant.
		wp_register_script( 'cb-core-platform-v1-config', false, [], CB_CORE_VERSION, true );
		wp_enqueue_script( 'cb-core-platform-v1-config' );
		wp_add_inline_script( 'cb-core-platform-v1-config',
			'window.cbCorePlatformV1=' . wp_json_encode( [
				'restRoot' => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'labels' => self::platform_field_labels(),
				'saveNotices' => [
					'saved' => __( 'Changes saved and verified.', 'core-blueprint' ),
					'conflict' => __( 'This document changed elsewhere. Your changes were not confirmed as saved.', 'core-blueprint' ),
					'refused' => __( 'Save was refused. Your changes have not been confirmed as saved.', 'core-blueprint' ),
					'unsynchronized' => __( 'Changes may be stored, but the editor could not reconcile them. Keep this Designer open.', 'core-blueprint' ),
					'unknown' => __( 'Save outcome is uncertain. Choose Verify save status to check the original operation. Keep this Designer open.', 'core-blueprint' ),
				],
			] ) . ';',
			'before'
		);
		wp_enqueue_script_module(
			'@cb-core/base-platform-v1',
			CB_CORE_URL . 'assets/js/design/platform/wordpress-save-adapter.js',
			[ self::MODULE_ID, '@cb-core/toast', '@cb-core/mail-platform', '@cb-core/reports-platform' ],
			self::asset_version( 'assets/js/design/platform/wordpress-save-adapter.js' )
		);
	}

	/**
	 * Public Mail profile styling; shared by the Base Mail and Newsletter
	 * Designers. The styling is opt-in and does not load Base Mail admin chrome.
	 */
	public static function enqueue_mail_rich_text_controls(): void {
		self::enqueue();
		wp_enqueue_style(
			self::MAIL_RICH_TEXT_STYLE,
			CB_CORE_URL . 'assets/css/design/mail-rich-text-controls.css',
			[ self::SHELL_STYLE ],
			self::asset_version( 'assets/css/design/mail-rich-text-controls.css' )
		);
	}

	/**
	 * Enqueue the canonical Core Blueprint Designer Mode around a consumer shell.
	 *
	 * Consumers provide their translated mode title plus declarative
	 * `data-cb-design-*` shell contracts and domain callbacks. Base owns launch/
	 * focus chrome, brand, shared labels, canonical composition primitives,
	 * async context-switch lifecycle/transition and the private Designer Mode
	 * source path. Consumers load/apply their domain context through the public
	 * context request event; they do not navigate or build product loaders.
	 * Designer Mode also owns the narrow shared Button and Form Control
	 * presentation required by the Base chrome and panel grammar it composes;
	 * consumers do not need the full Core Admin theme.
	 */
	public static function enqueue_designer_mode( string $title = '' ): void {
		self::enqueue();
		CoreBlueprintLockup::enqueue_assets();
		// Designer owns temporary operation feedback, including fullscreen.
		// The Core presentation is explicit for this Base-themed surface.
		\CoreBlueprint\Core\UI\Assets::enqueue_toasts( \CoreBlueprint\Core\UI\Assets::TOAST_PRESENTATION_CORE );
		\CoreBlueprint\Core\UI\Assets::enqueue_modals( \CoreBlueprint\Core\UI\Assets::MODAL_PRESENTATION_CORE );

		$title = sanitize_text_field( trim( $title ) );
		if ( '' === $title ) {
			$title = __( 'Design with Core Blueprint', 'core-blueprint' );
		}

		wp_enqueue_style(
			self::BUTTON_STYLE,
			CB_CORE_URL . 'assets/css/components/buttons.css',
			[ self::TOKEN_STYLE ],
			self::asset_version( 'assets/css/components/buttons.css' )
		);

		wp_enqueue_style(
			self::FORM_CONTROL_STYLE,
			CB_CORE_URL . 'assets/css/components/form-controls.css',
			[ self::TOKEN_STYLE ],
			self::asset_version( 'assets/css/components/form-controls.css' )
		);

		wp_enqueue_style(
			self::DESIGNER_MODE_STYLE,
			CB_CORE_URL . 'assets/css/design/designer-mode.css',
			[ self::SHELL_STYLE, self::BUTTON_STYLE, self::BRAND_LOCKUP_STYLE ],
			self::asset_version( 'assets/css/design/designer-mode.css' )
		);

		wp_enqueue_style(
			self::DESIGNER_COMPOSITION_STYLE,
			CB_CORE_URL . 'assets/css/design/designer-composition.css',
			[ self::DESIGNER_MODE_STYLE, self::FORM_CONTROL_STYLE ],
			self::asset_version( 'assets/css/design/designer-composition.css' )
		);

		wp_enqueue_style(
			self::DESIGNER_CONTEXT_STYLE,
			CB_CORE_URL . 'assets/css/design/designer-context.css',
			[ self::DESIGNER_COMPOSITION_STYLE ],
			self::asset_version( 'assets/css/design/designer-context.css' )
		);

		wp_enqueue_style(
			self::DESIGNER_TOOLBAR_STYLE,
			CB_CORE_URL . 'assets/css/design/designer-toolbar.css',
			[ self::DESIGNER_CONTEXT_STYLE, self::BUTTON_STYLE ],
			self::asset_version( 'assets/css/design/designer-toolbar.css' )
		);

		wp_enqueue_script(
			self::DESIGNER_EXIT_GUARD_SCRIPT,
			CB_CORE_URL . 'assets/js/features/designer-exit-guard.js',
			[],
			self::asset_version( 'assets/js/features/designer-exit-guard.js' ),
			true
		);

		// Localize the Exit Guard on its own earlier-loading script, not on the
		// Designer launch handle. This ensures translated defaults are present
		// before the shared guard initializes and consumers register adapters.
		wp_localize_script(
			self::DESIGNER_EXIT_GUARD_SCRIPT,
			'cbCoreDesignerExitGuardLabels',
			[
				'cleanTitle' => __( 'Close Designer?', 'core-blueprint' ),
				'cleanDescription' => __( 'Return to the previous page?', 'core-blueprint' ),
				'unknownDescription' => __( 'Changes may not be saved. Close Designer?', 'core-blueprint' ),
				'dirtyTitle' => __( 'Unsaved changes', 'core-blueprint' ),
				'dirtyDescription' => __( 'Save or discard your changes before closing the Designer.', 'core-blueprint' ),
				'choiceTitle' => __( 'What should happen to your changes?', 'core-blueprint' ),
				'save' => __( 'Save changes', 'core-blueprint' ),
				'discard' => __( 'Discard changes', 'core-blueprint' ),
				'transitionConfirm' => __( 'Switch design', 'core-blueprint' ),
				'transitionDirtyDescription' => __( 'Save or discard your changes before switching designs.', 'core-blueprint' ),
				'close' => __( 'Close Designer', 'core-blueprint' ),
				'cancel' => __( 'Cancel', 'core-blueprint' ),
				'busy' => __( 'Wait for the current save to finish.', 'core-blueprint' ),
				'unavailable' => __( 'The Designer cannot be closed safely. Try again.', 'core-blueprint' ),
				'saveFailure' => __( 'Your changes could not be verified as saved. The Designer remains open.', 'core-blueprint' ),
			]
		);

		wp_enqueue_script(
			self::DESIGNER_MODE_SCRIPT,
			CB_CORE_URL . 'assets/js/features/designer-launch.js',
			[ self::DESIGNER_EXIT_GUARD_SCRIPT ],
			self::asset_version( 'assets/js/features/designer-launch.js' ),
			true
		);

		wp_enqueue_script(
			self::DESIGNER_LAYOUT_SCRIPT,
			CB_CORE_URL . 'assets/js/features/designer-layout.js',
			[ self::DESIGNER_MODE_SCRIPT ],
			self::asset_version( 'assets/js/features/designer-layout.js' ),
			true
		);

		wp_enqueue_script(
			self::DESIGNER_CONTEXT_SCRIPT,
			CB_CORE_URL . 'assets/js/features/designer-context.js',
			[ self::DESIGNER_MODE_SCRIPT, self::DESIGNER_LAYOUT_SCRIPT ],
			self::asset_version( 'assets/js/features/designer-context.js' ),
			true
		);

		wp_enqueue_script(
			self::DESIGNER_TOOLBAR_SCRIPT,
			CB_CORE_URL . 'assets/js/features/designer-toolbar.js',
			[ self::DESIGNER_MODE_SCRIPT, self::DESIGNER_LAYOUT_SCRIPT, self::DESIGNER_CONTEXT_SCRIPT ],
			self::asset_version( 'assets/js/features/designer-toolbar.js' ),
			true
		);

		// Form Controls deliberately scopes itself to `.cb-core-form-scope` so
		// unrelated WordPress admin controls remain untouched. Designer Mode owns
		// that presentation boundary, so Base marks each consumer shell before the
		// launch runtime hydrates it; consumers never add this scope themselves.
		wp_add_inline_script(
			self::DESIGNER_MODE_SCRIPT,
			"document.querySelectorAll('[data-cb-design-shell]').forEach((shell) => shell.classList.add('cb-core-form-scope'));",
			'before'
		);

		wp_localize_script(
			self::DESIGNER_MODE_SCRIPT,
			'cbCoreDesignerLaunch',
			[
				'title'         => $title,
				'label'         => __( 'Design with Core Blueprint', 'core-blueprint' ),
				'ariaLabel'     => __( 'Open Designer Mode', 'core-blueprint' ),
				'iconUrl'       => CoreBlueprintMark::data_uri(),
				'closeLabel'    => __( 'Close', 'core-blueprint' ),
				'panelLabels'   => [
					'collapse' => __( 'Collapse', 'core-blueprint' ),
					'expand'   => __( 'Expand', 'core-blueprint' ),
				],
				'paletteLabels' => [
					'navigation'   => __( 'Navigation', 'core-blueprint' ),
					'elements'     => __( 'Elements', 'core-blueprint' ),
					'layers'       => __( 'Layers', 'core-blueprint' ),
					'dynamicData'  => __( 'Dynamic Data', 'core-blueprint' ),
					'actions'      => [
						'add'    => __( 'Add', 'core-blueprint' ),
						'edit'   => __( 'Edit', 'core-blueprint' ),
						'select' => __( 'Select', 'core-blueprint' ),
						'copy'   => __( 'Copy', 'core-blueprint' ),
					],
				],
				'layerActionLabels' => [
					'moveUp'   => __( 'Move element up', 'core-blueprint' ),
					'moveDown' => __( 'Move element down', 'core-blueprint' ),
					'remove'   => __( 'Remove element', 'core-blueprint' ),
				],
				'sidebarLabels' => [
					'inspector' => __( 'Inspector', 'core-blueprint' ),
					'layers'    => __( 'Layers', 'core-blueprint' ),
					'settings'  => __( 'Settings', 'core-blueprint' ),
				],
				'toolbarLabels' => [
					'view'    => __( 'View', 'core-blueprint' ),
					'tools'   => __( 'Tools', 'core-blueprint' ),
					'actions' => __( 'Actions', 'core-blueprint' ),
					'action'  => __( 'Action', 'core-blueprint' ),
				],
				'contextLabels' => [
					'loading' => __( 'Loading design…', 'core-blueprint' ),
					'ready'   => __( 'Design loaded', 'core-blueprint' ),
					'error'   => __( 'The selected design could not be loaded.', 'core-blueprint' ),
				],
				'exitGuardLabels' => [
					'saveFailure' => __( 'Your changes could not be verified as saved. The Designer remains open.', 'core-blueprint' ),
					'unavailable' => __( 'The Designer cannot be closed safely. Try again.', 'core-blueprint' ),
				],
				'toastLabels'  => [
					'saved' => __( 'Saved.', 'core-blueprint' ),
					'error' => __( 'Save failed.', 'core-blueprint' ),
				],
				'diagnosticLabels' => [
					'message' => __( 'The editor view needs refreshing. Your changes are preserved.', 'core-blueprint' ),
					'retry'   => __( 'Refresh editor view', 'core-blueprint' ),
				],
				'failureLabel' => __( 'Designer could not start. Reload the page and try again.', 'core-blueprint' ),
			]
		);
	}

	/**
	 * Return a stable content revision for a public Design Foundation asset.
	 *
	 * Base can evolve during one RC without forcing the plugin version to change
	 * for every internal build. Content fingerprints make the browser request the
	 * current registered asset after an update while retaining the plugin version
	 * as a safe fallback when the file cannot be read.
	 */
	private static function asset_version( string $relative_path ): string {
		$path = CB_CORE_DIR . $relative_path;
		if ( ! is_readable( $path ) ) {
			return CB_CORE_VERSION;
		}

		$hash = hash_file( 'sha256', $path );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return CB_CORE_VERSION;
		}

		return CB_CORE_VERSION . '-' . substr( $hash, 0, 12 );
	}

	private function __construct() {}
}