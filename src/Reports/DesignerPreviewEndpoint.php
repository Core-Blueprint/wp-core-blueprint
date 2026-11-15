<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Reports\Composer\NativeComposition;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only native Reports preview transport.
 *
 * Compiles an UNSAVED {project,fields} through the same Reports Flow compiler
 * used by PDF. It is deliberately independent of Designer Save Authority:
 * no Repository writes, no receipt and no schema migration happen here.
 *
 * @internal
 */
final class DesignerPreviewEndpoint {
	private const NAMESPACE = 'core-blueprint/v1';
	private const ROUTE = '/reports-designer/preview';
	private const PAGED_ROUTE = '/reports-designer/preview-paged';
	private const MAX_PDF_BYTES = 8 * 1024 * 1024;
	private const MAX_BODY_BYTES = 524288;

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods' => 'POST',
			'callback' => [ self::class, 'render' ],
			'permission_callback' => [ self::class, 'permission' ],
		] );
		register_rest_route( self::NAMESPACE, self::PAGED_ROUTE, [
			'methods' => 'POST',
			'callback' => [ self::class, 'render_paged' ],
			'permission_callback' => [ self::class, 'permission' ],
		] );
	}

	public static function permission( \WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return 'POST' === $request->get_method()
			&& is_string( $nonce ) && (bool) wp_verify_nonce( $nonce, 'wp_rest' )
			&& get_current_user_id() > 0 && is_user_logged_in()
			&& State::is_enabled() && current_user_can( 'cb_manage_branding' );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function render( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! self::permission( $request ) ) {
			return new \WP_Error( 'reports_preview_denied', 'REPORTS_PREVIEW_DENIED', [ 'status' => 403 ] );
		}
		if ( strlen( $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error( 'reports_preview_invalid', 'SCHEMA_DOMAIN_INVALID', [ 'status' => 422 ] );
		}
		$document = $request->get_json_params();
		if ( ! is_array( $document )
			|| count( $document ) !== 2
			|| ! array_key_exists( 'project', $document )
			|| ! array_key_exists( 'fields', $document )
			|| ! NativeComposition::valid_document( $document ) ) {
			return new \WP_Error( 'reports_preview_invalid', 'SCHEMA_DOMAIN_INVALID', [ 'status' => 422 ] );
		}

		try {
			$result = ( new DesignerPreview() )->render_with_source( $document );
			if ( ! in_array( $result['source'] ?? '', [ 'sample', 'archived' ], true )
				|| ! is_string( $result['html'] ?? null ) ) {
				throw new \RuntimeException( 'Invalid preview output.' );
			}
			$response = new \WP_REST_Response( [
				'status' => 'ready',
				'html' => $result['html'],
				'source' => $result['source'],
			], 200 );
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			return $response;
		} catch ( \Throwable $error ) {
			// Never leak archived snapshot values or detailed render errors.
			return new \WP_Error( 'reports_preview_unavailable', 'REPORTS_PREVIEW_UNAVAILABLE', [ 'status' => 503 ] );
		}
	}

	/**
	 * Opt-in canonical PDF.js snapshot transport. This endpoint only accepts
	 * the same validated native {project,fields} request as the HTML preview.
	 * The response is intentionally ephemeral and carries no document Save.
	 * PDF bytes are copied into a bounded base64 JSON response to avoid a
	 * second request/token/disk cache and keep one exact render identity.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function render_paged( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! self::permission( $request ) ) {
			return new \WP_Error( 'reports_preview_denied', 'REPORTS_PREVIEW_DENIED', [ 'status' => 403 ] );
		}
		if ( strlen( $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error( 'reports_preview_invalid', 'SCHEMA_DOMAIN_INVALID', [ 'status' => 422 ] );
		}
		$document = $request->get_json_params();
		if ( ! is_array( $document )
			|| count( $document ) !== 2
			|| ! array_key_exists( 'project', $document )
			|| ! array_key_exists( 'fields', $document )
			|| ! NativeComposition::valid_document( $document ) ) {
			return new \WP_Error( 'reports_preview_invalid', 'SCHEMA_DOMAIN_INVALID', [ 'status' => 422 ] );
		}

		try {
			$result = ( new DesignerPreview() )->render_paged_with_source( $document );
			$pdf = $result['pdf'] ?? null;
			$count = $result['page_count'] ?? null;
			if ( ! is_string( $pdf ) || strlen( $pdf ) > self::MAX_PDF_BYTES
				|| strlen( $pdf ) < 16 || ! str_starts_with( $pdf, '%PDF-' )
				|| ! is_int( $count ) || $count < 1 || $count > 500
				|| ! is_array( $result['regions'] ?? null )
				|| ! in_array( $result['source'] ?? '', [ 'sample', 'archived' ], true ) ) {
				throw new \RuntimeException( 'Invalid paged preview output.' );
			}
			$response = new \WP_REST_Response( [
				'status' => 'ready',
				'pdf_base64' => base64_encode( $pdf ),
				'projection' => [
					'page_count' => $count,
					'page_width_pt' => $result['page_width_pt'],
					'page_height_pt' => $result['page_height_pt'],
					'regions' => $result['regions'],
				],
				'source' => $result['source'],
			], 200 );
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			return $response;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'reports_preview_unavailable', 'REPORTS_PREVIEW_UNAVAILABLE', [ 'status' => 503 ] );
		}
	}

	private function __construct() {}
}
