<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Kernel\DesignProject;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\Geometry;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\Validator;

final class CB_Design_Foundation_R3_Fixed_Contract_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function root( float $width = 210.0, float $height = 297.0 ): array {
		return [
			'type'       => 'document',
			'provider'   => 'acme.fixed',
			'properties' => [
				'layout' => [
					'mode'  => 'fixed',
					'units' => 'mm',
					'page'  => [ 'width' => $width, 'height' => $height ],
				],
			],
			'children'   => [
				[
					'type'       => 'text',
					'provider'   => 'acme.fixed',
					'properties' => [
						'frame' => [ 'x' => 10.0, 'y' => 20.0, 'width' => 80.0, 'height' => 15.0 ],
					],
					'children'   => [],
				],
			],
		];
	}

	private function project( array $root ): DesignProject {
		return new DesignProject( 0, 'acme.fixed.design', $root );
	}

	public function test_fixed_accepts_a4_and_shipping_label_sized_pages_without_hardcoding_a_paper_format(): void {
		$validator = new Validator();
		self::assertFalse( $validator->validate( $this->project( $this->root() ) )->has_errors() );
		self::assertFalse( $validator->validate( $this->project( $this->root( 100.0, 150.0 ) ) )->has_errors() );
	}

	public function test_fixed_requires_root_owned_layout_and_millimetres(): void {
		$root = $this->root();
		$root['properties']['layout']['units'] = 'px';
		$diagnostics = ( new Validator() )->validate( $this->project( $root ) )->to_array();
		self::assertContains( 'fixed.layout_units', array_column( $diagnostics, 'code' ) );

		$root = $this->root();
		unset( $root['properties']['layout'] );
		$diagnostics = ( new Validator() )->validate( $this->project( $root ) )->to_array();
		self::assertContains( 'fixed.layout_required', array_column( $diagnostics, 'code' ) );
	}

	public function test_fixed_frames_are_server_validated_against_root_page_bounds(): void {
		$root = $this->root( 100.0, 150.0 );
		$root['children'][0]['properties']['frame'] = [ 'x' => 95.0, 'y' => 10.0, 'width' => 10.0, 'height' => 10.0 ];
		$diagnostics = ( new Validator() )->validate( $this->project( $root ) )->to_array();
		self::assertContains( 'fixed.frame_outside_page', array_column( $diagnostics, 'code' ) );
	}

	public function test_fixed_rejects_nested_layout_context_and_missing_frames(): void {
		$root = $this->root();
		$root['children'][0]['properties']['layout'] = [
			'mode' => 'fixed',
			'units' => 'mm',
			'page' => [ 'width' => 50.0, 'height' => 50.0 ],
		];
		unset( $root['children'][0]['properties']['frame'] );
		$diagnostics = ( new Validator() )->validate( $this->project( $root ) )->to_array();
		$codes = array_column( $diagnostics, 'code' );
		self::assertContains( 'fixed.nested_layout_forbidden', $codes );
		self::assertContains( 'fixed.frame_required', $codes );
	}

	public function test_fixed_reserved_geometry_objects_fail_closed_on_unknown_keys(): void {
		$root = $this->root();
		$root['properties']['layout']['page']['format'] = 'A4';
		$root['children'][0]['properties']['frame']['rotation'] = 15;
		$codes = array_column( ( new Validator() )->validate( $this->project( $root ) )->to_array(), 'code' );
		self::assertContains( 'fixed.page_unknown_key', $codes );
		self::assertContains( 'fixed.frame_unknown_key', $codes );
	}

	public function test_geometry_rejects_numeric_strings_and_non_finite_values(): void {
		self::assertNull( Geometry::number( '10' ) );
		self::assertNull( Geometry::number( INF ) );
		self::assertNull( Geometry::number( NAN ) );
	}

	public function test_fixed_profile_remains_free_of_flow_dompdf_and_internal_pdf_renderer_dependencies(): void {
		$directory = dirname( __DIR__, 2 ) . '/src/Design/Profile/Document/Fixed';
		foreach ( glob( $directory . '/*.php' ) ?: [] as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertDoesNotMatchRegularExpression( '/\b(?:Dompdf|pagination|Flow)\b/i', $source, basename( $file ) );
			self::assertStringNotContainsString( 'CoreBlueprint\\Core\\PDF\\Renderer', $source, basename( $file ) );
		}
	}

	public function test_fixed_geometry_and_validation_core_remain_pdf_api_free(): void {
		$directory = dirname( __DIR__, 2 ) . '/src/Design/Profile/Document/Fixed';
		foreach ( [ 'Contract.php', 'Geometry.php', 'Validator.php' ] as $filename ) {
			$source = (string) file_get_contents( $directory . '/' . $filename );
			self::assertStringNotContainsString( 'PdfApi', $source, $filename );
			self::assertStringNotContainsString( 'CoreBlueprint\\Core\\PDF', $source, $filename );
		}
	}

	public function test_fixed_pdf_adapter_uses_only_the_public_base_pdf_boundary(): void {
		$path = dirname( __DIR__, 2 ) . '/src/Design/Profile/Document/Fixed/PdfRenderer.php';
		$source = (string) file_get_contents( $path );
		self::assertStringContainsString( 'use CoreBlueprint\\Core\\PDF\\Api\\PdfApi;', $source );
		self::assertStringContainsString( 'PdfApi::render(', $source );
		self::assertStringNotContainsString( 'Dompdf', $source );
		self::assertStringNotContainsString( 'CoreBlueprint\\Core\\PDF\\Renderer', $source );
	}
}
