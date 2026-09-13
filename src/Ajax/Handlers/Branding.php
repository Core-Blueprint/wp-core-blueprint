<?php
declare(strict_types=1);
/**
 * Branding - AJAX handlers for the Preferences → Reports Designer.
 *
 * Endpoints:
 *   - cb_core_save_report_branding
 *   - cb_core_reset_report_branding
 *   - cb_core_preview_report_branding
 *
 * Capability gate: cb_manage_branding (operator-owned).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Ajax\Handlers;

use CB\Core\Ajax\Guards;
use CB\Core\Ajax\Request;
use CB\Core\Log\AuditLog;
use CB\Core\Reports\DesignerPreview;
use CB\Core\Reports\ReportBranding;
use CB\Core\Reports\ReportBrandingInput;
use CB\Core\Settings;

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
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( [ 'message' => $error->getMessage() ], 400 );
		}

		$settings            = Settings::get();
		$reports             = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$reports['branding'] = $branding;

		Settings::set_key( 'reports', $reports, 'preferences.report_branding' );

		AuditLog::log( 'reports.branding_updated', 'notice', [
			'logo_attachment_id'  => $branding['logo_attachment_id'],
			'has_provider_name'    => '' !== $branding['provider_name'],
			'has_provider_contact' => '' !== $branding['provider_contact'],
			'accent_color'         => $branding['accent_color'],
			'by'                   => get_current_user_id(),
		] );

		$logo_url = $branding['logo_attachment_id'] > 0
			? ReportBranding::attachment_url( $branding['logo_attachment_id'], 'medium' )
			: '';

		wp_send_json_success( $branding + [ 'logo_url' => $logo_url ] );
	}

	public static function reset(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_branding();

		$defaults = ReportBranding::settings_defaults();

		$settings            = Settings::get();
		$reports             = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$reports['branding'] = $defaults;
		Settings::set_key( 'reports', $reports, 'preferences.report_branding' );

		AuditLog::log( 'reports.branding_reset', 'notice', [
			'by' => get_current_user_id(),
		] );

		wp_send_json_success( [
			'logo_attachment_id' => (int) ( $defaults['logo_attachment_id'] ?? 0 ),
			'logo_url'           => '',
			'provider_name'       => '',
			'provider_contact'    => '',
			'accent_color'        => (string) ( $defaults['accent_color'] ?? ReportBranding::DEFAULT_ACCENT ),
		] );
	}

	/**
	 * Render proposed branding without persisting it.
	 *
	 * The response HTML is produced by MaintenanceFlowCompiler + Flow HtmlRenderer,
	 * the same typed document path used by the PDF presenter.
	 */
	public static function preview(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_branding();

		try {
			$branding = self::normalized_request();
			$html     = ( new DesignerPreview() )->render( $branding );
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( [ 'message' => $error->getMessage() ], 400 );
		} catch ( \Throwable $error ) {
			wp_send_json_error( [
				'message' => __( 'The report preview could not be rendered.', 'core-blueprint' ),
			], 500 );
		}

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * @return array{logo_attachment_id:int,provider_name:string,provider_contact:string,accent_color:string}
	 */
	private static function normalized_request(): array {
		return ReportBrandingInput::normalize( [
			'logo_attachment_id' => Request::int( 'logo_attachment_id', 0 ),
			'provider_name'       => Request::text( 'provider_name', '' ),
			'provider_contact'    => Request::text( 'provider_contact', '' ),
			'accent_color'        => Request::text( 'accent_color', '' ),
		] );
	}

	private static function require_manage_branding(): void {
		if ( ! current_user_can( 'cb_manage_branding' ) ) {
			wp_send_json_error( [
				'message' => __( 'Only Core Blueprint operators may change report branding.', 'core-blueprint' ),
			], 403 );
		}
	}
}
