<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter;
use CoreBlueprint\Core\Design\Profile\Document\Flow\Api\FlowRenderApi;
use CoreBlueprint\Core\Reports\DesignerPreview;
use CoreBlueprint\Core\Reports\DesignerPreviewEndpoint;

final class CB_Reports_Paged_Preview_Transport_Test extends WP_UnitTestCase {
	public function test_unsaved_reports_compose_one_actual_pdf_with_selectable_page_regions(): void {
		if ( ! FlowRenderApi::is_pdf_available() ) {
			self::markTestSkipped( 'Bundled Dompdf unavailable.' );
		}
		$native = [
			'project' => PlatformAdapter::default_project(),
			'fields' => PlatformAdapter::default_fields(),
		];
		$result = ( new DesignerPreview() )->render_paged_with_source( $native );
		self::assertStringStartsWith( '%PDF-', $result['pdf'] );
		self::assertContains( $result['source'], [ 'sample', 'archived' ] );
		self::assertGreaterThanOrEqual( 1, $result['page_count'] );
		self::assertLessThanOrEqual( 500, $result['page_count'] );
		self::assertEqualsWithDelta( 595.28, $result['page_width_pt'], 2 );
		self::assertEqualsWithDelta( 841.89, $result['page_height_pt'], 2 );
		self::assertArrayHasKey( 'r0', $result['regions'] );
		self::assertNotEmpty( $result['regions']['r0'] );
		foreach ( $result['regions'] as $region_id => $rectangles ) {
			self::assertMatchesRegularExpression( '/^r[0-9]+(?:\.[0-9]+){0,7}$/', $region_id );
			foreach ( $rectangles as $rectangle ) {
				self::assertGreaterThanOrEqual( 1, $rectangle['page'] );
				self::assertLessThanOrEqual( $result['page_count'], $rectangle['page'] );
				self::assertGreaterThan( 0, $rectangle['width'] );
				self::assertGreaterThan( 0, $rectangle['height'] );
			}
		}
	}

	public function test_paged_preview_rejects_unauthorized_post_without_rendering(): void {
		$request = new WP_REST_Request( 'POST', '/core-blueprint/v1/reports-designer/preview-paged' );
		$request->set_body( wp_json_encode( [
			'project' => PlatformAdapter::default_project(),
			'fields' => PlatformAdapter::default_fields(),
		] ) );
		$request->set_header( 'Content-Type', 'application/json' );
		self::assertFalse( DesignerPreviewEndpoint::permission( $request ) );
		$result = DesignerPreviewEndpoint::render_paged( $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_paged_preview_transport_is_separately_registered_and_no_store(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Reports/DesignerPreviewEndpoint.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( "PAGED_ROUTE = '/reports-designer/preview-paged'", $source );
		self::assertStringContainsString( "'permission_callback' => [ self::class, 'permission' ]", $source );
		self::assertStringContainsString( "'Cache-Control', 'private, no-store, max-age=0'", $source );
		self::assertStringContainsString( 'NativeComposition::valid_document( $document )', $source );
		self::assertStringContainsString( 'MAX_PDF_BYTES', $source );
	}
}
