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
			wp_send_json_error( [ 'message' => __( 'The Reports design is invalid.', 'core-blueprint' ) ], 422 );
		} catch ( \Throwable $error ) {
			wp_send_json_error( [ 'message' => __( 'Reports storage is unavailable.', 'core-blueprint' ) ], 503 );
		}
		$expected = self::expected_revision();
		$authority = \CoreBlueprint\Core\Design\Document\Authority::inspect();
		if ( 'active' === $authority['status'] ) {
			$result = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::save( $expected, $branding, $template );
			self::respond_canonical( $result );
		}
		if ( ! in_array( $authority['status'], [ 'legacy', 'prepared' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Reports storage is blocked.', 'core-blueprint' ) ], 503 );
		}
		if ( 'legacy' !== $expected ) {
			wp_send_json_error( [ 'message' => __( 'Reload the Reports Designer before saving.', 'core-blueprint' ) ], 409 );
		}

		$settings = Settings::get();
		$reports = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$composer = is_array( $reports['composer'] ?? null ) ? $reports['composer'] : [];
		$reports['branding'] = $branding;
		$composer['maintenance'] = $template;
		$reports['composer'] = $composer;
		$updated = Settings::set_paths( [
			'reports.branding' => $branding,
			'reports.composer.maintenance' => $template,
		], 'preferences.reports_designer' );
		self::verify_legacy_write( $updated, $branding, $template, __( 'Reports save could not be verified.', 'core-blueprint' ) );
		if ( $updated ) {
			AuditLog::log( 'reports.designer_updated', 'notice', [ 'by' => get_current_user_id() ] );
		}
		self::respond_design( $branding, $template, 'legacy' );
	}

	public static function reset(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_branding();
		$expected = self::expected_revision();
		$authority = \CoreBlueprint\Core\Design\Document\Authority::inspect();
		if ( 'active' === $authority['status'] ) {
			$result = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::reset( $expected );
			self::respond_canonical( $result );
		}
		if ( ! in_array( $authority['status'], [ 'legacy', 'prepared' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Reports storage is blocked.', 'core-blueprint' ) ], 503 );
		}
		if ( 'legacy' !== $expected ) {
			wp_send_json_error( [ 'message' => __( 'Reload the Reports Designer before resetting.', 'core-blueprint' ) ], 409 );
		}
		$defaults = ReportBranding::settings_defaults();
		$template = MaintenanceTemplate::defaults();
		$settings = Settings::get();
		$reports = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		$composer = is_array( $reports['composer'] ?? null ) ? $reports['composer'] : [];
		$reports['branding'] = $defaults;
		$composer['maintenance'] = $template;
		$reports['composer'] = $composer;
		$updated = Settings::set_paths( [
			'reports.branding' => $defaults,
			'reports.composer.maintenance' => $template,
		], 'preferences.reports_designer' );
		self::verify_legacy_write( $updated, $defaults, $template, __( 'Reports reset could not be verified.', 'core-blueprint' ) );
		if ( $updated ) {
			AuditLog::log( 'reports.designer_reset', 'notice', [ 'by' => get_current_user_id() ] );
		}
		self::respond_design( $defaults, $template, 'legacy' );
	}

	/** Verify the actual stored legacy fields, never a stale request cache. */
	private static function verify_legacy_write( bool $changed, array $branding, array $template, string $failure_message ): void {
		$status = Settings::last_write_status();
		if ( ! $changed && 'unchanged' !== $status ) {
			wp_send_json_error( [ 'message' => $failure_message, 'code' => 'reports_settings_conflict' ], 'conflict' === $status || 'refused' === $status ? 409 : 503 );
		}
		$source = \CoreBlueprint\Core\Settings\OptionCas::read();
		$reports = 'ok' === ( $source['status'] ?? '' ) ? (array) ( $source['value']['reports'] ?? [] ) : [];
		if ( 'ok' !== ( $source['status'] ?? '' )
			|| ( $reports['branding'] ?? ReportBranding::settings_defaults() ) !== $branding
			|| ( $reports['composer']['maintenance'] ?? MaintenanceTemplate::defaults() ) !== $template ) {
			wp_send_json_error( [ 'message' => $failure_message, 'code' => 'reports_settings_unverified' ], 503 );
		}
	}

	private static function expected_revision(): string {
		return isset( $_POST['expected_revision'] ) && is_string( $_POST['expected_revision'] )
			? (string) wp_unslash( $_POST['expected_revision'] ) : '';
	}

	private static function respond_canonical( array $result ): never {
		if ( 'saved' !== ( $result['status'] ?? '' ) ) {
			$reason = (string) ( $result['status'] ?? 'error' );
			wp_send_json_error(
				[ 'message' => __( 'Reports design could not be saved. Reload if it has changed.', 'core-blueprint' ) ],
				'conflict' === $reason ? 409 : ( 'invalid' === $reason ? 422 : 503 )
			);
		}
		$document = $result['document'] ?? null;
		if ( ! is_array( $document ) || ! is_string( $result['revision'] ?? null )
			|| ! \CoreBlueprint\Core\Reports\DesignDocumentAdapter::valid_document( $document ) ) {
			wp_send_json_error( [ 'message' => __( 'Reports save readback failed.', 'core-blueprint' ) ], 503 );
		}
		self::respond_design( $document['branding'], $document['composer']['maintenance'], $result['revision'] );
	}

	private static function respond_design( array $branding, array $template, string $revision ): never {
		$logo_id = (int) ( $branding['logo_attachment_id'] ?? 0 );
		$logo_url = $logo_id > 0 ? ReportBranding::attachment_url( $logo_id, 'medium' ) : '';
		wp_send_json_success( $branding + [
			'logo_url' => $logo_url,
			'template' => $template,
			'revision' => $revision,
			'document' => [ 'branding' => $branding, 'composer' => [ 'maintenance' => $template ] ],
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
			$live = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::current();
			$branding = self::normalized_request();
			$template = self::normalized_template_request();
			$html = ( new DesignerPreview() )->render( $branding, $template );
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( [ 'message' => $error->getMessage() ], 400 );
		} catch ( \Throwable $error ) {
			wp_send_json_error( [
				'message' => __( 'An error occurred.', 'core-blueprint' ),
			], 500 );
		}

		wp_send_json_success( [ 'html' => $html, 'revision' => $live['revision'], 'authority' => $live['authority'] ] );
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
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Invalid data.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
