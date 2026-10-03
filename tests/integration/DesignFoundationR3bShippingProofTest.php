<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Kernel\AuthorizationRegistry;
use CoreBlueprint\Core\Design\Kernel\CapabilityClass;
use CoreBlueprint\Core\Design\Kernel\CapabilityRegistry;
use CoreBlueprint\Core\Design\Kernel\DesignProject;
use CoreBlueprint\Core\Design\Kernel\DesignTypeRegistry;
use CoreBlueprint\Core\Design\Kernel\ProviderRegistry;
use CoreBlueprint\Core\Design\Kernel\SchemaValidator;
use CoreBlueprint\Core\Design\Kernel\Serializer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\HtmlRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\PdfRenderer as FixedPdfRenderer;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\RenderFragment;
use CoreBlueprint\Core\Design\Profile\Document\Fixed\Validator as FixedValidator;
use CoreBlueprint\Core\PDF\Api\PdfApi;
use CoreBlueprint\Core\PDF\RendererException;

final class CB_Design_Foundation_R3b_Shipping_Proof_Test extends WP_UnitTestCase {
	private const PROVIDER = 'fixture.shipping';
	private const DESIGN_TYPE = 'fixture.shipping.label';
	private const BINDING_CAPABILITY = 'binding.resolve';
	private const ASSET_CAPABILITY = 'asset.resolve';

	/** @return array<string,mixed> */
	private function fixture(): array {
		$path = dirname( __DIR__ ) . '/fixtures/design-foundation/r3b/shipping-label.json';
		$decoded = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $decoded );
		return $decoded;
	}

	/** @return array{0:AuthorizationRegistry,1:Serializer} */
	private function kernel(): array {
		$providers = new ProviderRegistry();
		self::assertTrue( $providers->register( self::PROVIDER ) );

		$design_types = new DesignTypeRegistry( $providers );
		self::assertTrue( $design_types->register( self::DESIGN_TYPE, self::PROVIDER ) );

		$capabilities = new CapabilityRegistry( $providers );
		self::assertTrue( $capabilities->register( self::PROVIDER, self::BINDING_CAPABILITY, CapabilityClass::Data ) );
		self::assertTrue( $capabilities->register( self::PROVIDER, self::ASSET_CAPABILITY, CapabilityClass::Data ) );

		$authorization = new AuthorizationRegistry( $providers, $design_types, $capabilities );
		$serializer = new Serializer( new SchemaValidator( $providers, $design_types ) );
		return [ $authorization, $serializer ];
	}

	public function test_registration_does_not_authorize_shipping_external_data(): void {
		[ $authorization, $serializer ] = $this->kernel();
		self::assertFalse( $authorization->is_authorized( self::DESIGN_TYPE, self::PROVIDER, self::BINDING_CAPABILITY ) );
		self::assertFalse( $authorization->is_authorized( self::DESIGN_TYPE, self::PROVIDER, self::ASSET_CAPABILITY ) );

		$fixture = $this->fixture();
		$project = $this->decode_project( $fixture, $serializer );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not authorized' );
		$this->compile_fragments( $project, $fixture, $authorization );
	}

	public function test_authorized_shipping_fixture_compiles_to_typed_fragments_and_deterministic_html(): void {
		$fixture = $this->fixture();
		[ $authorization, $serializer ] = $this->kernel();
		self::assertTrue( $authorization->grant_consumer( self::DESIGN_TYPE, self::PROVIDER, self::BINDING_CAPABILITY ) );
		self::assertTrue( $authorization->grant_consumer( self::DESIGN_TYPE, self::PROVIDER, self::ASSET_CAPABILITY ) );

		$project = $this->decode_project( $fixture, $serializer );
		$fixed_diagnostics = ( new FixedValidator() )->validate( $project );
		self::assertFalse( $fixed_diagnostics->has_errors(), wp_json_encode( $fixed_diagnostics->to_array() ) );

		$design_json = wp_json_encode( $fixture['design'] );
		self::assertIsString( $design_json );
		self::assertStringNotContainsString( '://', $design_json );
		self::assertStringNotContainsString( 'data:', $design_json );
		self::assertStringNotContainsString( '../', $design_json );

		$fragments = $this->compile_fragments( $project, $fixture, $authorization );
		self::assertSame( $fixture['expected']['fragment_types'], array_map( static fn ( RenderFragment $fragment ): string => $fragment->type(), $fragments ) );

		$page = $project->root()['properties']['layout']['page'];
		self::assertSame( array_map( 'floatval', $fixture['expected']['page_mm'] ), [ (float) $page['width'], (float) $page['height'] ] );

		$html = ( new HtmlRenderer() )->render( $page, $fragments, (string) $fixture['locale'] );
		self::assertSame( $fixture['expected']['html_sha256'], hash( 'sha256', $html ) );
		self::assertStringContainsString( '<html lang="en-GB">', $html );
		self::assertStringContainsString( 'Ada Example', $html );
		self::assertStringContainsString( 'SHP-000042', $html );
	}

	public function test_shipping_proof_renders_actual_custom_size_pdf_through_public_base_api(): void {
		self::assertTrue( PdfApi::is_available(), 'Bundled Base PDF renderer must be available for the R3b proof.' );
		$fixture = $this->fixture();
		[ $authorization, $serializer ] = $this->kernel();
		self::assertTrue( $authorization->grant_consumer( self::DESIGN_TYPE, self::PROVIDER, self::BINDING_CAPABILITY ) );
		self::assertTrue( $authorization->grant_consumer( self::DESIGN_TYPE, self::PROVIDER, self::ASSET_CAPABILITY ) );
		$project = $this->decode_project( $fixture, $serializer );
		$fragments = $this->compile_fragments( $project, $fixture, $authorization );
		$page = $project->root()['properties']['layout']['page'];

		$pdf = ( new FixedPdfRenderer() )->render( $page, $fragments, (string) $fixture['locale'] );
		self::assertStringStartsWith( '%PDF-', $pdf );

		[ $width, $height ] = $this->media_box_dimensions( $pdf );
		$expected = $fixture['expected']['pdf_media_box_points'];
		self::assertEqualsWithDelta( (float) $expected[0], $width, 0.05 );
		self::assertEqualsWithDelta( (float) $expected[1], $height, 0.05 );
	}

	public function test_pdf_api_keeps_named_paper_compatibility_and_rejects_invalid_custom_mm(): void {
		self::assertTrue( PdfApi::is_available() );
		$legacy = PdfApi::render(
			'<!doctype html><html><body>Legacy named paper</body></html>',
			[ 'paper_size' => 'A4', 'orientation' => 'landscape' ]
		);
		self::assertStringStartsWith( '%PDF-', $legacy );

		$this->expectException( RendererException::class );
		PdfApi::render(
			'<!doctype html><html><body>Invalid custom paper</body></html>',
			[ 'paper_size_mm' => [ '100', 150 ] ]
		);
	}

	/** @param array<string,mixed> $fixture */
	private function decode_project( array $fixture, Serializer $serializer ): DesignProject {
		$json = wp_json_encode( $fixture['design'], JSON_UNESCAPED_SLASHES );
		self::assertIsString( $json );
		return $serializer->decode( $json, true );
	}

	/**
	 * @param array<string,mixed> $fixture
	 * @return list<RenderFragment>
	 */
	private function compile_fragments( DesignProject $project, array $fixture, AuthorizationRegistry $authorization ): array {
		$root = $project->root();
		$children = is_array( $root['children'] ?? null ) ? $root['children'] : [];
		$fragments = [];
		foreach ( $children as $node ) {
			if ( ! is_array( $node ) ) {
				throw new RuntimeException( 'Shipping proof node is invalid.' );
			}
			$properties = is_array( $node['properties'] ?? null ) ? $node['properties'] : [];
			$frame = is_array( $properties['frame'] ?? null ) ? $properties['frame'] : [];
			$type = (string) ( $node['type'] ?? '' );

			if ( 'text' === $type ) {
				$this->assert_exact_keys( $properties, [ 'frame', 'value' ], 'Shipping text properties' );
				$value = is_array( $properties['value'] ?? null ) ? $properties['value'] : [];
				$source = (string) ( $value['source'] ?? '' );
				if ( 'literal' === $source ) {
					$this->assert_exact_keys( $value, [ 'source', 'text' ], 'Shipping literal value' );
					if ( ! is_string( $value['text'] ?? null ) ) {
						throw new RuntimeException( 'Shipping literal text is invalid.' );
					}
					$fragments[] = RenderFragment::text( $frame, $value['text'] );
					continue;
				}
				if ( 'binding' !== $source ) {
					throw new RuntimeException( 'Shipping value source is unsupported.' );
				}
				$this->assert_exact_keys( $value, [ 'source', 'provider', 'binding' ], 'Shipping binding value' );
				$provider = (string) ( $value['provider'] ?? '' );
				if ( ! $authorization->is_authorized( $project->design_type(), $provider, self::BINDING_CAPABILITY ) ) {
					throw new RuntimeException( 'Shipping binding provider is not authorized.' );
				}
				$binding = (string) ( $value['binding'] ?? '' );
				$sample_bindings = is_array( $fixture['sample_bindings'] ?? null ) ? $fixture['sample_bindings'] : [];
				if ( ! array_key_exists( $binding, $sample_bindings ) || ! is_string( $sample_bindings[ $binding ] ) ) {
					throw new RuntimeException( 'Shipping sample binding is unresolved.' );
				}
				$fragments[] = RenderFragment::text( $frame, $sample_bindings[ $binding ] );
				continue;
			}

			if ( 'image' === $type ) {
				$this->assert_exact_keys( $properties, [ 'frame', 'asset' ], 'Shipping image properties' );
				$asset_ref = is_array( $properties['asset'] ?? null ) ? $properties['asset'] : [];
				$this->assert_exact_keys( $asset_ref, [ 'provider', 'asset_id' ], 'Shipping asset reference' );
				$provider = (string) ( $asset_ref['provider'] ?? '' );
				if ( ! $authorization->is_authorized( $project->design_type(), $provider, self::ASSET_CAPABILITY ) ) {
					throw new RuntimeException( 'Shipping asset provider is not authorized.' );
				}
				$asset_id = (string) ( $asset_ref['asset_id'] ?? '' );
				$catalog = is_array( $fixture['asset_catalog'] ?? null ) ? $fixture['asset_catalog'] : [];
				$asset = is_array( $catalog[ $asset_id ] ?? null ) ? $catalog[ $asset_id ] : [];
				$this->assert_exact_keys( $asset, [ 'mime', 'base64' ], 'Shipping test asset' );
				$mime = (string) ( $asset['mime'] ?? '' );
				if ( ! in_array( $mime, [ 'image/png', 'image/jpeg' ], true ) || ! is_string( $asset['base64'] ?? null ) ) {
					throw new RuntimeException( 'Shipping test asset is invalid.' );
				}
				$fragments[] = RenderFragment::image( $frame, 'data:' . $mime . ';base64,' . $asset['base64'] );
				continue;
			}

			throw new RuntimeException( 'Shipping proof node type is unsupported.' );
		}
		return $fragments;
	}

	/** @param array<string,mixed> $value @param list<string> $expected */
	private function assert_exact_keys( array $value, array $expected, string $label ): void {
		$keys = array_keys( $value );
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new RuntimeException( $label . ' contains unknown or missing keys.' );
		}
	}

	/** @return array{0:float,1:float} */
	private function media_box_dimensions( string $pdf ): array {
		$matched = preg_match(
			'/\/MediaBox\s*\[\s*(-?[0-9.]+)\s+(-?[0-9.]+)\s+(-?[0-9.]+)\s+(-?[0-9.]+)\s*\]/',
			$pdf,
			$matches
		);
		if ( 1 !== $matched ) {
			self::fail( 'Rendered shipping proof PDF has no readable MediaBox.' );
		}
		return [ (float) $matches[3] - (float) $matches[1], (float) $matches[4] - (float) $matches[2] ];
	}
}
