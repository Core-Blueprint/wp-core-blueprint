<?php
declare(strict_types=1);
/**
 * Branding - AJAX handlers for the Preferences → Reports Designer.
 *
 * Endpoints remain stable for compatibility, but the save authority now owns
 * both Reports branding and the bounded Maintenance Composer template.
 *
 * Capability gate: cb_manage_branding (operator-owned).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Guards;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate;
use CoreBlueprint\Core\Reports\DesignerPreview;
use CoreBlueprint\Core\Reports\ReportBranding;
use CoreBlueprint\Core\Reports\ReportBrandingInput;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Branding {

	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_save_report_branding',    [ __CLASS__, 'save' ] );
		add_action( 'wp_ajax_cb_core_reset_report_branding',   [ __CLASS__, 'reset' ] );
		add_action( 'wp_ajax_cb_core_preview_report_branding', [ __CLASS__, 'preview' ] );
	}

	public static function save(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_branding();

		try {
			$branding = self::normalized_request();
			$template = self::normalized_template_request();
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( [ 'message' => $error->getMessage() ], 400 );
		}

		$settings = Settings::get();
		$reports  = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$composer = is_array( $reports['composer'] ?? null ) ? $reports['composer'] : [];

		$reports['branding']               = $branding;
		$composer['maintenance']           = $template;
		$reports['composer']               = $composer;

		Settings::set_key( 'reports', $reports, 'preferences.reports_designer' );

		AuditLog::log( 'reports.designer_updated', 'notice', [
			'logo_attachment_id'  => $branding['logo_attachment_id'],
			'show_logo'           => $branding['show_logo'],
			'has_provider_name'    => '' !== $branding['provider_name'],
			'has_provider_contact' => '' !== $branding['provider_contact'],
			'accent_color'         => $branding['accent_color'],
			'surface_style'        => $branding['surface_style'],
			'density'              => $branding['density'],
			'corner_style'         => $branding['corner_style'],
			'text_scale'           => $branding['text_scale'],
			'composer_version'     => $template['schema_version'],
			'enabled_blocks'       => count( array_filter( $template['blocks'], static fn ( array $block ): bool => ! empty( $block['enabled'] ) ) ),
			'by'                   => get_current_user_id(),
		] );

		$logo_url = $branding['logo_attachment_id'] > 0
			? ReportBranding::attachment_url( $branding['logo_attachment_id'], 'medium' )
			: '';

		wp_send_json_success( $branding + [
			'logo_url' => $logo_url,
			'template' => $template,
		] );
	}

	public static function reset(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_branding();

		$defaults = ReportBranding::settings_defaults();
		$template = MaintenanceTemplate::defaults();

		$settings = Settings::get();
		$reports  = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$composer = is_array( $reports['composer'] ?? null ) ? $reports['composer'] : [];

		$reports['branding']     = $defaults;
		$composer['maintenance'] = $template;
		$reports['composer']     = $composer;
		Settings::set_key( 'reports', $reports, 'preferences.reports_designer' );

		AuditLog::log( 'reports.designer_reset', 'notice', [
			'by' => get_current_user_id(),
		] );

		wp_send_json_success( $defaults + [
			'logo_attachment_id' => (int) ( $defaults['logo_attachment_id'] ?? 0 ),
			'logo_url'           => '',
			'template'           => $template,
		] );
	}

	/**
	 * Render proposed Reports Designer state without persisting it.
	 *
	 * The response HTML is produced by MaintenanceFlowCompiler + Flow HtmlRenderer,
	 * the same typed document path used by the PDF presenter.
	 */
	public static function preview(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_branding();

		try {
			$branding = self::normalized_request();
			$template = self::normalized_template_request();
			$html     = ( new DesignerPreview() )->render( $branding, $template );
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( [ 'message' => $error->getMessage() ], 400 );
		} catch ( \Throwable $error ) {
			wp_send_json_error( [
				'message' => __( 'An error occurred.', 'core-blueprint' ),
			], 500 );
		}

		wp_send_json_success( [ 'html' => $html ] );
	}

	/** @return array<string,mixed> */
	private static function normalized_request(): array {
		return ReportBrandingInput::normalize( [
			'logo_attachment_id' => Request::int( 'logo_attachment_id', 0 ),
			'show_logo'          => Request::text( 'show_logo', '1' ),
			'provider_name'      => Request::text( 'provider_name', '' ),
			'provider_contact'   => Request::text( 'provider_contact', '' ),
			'accent_color'       => Request::text( 'accent_color', '' ),
			'surface_style'      => Request::text( 'surface_style', 'cards' ),
			'density'            => Request::text( 'density', 'comfortable' ),
			'corner_style'       => Request::text( 'corner_style', 'soft' ),
			'text_scale'         => Request::text( 'text_scale', 'standard' ),
		] );
	}

	/**
	 * Older callers did not send Composer state. Preserve the currently stored
	 * template for those requests rather than interpreting omission as reset.
	 *
	 * @return array{schema_version:int,blocks:list<array{id:string,type:string,enabled:bool,settings:array<string,mixed>}>}
	 */
	private static function normalized_template_request(): array {
		if ( ! isset( $_POST['template'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by each public action before this helper.
			return MaintenanceTemplate::current();
		}

		$raw = wp_unslash( $_POST['template'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by each public action before this helper.
		if ( ! is_string( $raw ) ) {
			throw new \InvalidArgumentException( __( 'Invalid data.', 'core-blueprint' ) );
		}

		return MaintenanceTemplate::from_json( $raw );
	}

	private static function require_manage_branding(): void {
		if ( ! current_user_can( 'cb_manage_branding' ) ) {
			wp_send_json_error( [
				'message' => __( 'Only Core Blueprint operators may change report branding.', 'core-blueprint' ),
			], 403 );
		}
	}
}
