<?php
declare(strict_types=1);

use CB\Core\Design\Profile\Document\Flow\Api\FlowRenderApi;
use CB\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CB\Core\Design\Profile\Document\Flow\RenderBlock;

final class CB_Design_Foundation_Flow_Preview_Host_Test extends WP_UnitTestCase {
	/** @return array<string,mixed> */
	private function layout(): array {
		return [
			'mode'    => 'flow',
			'units'   => 'mm',
			'page'    => [ 'width' => 210.0, 'height' => 297.0 ],
			'margins' => [ 'top' => 12.0, 'right' => 12.0, 'bottom' => 15.0, 'left' => 12.0 ],
		];
	}

	private function bridge( string $html ): string {
		$matches = [];
		self::assertSame( 1, preg_match( '/<script data-cb-core-flow-preview-sizing="1">(.*?)<\/script>/s', $html, $matches ) );
		self::assertArrayHasKey( 1, $matches );
		return (string) $matches[1];
	}

	public function test_preview_html_exposes_one_canonical_protocol_marker_and_static_bridge(): void {
		$first = FlowRenderApi::preview_html( $this->layout(), [ RenderBlock::text( 'First render' ) ], 'en_GB' );
		$second = FlowRenderApi::preview_html( $this->layout(), [ RenderBlock::text( 'Second render' ) ], 'en_GB' );

		self::assertSame( 1, substr_count( $first, '<meta name="cb-core-flow-preview-protocol" content="1">' ) );
		self::assertSame( 1, substr_count( $first, 'data-cb-core-flow-preview-protocol="1"' ) );
		self::assertSame( 1, substr_count( $first, 'data-cb-core-flow-preview-generation="0"' ) );
		self::assertSame( 1, substr_count( $first, 'data-cb-flow-preview-root="1"' ) );
		self::assertSame( 1, substr_count( $first, 'data-cb-core-flow-preview-sizing="1"' ) );

		$first_bridge = $this->bridge( $first );
		$second_bridge = $this->bridge( $second );
		self::assertSame( $first_bridge, $second_bridge, 'The hashed sizing bridge must remain byte-for-byte static across renders.' );
		self::assertStringNotContainsString( 'First render', $first_bridge );
		self::assertStringNotContainsString( 'Second render', $second_bridge );
		self::assertStringNotContainsString( 'data-cb-core-flow-preview-generation="1"', $first );
		self::assertStringContainsString( "window.addEventListener('message'", $first_bridge );
		self::assertStringContainsString( 'event.source!==window.parent', $first_bridge );
		self::assertStringContainsString( "data.type==='cb-core-flow-preview-measure'", $first_bridge );
		self::assertStringContainsString( 'data.version===1', $first_bridge );
		self::assertStringContainsString( 'event.data.generation!==generation', $first_bridge );

		$hash = base64_encode( hash( 'sha256', $first_bridge, true ) );
		self::assertStringContainsString( "script-src &#39;sha256-{$hash}&#39;", $first );
		self::assertStringContainsString( "script-src &#39;sha256-{$hash}&#39;", $second );
		self::assertStringNotContainsString( 'allow-same-origin', $first );
	}

	public function test_paged_html_renderer_does_not_gain_preview_protocol_or_bridge(): void {
		$paged = ( new HtmlRenderer() )->render( $this->layout(), [ RenderBlock::text( 'Paged' ) ], 'en_GB' );

		self::assertStringNotContainsString( 'cb-core-flow-preview-protocol', $paged );
		self::assertStringNotContainsString( 'cb-core-flow-preview-sizing', $paged );
		self::assertStringNotContainsString( 'cb-flow-preview-root', $paged );
	}

	public function test_designer_hidden_semantics_override_component_display_modes(): void {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/design/designer-composition.css' );
		self::assertIsString( $css );
		self::assertMatchesRegularExpression(
			'/\.cb-core-design-shell\s+\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/s',
			$css
		);
		self::assertStringContainsString( '.cb-core-design-shell__panel-body {', $css );
		self::assertStringContainsString( 'display: grid;', $css );
		self::assertStringContainsString( '.cb-core-design-shell__canvas--composed {', $css );
		self::assertStringContainsString( 'display: flex;', $css );
		self::assertStringContainsString( '.cb-core-design-shell__layer-label,', $css );
		self::assertStringContainsString( 'display: block;', $css );
	}

	public function test_public_flow_facade_exports_host_without_parent_iframe_dom_reads(): void {
		$root = dirname( __DIR__, 2 );
		$index = file_get_contents( $root . '/assets/js/design/document/flow/index.js' );
		$host = file_get_contents( $root . '/assets/js/design/document/flow/preview-host.js' );
		self::assertIsString( $index );
		self::assertIsString( $host );

		self::assertStringContainsString( "export { createFlowPreviewHost } from './preview-host.js';", $index );
		self::assertStringContainsString( "iframe.setAttribute('sandbox', 'allow-scripts');", $host );
		self::assertStringContainsString( "const MEASURE_MESSAGE_TYPE = 'cb-core-flow-preview-measure';", $host );
		self::assertStringContainsString( "iframe.addEventListener('load', onLoad, { once: true });", $host );
		self::assertStringContainsString( 'clearPendingLoadHandler();', $host );
		self::assertStringNotContainsString( 'allow-same-origin', $host );
		self::assertStringNotContainsString( 'contentDocument', $host );
		self::assertStringNotContainsString( 'contentWindow.document', $host );
		self::assertStringNotContainsString( 'scrollHeight', $host );
	}
}
